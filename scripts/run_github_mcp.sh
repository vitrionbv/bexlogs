#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/scripts/github-mcp.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE — copy scripts/github-mcp.env.example" >&2
  exit 1
fi

unset GITHUB_RESOLVED_ACCESS_TOKEN GITHUB_VERBLEIF_ACCESS_TOKEN GITHUB_VITRION_ACCESS_TOKEN GITHUB_PERSONAL_ACCESS_TOKEN

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

: "${GITHUB_VITRION_ACCESS_TOKEN:?Set GITHUB_VITRION_ACCESS_TOKEN in scripts/github-mcp.env}"

exec docker run -i --rm \
  -e GITHUB_PERSONAL_ACCESS_TOKEN="$GITHUB_VITRION_ACCESS_TOKEN" \
  ghcr.io/github/github-mcp-server
