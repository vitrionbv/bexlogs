#!/usr/bin/env bash
# BexLogs — one-shot Hetzner / Ubuntu 24.04 production bootstrap.
#
# Usage on a fresh box (run as root):
#
#   curl -sSL https://raw.githubusercontent.com/<owner>/bexlogs/main/deploy/bootstrap.sh \
#     | sudo bash -s -- \
#         --domain bexlogs.example.com \
#         --acme-email admin@example.com \
#         --repo https://github.com/<owner>/bexlogs.git \
#         --github-runner-token "$RUNNER_TOKEN" \
#         --github-runner-url https://github.com/<owner>/bexlogs
#
# Idempotent — safe to re-run. Won't overwrite an existing .env, won't
# regenerate APP_KEY, won't re-register an existing runner, etc.
#
# Required:    --domain <fqdn>
# Optional:    --repo, --branch, --acme-email, --admin-email, --app-key,
#              --github-runner-token, --github-runner-url, --no-deploy

set -euo pipefail

# ─── Defaults ──────────────────────────────────────────────────────────────
DOMAIN=""
REPO="https://github.com/CHANGE_ME/bexlogs.git"
BRANCH="main"
ACME_EMAIL=""
ADMIN_EMAIL=""
APP_KEY=""
RUNNER_TOKEN=""
RUNNER_URL=""
DO_DEPLOY=1
APP_DIR="/opt/bexlogs"
APP_USER="bexlogs"
RUNNER_DIR="/opt/bexlogs-runner"

# ─── Logging helpers ───────────────────────────────────────────────────────
log()   { printf '\033[1;36m▶ %s\033[0m\n' "$*"; }
ok()    { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn()  { printf '\033[1;33m⚠ %s\033[0m\n' "$*"; }
fatal() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ─── Argument parsing ──────────────────────────────────────────────────────
usage() {
    sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain)               DOMAIN="$2"; shift 2 ;;
        --repo)                 REPO="$2"; shift 2 ;;
        --branch)               BRANCH="$2"; shift 2 ;;
        --acme-email)           ACME_EMAIL="$2"; shift 2 ;;
        --admin-email)          ADMIN_EMAIL="$2"; shift 2 ;;
        --app-key)              APP_KEY="$2"; shift 2 ;;
        --github-runner-token)  RUNNER_TOKEN="$2"; shift 2 ;;
        --github-runner-url)    RUNNER_URL="$2"; shift 2 ;;
        --no-deploy)            DO_DEPLOY=0; shift ;;
        -h|--help)              usage 0 ;;
        *) fatal "Unknown option: $1 (try --help)" ;;
    esac
done

[ -z "$DOMAIN" ] && fatal "--domain is required"
[ "$EUID" -ne 0 ] && fatal "Run as root (sudo bash bootstrap.sh ...)"

[ -z "$ACME_EMAIL" ]  && ACME_EMAIL="admin@${DOMAIN}"
[ -z "$ADMIN_EMAIL" ] && ADMIN_EMAIL="admin@${DOMAIN}"

if [ -n "$RUNNER_TOKEN" ] && [ -z "$RUNNER_URL" ]; then
    fatal "--github-runner-token requires --github-runner-url"
fi

export DEBIAN_FRONTEND=noninteractive

# ─── Step 1: System update ─────────────────────────────────────────────────
log "[1/12] apt update + upgrade"
apt-get update -y
apt-get -o Dpkg::Options::="--force-confnew" upgrade -y

# ─── Step 2: Base packages ─────────────────────────────────────────────────
log "[2/12] Installing base packages"
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg git \
    ufw fail2ban unattended-upgrades \
    jq openssl \
    tzdata

# ─── Step 3: Docker Engine + Compose plugin ────────────────────────────────
log "[3/12] Installing Docker Engine + Compose"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    if [ ! -f /etc/apt/keyrings/docker.asc ]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
            -o /etc/apt/keyrings/docker.asc
        chmod a+r /etc/apt/keyrings/docker.asc
    fi
    cat >/etc/apt/sources.list.d/docker.list <<EOF
deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable
EOF
    apt-get update -y
    apt-get install -y --no-install-recommends \
        docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
    ok "Docker installed"
else
    ok "Docker already installed ($(docker --version | awk '{print $3}' | tr -d ,))"
fi

# ─── Step 4: Firewall ──────────────────────────────────────────────────────
log "[4/12] Configuring UFW"
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw allow 443/udp comment 'HTTPS/3'
ufw --force enable
ok "UFW active — 22/80/443 open, everything else denied"

# ─── Step 5: Unattended security upgrades ──────────────────────────────────
log "[5/12] Enabling unattended-upgrades"
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
ok "unattended-upgrades active"

# ─── Step 6: System user ──────────────────────────────────────────────────
log "[6/12] Creating ${APP_USER} user"
if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "/home/${APP_USER}" \
        --shell /usr/sbin/nologin "$APP_USER"
    ok "User ${APP_USER} created"
else
    ok "User ${APP_USER} already exists"
fi
usermod -aG docker "$APP_USER"

# ─── Step 7: Source checkout ──────────────────────────────────────────────
log "[7/12] Cloning / updating ${REPO} → ${APP_DIR}"
if [ -d "${APP_DIR}/.git" ]; then
    pushd "$APP_DIR" >/dev/null
    sudo -u "$APP_USER" git fetch --all --prune
    sudo -u "$APP_USER" git checkout "$BRANCH"
    sudo -u "$APP_USER" git pull --ff-only origin "$BRANCH"
    popd >/dev/null
    ok "Repo updated to ${BRANCH}"
else
    if [ "$REPO" = "https://github.com/CHANGE_ME/bexlogs.git" ]; then
        fatal "Pass --repo <git-url> on first run (no existing checkout at ${APP_DIR})."
    fi
    install -d -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
    sudo -u "$APP_USER" git clone --branch "$BRANCH" "$REPO" "$APP_DIR"
    ok "Cloned into ${APP_DIR}"
fi
chown -R "${APP_USER}:${APP_USER}" "$APP_DIR"

# ─── Step 8: .env generation ──────────────────────────────────────────────
log "[8/12] Generating laravel/.env"
ENV_FILE="${APP_DIR}/laravel/.env"
ENV_TEMPLATE="${APP_DIR}/laravel/.env.production.example"
[ ! -f "$ENV_TEMPLATE" ] && fatal "Missing ${ENV_TEMPLATE}. Did the repo check out cleanly?"

# Read or invent each secret. We do this BEFORE writing the file so we can
# pull existing values out of an existing .env (so re-running the bootstrap
# is non-destructive).
read_existing() {
    # $1: var name → echoes existing value or empty
    [ ! -f "$ENV_FILE" ] && return 0
    grep -E "^${1}=" "$ENV_FILE" | tail -1 | sed -E "s/^${1}=//; s/^['\"]//; s/['\"]$//"
}

gen_b64()   { echo "base64:$(openssl rand -base64 32 | tr -d '\n')"; }
gen_hex()   { openssl rand -hex 32; }
gen_id()    { openssl rand -hex 4 | awk '{printf "%d\n", strtonum("0x"$1)}'; }

EXISTING_APP_KEY="$(read_existing APP_KEY)"
EXISTING_DB_PASSWORD="$(read_existing DB_PASSWORD)"
EXISTING_WORKER_TOKEN="$(read_existing WORKER_API_TOKEN)"
EXISTING_REVERB_ID="$(read_existing REVERB_APP_ID)"
EXISTING_REVERB_KEY="$(read_existing REVERB_APP_KEY)"
EXISTING_REVERB_SECRET="$(read_existing REVERB_APP_SECRET)"

if [ -n "$APP_KEY" ]; then
    FINAL_APP_KEY="$APP_KEY"
elif [ -n "$EXISTING_APP_KEY" ] && [ "$EXISTING_APP_KEY" != "CHANGE_ME" ]; then
    FINAL_APP_KEY="$EXISTING_APP_KEY"
else
    FINAL_APP_KEY="$(gen_b64)"
fi

FINAL_DB_PASSWORD="${EXISTING_DB_PASSWORD:-$(gen_hex)}"
[ "$FINAL_DB_PASSWORD" = "CHANGE_ME" ] && FINAL_DB_PASSWORD="$(gen_hex)"

FINAL_WORKER_TOKEN="${EXISTING_WORKER_TOKEN:-$(gen_hex)}"
[ "$FINAL_WORKER_TOKEN" = "CHANGE_ME" ] && FINAL_WORKER_TOKEN="$(gen_hex)"

FINAL_REVERB_ID="${EXISTING_REVERB_ID:-$(gen_id)}"
[ "$FINAL_REVERB_ID" = "CHANGE_ME" ] && FINAL_REVERB_ID="$(gen_id)"

FINAL_REVERB_KEY="${EXISTING_REVERB_KEY:-$(openssl rand -hex 16)}"
[ "$FINAL_REVERB_KEY" = "CHANGE_ME" ] && FINAL_REVERB_KEY="$(openssl rand -hex 16)"

FINAL_REVERB_SECRET="${EXISTING_REVERB_SECRET:-$(openssl rand -hex 16)}"
[ "$FINAL_REVERB_SECRET" = "CHANGE_ME" ] && FINAL_REVERB_SECRET="$(openssl rand -hex 16)"

# Read the extension version straight out of manifest.json — no jq required
# because that's an extra runtime dep we'd rather skip on a fresh server,
# but jq is already installed above so use it.
EXT_VERSION="$(jq -r '.version' "${APP_DIR}/extension/manifest.json")"
[ "$EXT_VERSION" = "null" ] || [ -z "$EXT_VERSION" ] \
    && fatal "Couldn't parse extension/manifest.json version"

# Generate the .env from the template, doing in-place substitution.
TMP_ENV="$(mktemp)"
cp "$ENV_TEMPLATE" "$TMP_ENV"

sed_replace() {
    # $1: var, $2: value
    local key="$1" val="$2"
    val="${val//\\/\\\\}"
    val="${val//&/\\&}"
    val="${val//|/\\|}"
    sed -i -E "s|^${key}=.*$|${key}=${val}|" "$TMP_ENV"
}

sed_replace APP_DOMAIN              "$DOMAIN"
sed_replace APP_ACME_EMAIL          "$ACME_EMAIL"
sed_replace APP_KEY                 "$FINAL_APP_KEY"
sed_replace APP_URL                 "https://${DOMAIN}"
sed_replace DB_PASSWORD             "$FINAL_DB_PASSWORD"
sed_replace REVERB_HOST             "$DOMAIN"
sed_replace REVERB_APP_ID           "$FINAL_REVERB_ID"
sed_replace REVERB_APP_KEY          "$FINAL_REVERB_KEY"
sed_replace REVERB_APP_SECRET       "$FINAL_REVERB_SECRET"
# Vite needs the REVERB_* baked in at npm-run-build time. Mirror them here
# (these only appear in the *built* JS, not in the runtime PHP env).
sed_replace VITE_REVERB_APP_KEY     "$FINAL_REVERB_KEY"
sed_replace VITE_REVERB_HOST        "$DOMAIN"
sed_replace WORKER_API_TOKEN        "$FINAL_WORKER_TOKEN"
sed_replace EXTENSION_VERSION       "$EXT_VERSION"
sed_replace EXTENSION_MIN_VERSION   "$EXT_VERSION"
sed_replace MAIL_FROM_ADDRESS       "no-reply@${DOMAIN}"

install -m 0640 -o "$APP_USER" -g "$APP_USER" "$TMP_ENV" "$ENV_FILE"
rm -f "$TMP_ENV"
ok ".env written to ${ENV_FILE}"

# ─── Step 8b: Origin certificate preflight ────────────────────────────────
ORIGIN_DIR="/etc/bexlogs/origin"
ORIGIN_CRT="${ORIGIN_DIR}/origin.crt"
ORIGIN_KEY="${ORIGIN_DIR}/origin.key"

if [ ! -f "$ORIGIN_CRT" ] || [ ! -f "$ORIGIN_KEY" ]; then
    warn "Cloudflare Origin Certificate not found."
    cat <<EOF

  Before bringing the stack up, place the Cloudflare Origin Certificate
  and private key at:

      ${ORIGIN_CRT}
      ${ORIGIN_KEY}

  Generate one at:  Cloudflare dash → SSL/TLS → Origin Server → Create
  Certificate. RSA 2048, hostnames covering ${DOMAIN:-<your-domain>},
  15-year validity, PEM format.

  Paste the certificate block into ${ORIGIN_CRT} and the private key block
  into ${ORIGIN_KEY}. Then:

      install -d -m 0755 ${ORIGIN_DIR}
      chmod 0644 ${ORIGIN_CRT}
      chmod 0600 ${ORIGIN_KEY}

  And re-run this bootstrap.

EOF
    fatal "Origin certificate missing — see above."
fi

install -d -m 0755 "$ORIGIN_DIR"
chmod 0644 "$ORIGIN_CRT" || true
chmod 0600 "$ORIGIN_KEY" || true
ok "Origin certificate in place"

# ─── Step 9: Build + bring up the stack ───────────────────────────────────
COMPOSE=(docker compose -f "${APP_DIR}/docker-compose.production.yml" --env-file "$ENV_FILE")

if [ "$DO_DEPLOY" -eq 1 ]; then
    log "[9/12] Building images (this takes a few minutes on first run)"
    pushd "$APP_DIR" >/dev/null
    "${COMPOSE[@]}" build
    log "    Bringing the stack up"
    "${COMPOSE[@]}" up -d
    popd >/dev/null
    ok "Stack up"

    # ── Step 10: Wait for app, then migrate + cache ─────────────────────
    log "[10/12] Waiting for app health"
    for i in $(seq 1 60); do
        if "${COMPOSE[@]}" exec -T app curl -fsS http://127.0.0.1/up >/dev/null 2>&1; then
            ok "app is healthy"
            break
        fi
        sleep 2
        [ "$i" -eq 60 ] && warn "app didn't become healthy within 120s — continuing anyway"
    done

    log "    Running migrations + warming caches"
    "${COMPOSE[@]}" exec -T app php artisan migrate --force
    "${COMPOSE[@]}" exec -T app php artisan storage:link --quiet 2>/dev/null || true
    "${COMPOSE[@]}" exec -T app php artisan config:cache
    "${COMPOSE[@]}" exec -T app php artisan route:cache
    "${COMPOSE[@]}" exec -T app php artisan view:cache
    "${COMPOSE[@]}" exec -T app php artisan event:cache
    ok "App ready"

    # ── Step 11: Extension zip is baked into the image, nothing to do ──
    log "[11/12] Browser extension zip is built inside the docker image — skipping host build"
else
    log "[9-11/12] --no-deploy was set; skipping docker build/up and migrations"
fi

# ─── Step 12: GitHub Actions runner ───────────────────────────────────────
if [ -n "$RUNNER_TOKEN" ]; then
    log "[12/12] Registering self-hosted GitHub Actions runner"

    if [ -f "${RUNNER_DIR}/.runner" ]; then
        ok "Runner already configured at ${RUNNER_DIR} — skipping registration"
    else
        install -d -o "$APP_USER" -g "$APP_USER" "$RUNNER_DIR"

        # Resolve the latest runner version via the public GH API.
        RUNNER_VERSION="$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest \
            | jq -r '.tag_name | sub("^v";"")')"
        [ -z "$RUNNER_VERSION" ] || [ "$RUNNER_VERSION" = "null" ] \
            && fatal "Couldn't determine latest runner version"

        ARCH="$(uname -m)"
        case "$ARCH" in
            x86_64)  RUNNER_ARCH=x64 ;;
            aarch64) RUNNER_ARCH=arm64 ;;
            *) fatal "Unsupported arch: $ARCH" ;;
        esac

        TARBALL="actions-runner-linux-${RUNNER_ARCH}-${RUNNER_VERSION}.tar.gz"
        log "    Downloading actions-runner v${RUNNER_VERSION} (${RUNNER_ARCH})"
        sudo -u "$APP_USER" curl -fsSL \
            "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/${TARBALL}" \
            -o "${RUNNER_DIR}/${TARBALL}"
        sudo -u "$APP_USER" tar -xzf "${RUNNER_DIR}/${TARBALL}" -C "$RUNNER_DIR"
        rm -f "${RUNNER_DIR}/${TARBALL}"

        log "    Configuring runner"
        sudo -u "$APP_USER" "${RUNNER_DIR}/config.sh" \
            --url "$RUNNER_URL" \
            --token "$RUNNER_TOKEN" \
            --name "$(hostname)" \
            --labels "self-hosted,linux,${RUNNER_ARCH},bexlogs" \
            --work _work \
            --unattended \
            --replace

        log "    Installing systemd service"
        "${RUNNER_DIR}/svc.sh" install "$APP_USER"
        "${RUNNER_DIR}/svc.sh" start
        ok "Runner installed + started"
    fi
else
    log "[12/12] Skipping GitHub runner setup (--github-runner-token not set)"
fi

# ─── Summary ──────────────────────────────────────────────────────────────
cat <<EOF

$(printf '\033[1;32m')╔══════════════════════════════════════════════════════════════════╗
║              BexLogs deployed successfully                       ║
╚══════════════════════════════════════════════════════════════════╝$(printf '\033[0m')

  URL              https://${DOMAIN}
  Health endpoint  https://${DOMAIN}/up
  Source           ${APP_DIR} (branch: ${BRANCH})
  .env             ${ENV_FILE}
  Storage volume   bexlogs_storage  (named docker volume)
  PG volume        bexlogs_pg_data  (named docker volume)

  Useful commands (run from ${APP_DIR}):

    # Tail app logs
    docker compose -f docker-compose.production.yml logs -f app

    # All services + status
    docker compose -f docker-compose.production.yml ps

    # Run an artisan command
    docker compose -f docker-compose.production.yml exec app php artisan <cmd>

    # Restart just one role
    docker compose -f docker-compose.production.yml restart queue

  Next steps:

    1. Make sure your DNS has an A record:
         ${DOMAIN}  →  $(curl -fsS https://api.ipify.org 2>/dev/null || echo '<this server IP>')
       Caddy issues a Let's Encrypt cert as soon as DNS resolves and the
       ACME challenge can hit :80.

    2. Push to ${BRANCH} on GitHub. The self-hosted runner you just
       registered (or will register with --github-runner-token) will
       pick up .github/workflows/deploy.yml and redeploy.

    3. To roll forward manually:
         cd ${APP_DIR}
         git pull --ff-only
         docker compose -f docker-compose.production.yml up -d --build

EOF

ok "Done."
