#!/usr/bin/env bash
set -euo pipefail

# Provision a Sprite and bootstrap OpenClaw + pmprompt skills.
#
# Usage:
#   ./scripts/provision_sprite.sh --name pm-agent-test
#
# Required env:
#   - One provider key: OPENAI_API_KEY or ANTHROPIC_API_KEY or OPENROUTER_API_KEY
# Optional env:
#   - OPENCLAW_MODEL_PRIMARY (e.g. sonnet)
#   - PM_SKILLS_REPO (default pmprompt/claude-plugin-product-management)
#   - PM_SKILLS_REF (default main)

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

if [[ -z "${OPENAI_API_KEY:-}" && -z "${ANTHROPIC_API_KEY:-}" && -z "${OPENROUTER_API_KEY:-}" ]]; then
  echo "Missing OPENAI_API_KEY or ANTHROPIC_API_KEY or OPENROUTER_API_KEY (set one)." >&2
  exit 1
fi

if [[ -z "${OPENCLAW_GATEWAY_TOKEN:-}" ]]; then
  OPENCLAW_GATEWAY_TOKEN="$(python3 - <<'PY'
import secrets
print(secrets.token_hex(32))
PY
)"
  export OPENCLAW_GATEWAY_TOKEN
fi

PM_SKILLS_REPO="${PM_SKILLS_REPO:-https://github.com/pmprompt/claude-plugin-product-management.git}"
PM_SKILLS_REF="${PM_SKILLS_REF:-main}"

# base64 portability: GNU uses -w; BSD/macOS does not.
_b64() { base64 | tr -d '\n' | tr -d '\r'; }

SYS_PROMPT_B64="$(base64 < "$(dirname "$0")/../prompts/system-prompt-pm.txt" | tr -d '\n' | tr -d '\r')"

OPENAI_API_KEY_B64="$(printf %s "${OPENAI_API_KEY:-}" | _b64)"
ANTHROPIC_API_KEY_B64="$(printf %s "${ANTHROPIC_API_KEY:-}" | _b64)"
OPENROUTER_API_KEY_B64="$(printf %s "${OPENROUTER_API_KEY:-}" | _b64)"

OPENCLAW_MODEL_PRIMARY_B64="$(printf %s "${OPENCLAW_MODEL_PRIMARY:-}" | _b64)"
PM_SKILLS_REPO_B64="$(printf %s "${PM_SKILLS_REPO}" | _b64)"
PM_SKILLS_REF_B64="$(printf %s "${PM_SKILLS_REF}" | _b64)"

BOOTSTRAP_B64="$(base64 < "$(dirname "$0")/bootstrap_in_sprite.sh" | tr -d '\n' | tr -d '\r')"

echo "[1/6] Creating sprite: $NAME"
if ! sprite create "$NAME"; then
  echo "[openclawpm] Sprite already exists; reusing: $NAME"
fi
sprite use "$NAME"

echo "[2/6] Bootstrapping OpenClaw + skills inside the Sprite"

# Write bootstrap script + env into sprite, then run it. This avoids fragile nested quoting.
sprite exec bash -s <<EOS
set -euo pipefail

printf '%s' "$BOOTSTRAP_B64" | base64 -d > /tmp/openclawpm_bootstrap_in_sprite.sh
chmod +x /tmp/openclawpm_bootstrap_in_sprite.sh

cat > /tmp/openclawpm_env <<EOF
OPENCLAW_GATEWAY_TOKEN=$OPENCLAW_GATEWAY_TOKEN
SYS_PROMPT_B64=$SYS_PROMPT_B64
PM_SKILLS_REPO_B64=$PM_SKILLS_REPO_B64
PM_SKILLS_REF_B64=$PM_SKILLS_REF_B64
OPENCLAW_MODEL_PRIMARY_B64=$OPENCLAW_MODEL_PRIMARY_B64
OPENAI_API_KEY_B64=$OPENAI_API_KEY_B64
ANTHROPIC_API_KEY_B64=$ANTHROPIC_API_KEY_B64
OPENROUTER_API_KEY_B64=$OPENROUTER_API_KEY_B64
EOF

set -a
source /tmp/openclawpm_env
set +a

bash /tmp/openclawpm_bootstrap_in_sprite.sh
EOS

echo "[6/6] Done. Next steps"
cat <<EOF
Sprite: $NAME
- Console: sprite console -s $NAME
- Verify:  ./scripts/verify_sprite.sh --name $NAME
- CLI:     (cd cli && ./openclawpm verify $NAME)
EOF
