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

echo "[1/4] Checking OpenClaw is installed"
# OpenClaw may be installed via npm global bin that isn't on PATH in fresh shells.
sprite exec -s "$NAME" bash -lc "set -euo pipefail
  NPM_BIN=\"$(npm bin -g 2>/dev/null || true)\"
  NPM_PREFIX=\"$(npm config get prefix 2>/dev/null || true)\"
  if [[ -n \"$NPM_BIN\" && -d \"$NPM_BIN\" ]]; then export PATH=\"$NPM_BIN:$PATH\"; fi
  if [[ -n \"$NPM_PREFIX\" && -d \"$NPM_PREFIX/bin\" ]]; then export PATH=\"$NPM_PREFIX/bin:$PATH\"; fi
  if [[ -d '/.sprite/languages/node/nvm/versions/node/v22.20.0/bin' ]]; then export PATH='/.sprite/languages/node/nvm/versions/node/v22.20.0/bin:'\"$PATH\"; fi
  export PATH=\"$HOME/.local/bin:$PATH\"
  hash -r
  command -v openclaw
  openclaw --version
"
echo "[2/4] Checking gateway health (best-effort)"
# On Sprites, systemd user services are unavailable. Gateway service checks may fail.
# We keep this best-effort and don't fail the verify on it.
sprite exec -s "$NAME" bash -lc "openclaw gateway health || openclaw gateway status || true" || true

echo "[3/4] Checking pmprompt skills are discoverable"
# Ensure our expected skills exist in workspace
sprite exec -s "$NAME" bash -lc "WS=\"$(openclaw config get agents.defaults.workspace)\"; ls -la \"$WS/skills\" | head"

# Skills list (this is the real signal)
sprite exec -s "$NAME" bash -lc "openclaw skills list | sed -n '1,120p'"

# Hard assert at least shaping exists (we added it to the plugin repo)
sprite exec -s "$NAME" bash -lc "openclaw skills list | grep -q '\\bshaping\\b'"

echo "[4/4] Running a local agent turn (requires model key configured)"
# Run embedded agent to ensure model/provider works. This does not require Telegram or any gateway service.
# (Provider keys must be present in the sprite config from onboarding.)
sprite exec -s "$NAME" bash -lc "openclaw agent --local --message 'Reply with exactly: READY. Then list 3 pmprompt skills you can run.' --timeout 120"
echo "PASS"
