#!/usr/bin/env bash
set -euo pipefail

# Verify a Sprite has OpenClaw + pmprompt skills installed.
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

# Run verification entirely inside the Sprite so PATH repair is consistent.
sprite exec -s "$NAME" bash -s <<'EOS'
set -euo pipefail

echo "[1/4] Checking OpenClaw is installed"
NPM_BIN="$(npm bin -g 2>/dev/null || true)"
NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then export PATH="$NPM_BIN:$PATH"; fi
if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then export PATH="$NPM_PREFIX/bin:$PATH"; fi
if [[ -d '/.sprite/languages/node/nvm/versions/node/v22.20.0/bin' ]]; then export PATH="/.sprite/languages/node/nvm/versions/node/v22.20.0/bin:$PATH"; fi
export PATH="$HOME/.local/bin:$PATH"
hash -r

command -v openclaw
openclaw --version

echo "[2/4] Checking gateway health (best-effort; may fail on Sprites)"
# Sprites doesn't provide systemd user services, so gateway service probing can fail.
# Keep this non-blocking and time-bounded.
(timeout 5s openclaw gateway health || true) >/tmp/openclawpm_gateway_check.txt 2>&1 || true
(tail -n 40 /tmp/openclawpm_gateway_check.txt || true)

echo "[3/4] Checking pmprompt skills are discoverable"
WS="$(openclaw config get agents.defaults.workspace)"
ls -la "$WS/skills" | head

openclaw skills list | sed -n '1,120p'
openclaw skills list | grep -q '\bshaping\b'

echo "[4/4] Running a local agent turn (requires model key configured)"
openclaw agent --local --message 'Reply with exactly: READY. Then list 3 pmprompt skills you can run.' --timeout 120

echo "PASS"
EOS
