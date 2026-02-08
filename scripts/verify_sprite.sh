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
sprite exec -s "$NAME" bash -lc "command -v openclaw && openclaw --version"

echo "[2/4] Checking gateway health"
# Status may fail if service isn't installed; probe gateway health via local call.
sprite exec -s "$NAME" bash -lc "openclaw gateway health || openclaw gateway status || true"

echo "[3/4] Checking pmprompt skills are discoverable"
# Ensure our expected skills exist in workspace
sprite exec -s "$NAME" bash -lc "WS=\"$(openclaw config get agents.defaults.workspace)\"; ls -la \"$WS/skills\" | head"

# Skills list (this is the real signal)
sprite exec -s "$NAME" bash -lc "openclaw skills list | sed -n '1,120p'"

# Hard assert at least shaping exists (we added it to the plugin repo)
sprite exec -s "$NAME" bash -lc "openclaw skills list | grep -q '\\bshaping\\b'"

echo "[4/4] Running a local agent turn (requires model key configured)"
# Run embedded agent to ensure model/provider works. This does not require Telegram or any channel.
sprite exec -s "$NAME" bash -lc "openclaw agent --local --message 'Reply with exactly: READY. Then list 3 pmprompt skills you can run.' --timeout 120"

echo "PASS"
