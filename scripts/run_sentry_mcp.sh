#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/scripts/sentry-mcp.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE — copy scripts/sentry-mcp.env.example" >&2
  exit 1
fi

unset SENTRY_BACKEND_ACCESS_TOKEN

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${SENTRY_BACKEND_ACCESS_TOKEN:?Set SENTRY_BACKEND_ACCESS_TOKEN in scripts/sentry-mcp.env}"

exec npx @sentry/mcp-server@latest \
  --access-token="$SENTRY_BACKEND_ACCESS_TOKEN" \
  --host=sentry-backend.vitrion.dev \
  --disable-skills=seer
