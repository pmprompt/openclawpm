#!/usr/bin/env bash
set -euo pipefail

# Verify a Sprite has OpenClaw running + skills installed.
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

echo "[1/4] Checking OpenClaw is installed"
# Use stdin to avoid local shell variable expansion issues.
sprite exec -s "$NAME" bash -s <<'EOS'
set -euo pipefail

# Repair PATH for OpenClaw installed via npm global bin.
NPM_BIN="$(npm bin -g 2>/dev/null || true)"
NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then export PATH="$NPM_BIN:$PATH"; fi
if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then export PATH="$NPM_PREFIX/bin:$PATH"; fi
if [[ -d '/.sprite/languages/node/nvm/versions/node/v22.20.0/bin' ]]; then export PATH="/.sprite/languages/node/nvm/versions/node/v22.20.0/bin:$PATH"; fi
export PATH="$HOME/.local/bin:$PATH"
hash -r

command -v openclaw
openclaw --version
EOS

echo "[2/4] Checking gateway health (best-effort)"
# On Sprites, systemd user services are unavailable; don't fail the verify on this.
sprite exec -s "$NAME" bash -lc "openclaw gateway health || openclaw gateway status || true" || true

echo "[3/4] Checking pmprompt skills are discoverable"
sprite exec -s "$NAME" bash -lc "WS=\"\$(openclaw config get agents.defaults.workspace)\"; ls -la \"\$WS/skills\" | head"

# Skills list (signal)
sprite exec -s "$NAME" bash -lc "openclaw skills list | sed -n '1,120p'"

# Hard assert shaping exists
sprite exec -s "$NAME" bash -lc "openclaw skills list | grep -q '\\bshaping\\b'"

echo "[4/4] Running a local agent turn (requires model key configured)"
sprite exec -s "$NAME" bash -lc "openclaw agent --local --message 'Reply with exactly: READY. Then list 3 pmprompt skills you can run.' --timeout 120"

echo "PASS"
