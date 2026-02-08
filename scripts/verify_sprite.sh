#!/usr/bin/env bash
set -euo pipefail

# Stub: Verify a Sprite has OpenClaw running + skills installed.
# Usage:
#   ./scripts/verify_sprite.sh --name pm-agent-test

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

echo "Verifying sprite: $NAME"

echo "[1/3] Checking OpenClaw installed (stub)"
# TODO: sprite exec which openclaw

echo "[2/3] Checking gateway running (stub)"
# TODO: sprite exec openclaw status (or equivalent)

echo "[3/3] Checking skills present (stub)"
# TODO: sprite exec ls installed skills

echo "PASS (stub)"
