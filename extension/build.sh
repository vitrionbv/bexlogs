#!/usr/bin/env bash
# Build the BexLogs browser extension into a distributable zip and copy it
# into the Laravel app's public storage so /extension/download serves it.
#
# Usage:
#   ./build.sh
#   ./build.sh --laravel-storage /path/to/laravel/storage/app/public
set -euo pipefail

cd "$(dirname "$0")"

LARAVEL_STORAGE_DEFAULT="$(cd ../laravel/storage/app/public 2>/dev/null && pwd || echo "")"
LARAVEL_STORAGE="${LARAVEL_STORAGE_DEFAULT}"

while [ $# -gt 0 ]; do
    case "$1" in
        --laravel-storage)
            LARAVEL_STORAGE="$2"
            shift 2
            ;;
        *)
            echo "Unknown arg: $1" >&2
            exit 1
            ;;
    esac
done

VERSION="$(grep -E '"version"' manifest.json | head -1 | sed -E 's/.*"version"[^"]+"([^"]+)".*/\1/')"
OUT_DIR="build"
ZIP_NAME="bexlogs-extension.zip"
VERSIONED_ZIP="bexlogs-extension-${VERSION}.zip"

rm -rf "${OUT_DIR}"
mkdir -p "${OUT_DIR}"

echo "Packaging extension v${VERSION}…"
zip -r -X "${OUT_DIR}/${ZIP_NAME}" \
    manifest.json background.js content.js popup.html popup.css popup.js icons \
    -x "*.DS_Store" >/dev/null

cp "${OUT_DIR}/${ZIP_NAME}" "${OUT_DIR}/${VERSIONED_ZIP}"

if [ -n "${LARAVEL_STORAGE}" ] && [ -d "${LARAVEL_STORAGE}" ]; then
    cp "${OUT_DIR}/${ZIP_NAME}" "${LARAVEL_STORAGE}/${ZIP_NAME}"
    echo "  → copied to ${LARAVEL_STORAGE}/${ZIP_NAME}"
else
    echo "  (skipped Laravel-storage copy — pass --laravel-storage <path>)"
fi

echo "Done: ${OUT_DIR}/${VERSIONED_ZIP}"
