#!/usr/bin/env bash
set -euo pipefail

# Stub: Provision a Sprite and bootstrap OpenClaw + PM skills + Telegram.
#
# Usage:
#   ./scripts/provision_sprite.sh --name pm-agent-test
#
# Requirements:
#   - sprite CLI installed + authenticated
#   - env vars set (see .env.example)

NAME=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --name) NAME="$2"; shift 2;;
    *) echo "Unknown arg: $1"; exit 1;;
  esac
done

if [[ -z "$NAME" ]]; then
  echo "Missing --name" >&2
  exit 1
fi

echo "[1/6] Creating sprite: $NAME"
sprite create "$NAME" || true
sprite use "$NAME"

echo "[2/6] Installing prerequisites (stub)"
# TODO: install openclaw if not present
# TODO: install jq, git if needed

echo "[3/6] Writing OpenClaw config (stub)"
# TODO: render configs/openclaw.template.json5 -> ~/.openclaw/openclaw.json

echo "[4/6] Installing PM skills repo (stub)"
# TODO: clone PM skills repo and install/symlink skills into OpenClaw skills dir

echo "[5/6] Configure Telegram channel (stub)"
# TODO: set telegram bot token + allowed chat id

echo "[6/6] Start OpenClaw gateway + channel (stub)"
# TODO: openclaw gateway start

echo "\nProvisioning stub complete. Next:" 
cat <<EOF
- Verify sprite URL: sprite url
- Open sprite console: sprite console
- Tail logs (TBD)
EOF
