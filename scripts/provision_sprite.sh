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

echo "[2/6] Bootstrapping OpenClaw (non-interactive)"

# Decide provider based on env vars
AUTH_ARGS=()
if [[ -n "${OPENAI_API_KEY:-}" ]]; then
  AUTH_ARGS+=(--auth-choice openai-api-key --openai-api-key "${OPENAI_API_KEY}")
elif [[ -n "${ANTHROPIC_API_KEY:-}" ]]; then
  AUTH_ARGS+=(--auth-choice anthropic-api-key --anthropic-api-key "${ANTHROPIC_API_KEY}")
else
  echo "Missing OPENAI_API_KEY or ANTHROPIC_API_KEY (set one)." >&2
  exit 1
fi

# Generate a gateway token if not supplied
if [[ -z "${OPENCLAW_GATEWAY_TOKEN:-}" ]]; then
  OPENCLAW_GATEWAY_TOKEN="$(python3 - <<'PY'
import secrets
print(secrets.token_hex(32))
PY
)"
  export OPENCLAW_GATEWAY_TOKEN
fi

# Create config + workspace inside the Sprite
sprite exec bash -lc "set -euo pipefail
  command -v openclaw >/dev/null || (echo 'openclaw not found in sprite PATH' >&2; exit 1)

  openclaw onboard \
    --non-interactive --accept-risk \
    --flow quickstart \
    --skip-channels --skip-ui \
    --gateway-bind loopback \
    --gateway-auth token \
    --gateway-token '${OPENCLAW_GATEWAY_TOKEN}' \
    ${AUTH_ARGS[*]} \
    --json >/tmp/openclaw_onboard.json

  # Optional model override
  if [[ -n \"${OPENCLAW_MODEL_PRIMARY:-}\" ]]; then
    openclaw config set agents.defaults.model.primary \"${OPENCLAW_MODEL_PRIMARY}\"
  fi
"

echo "[3/6] Installing PM skills into OpenClaw workspace"

PM_SKILLS_REPO="${PM_SKILLS_REPO:-git@github.com:pmprompt/claude-plugin-product-management.git}"
PM_SKILLS_REF="${PM_SKILLS_REF:-main}"

sprite exec bash -lc "set -euo pipefail
  WS=\"$(openclaw config get agents.defaults.workspace)\"
  mkdir -p \"$WS/skills\"

  rm -rf /tmp/pmprompt-skills || true
  git clone --depth 1 --branch '${PM_SKILLS_REF}' '${PM_SKILLS_REPO}' /tmp/pmprompt-skills

  # Copy all skills from the plugin repo into the OpenClaw workspace skills folder
  rsync -a --delete /tmp/pmprompt-skills/skills/ \"$WS/skills/\"
"

echo "[4/6] Writing workspace identity + PM system prompt files"

# Minimal workspace context files (these are what *we* want our vertical agent to read)
SYS_PROMPT=$(cat "$(dirname "$0")/../prompts/system-prompt-pm.txt")

sprite exec bash -lc "set -euo pipefail
  WS=\"$(openclaw config get agents.defaults.workspace)\"

  cat > \"$WS/IDENTITY.md\" <<'EOF'
# IDENTITY.md

- **Name:** Kramer
- **Creature:** AI assistant / product management collaborator
- **Vibe:** Sharp but approachable, artifact-first
EOF

  cat > \"$WS/SOUL.md\" <<'EOF'
# SOUL.md

${SYS_PROMPT}
EOF

  cat > \"$WS/USER.md\" <<'EOF'
# USER.md

- **Default ICP:** professional product managers
- **Focus:** producing shippable artifacts (PRDs, decision memos, shaping pitches)
EOF
"

echo "[5/6] Starting gateway service"
# Ensure gateway is running. "gateway status" works whether it is a service or foreground.
# If the daemon is installed, this will start it; otherwise it will no-op.
sprite exec bash -lc "set -euo pipefail
  openclaw gateway start || true
  openclaw gateway status || true
"

echo "[6/6] Done. Next steps"
cat <<EOF
Sprite: $NAME
- Console: sprite console -s $NAME
- Verify:  ./scripts/verify_sprite.sh --name $NAME
- CLI:     (cd cli && ./openclawpm verify $NAME)
EOF
