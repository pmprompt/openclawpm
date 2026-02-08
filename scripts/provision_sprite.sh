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

# Decide provider based on env vars
if [[ -z "${OPENAI_API_KEY:-}" && -z "${ANTHROPIC_API_KEY:-}" && -z "${OPENROUTER_API_KEY:-}" ]]; then
  echo "Missing OPENAI_API_KEY or ANTHROPIC_API_KEY or OPENROUTER_API_KEY (set one)." >&2
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

PM_SKILLS_REPO="${PM_SKILLS_REPO:-git@github.com:pmprompt/claude-plugin-product-management.git}"
PM_SKILLS_REF="${PM_SKILLS_REF:-main}"

# base64 portability: GNU uses -w; BSD/macOS does not.
# Use tr to strip newlines. (Also strip CR just in case.)
_b64() { base64 | tr -d '\n' | tr -d '\r'; }

SYS_PROMPT_B64="$(base64 < "$(dirname "$0")/../prompts/system-prompt-pm.txt" | tr -d '\n' | tr -d '\r')"

# Avoid quoting issues when passing secrets to sprite exec by base64-encoding them.
OPENAI_API_KEY_B64="$(printf %s "${OPENAI_API_KEY:-}" | _b64)"
ANTHROPIC_API_KEY_B64="$(printf %s "${ANTHROPIC_API_KEY:-}" | _b64)"
OPENROUTER_API_KEY_B64="$(printf %s "${OPENROUTER_API_KEY:-}" | _b64)"

OPENCLAW_MODEL_PRIMARY_B64="$(printf %s "${OPENCLAW_MODEL_PRIMARY:-}" | _b64)"
PM_SKILLS_REPO_B64="$(printf %s "${PM_SKILLS_REPO}" | _b64)"
PM_SKILLS_REF_B64="$(printf %s "${PM_SKILLS_REF}" | _b64)"


echo "[1/6] Creating sprite: $NAME"
# Create sprite (if it already exists, reuse it)
if ! sprite create "$NAME"; then
  echo "[openclawpm] Sprite already exists; reusing: $NAME"
fi
sprite use "$NAME"

echo "[2/6] Bootstrapping OpenClaw + skills inside the Sprite"

# Build a single in-sprite bootstrap script and run it to avoid fragile quote-escaping.
BOOT_SCRIPT_B64="$(base64 <<'EOS' | tr -d '\n' | tr -d '\r'
#!/usr/bin/env bash
set -euo pipefail

# Expect env vars (all base64):
#   OPENCLAW_GATEWAY_TOKEN
#   SYS_PROMPT_B64
#   PM_SKILLS_REPO_B64, PM_SKILLS_REF_B64
#   OPENCLAW_MODEL_PRIMARY_B64
#   OPENAI_API_KEY_B64 | ANTHROPIC_API_KEY_B64 | OPENROUTER_API_KEY_B64

b64d() {
  local v="$1"
  if [[ -z "$v" ]]; then
    printf ''
    return 0
  fi
  printf '%s' "$v" | base64 -d
}

# Decode config values
PM_SKILLS_REPO="$(b64d "${PM_SKILLS_REPO_B64:-}")"
PM_SKILLS_REF="$(b64d "${PM_SKILLS_REF_B64:-}")"
OPENCLAW_MODEL_PRIMARY="$(b64d "${OPENCLAW_MODEL_PRIMARY_B64:-}")"

OPENAI_API_KEY="$(b64d "${OPENAI_API_KEY_B64:-}")"
ANTHROPIC_API_KEY="$(b64d "${ANTHROPIC_API_KEY_B64:-}")"
OPENROUTER_API_KEY="$(b64d "${OPENROUTER_API_KEY_B64:-}")"

export OPENAI_API_KEY ANTHROPIC_API_KEY OPENROUTER_API_KEY

if ! command -v openclaw >/dev/null 2>&1; then
  echo '[openclawpm] Installing OpenClaw (skip onboard)...'
  curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard
fi

# Repair PATH for current shell (Sprites uses nvm; installer warns PATH may be missing it)
NPM_BIN="$(npm bin -g 2>/dev/null || true)"
NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then export PATH="$NPM_BIN:$PATH"; fi
if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then export PATH="$NPM_PREFIX/bin:$PATH"; fi
if [[ -d '/.sprite/languages/node/nvm/versions/node/v22.20.0/bin' ]]; then export PATH="/.sprite/languages/node/nvm/versions/node/v22.20.0/bin:$PATH"; fi
export PATH="$HOME/.local/bin:$PATH"
hash -r

command -v openclaw >/dev/null || (echo 'openclaw still not found after install' >&2; exit 1)
openclaw --version

AUTH_ARGS=()
if [[ -n "${OPENAI_API_KEY:-}" ]]; then
  AUTH_ARGS+=(--auth-choice openai-api-key --openai-api-key "${OPENAI_API_KEY}")
elif [[ -n "${ANTHROPIC_API_KEY:-}" ]]; then
  AUTH_ARGS+=(--auth-choice anthropic-api-key --anthropic-api-key "${ANTHROPIC_API_KEY}")
elif [[ -n "${OPENROUTER_API_KEY:-}" ]]; then
  AUTH_ARGS+=(--auth-choice openrouter-api-key --openrouter-api-key "${OPENROUTER_API_KEY}")
else
  echo 'Missing provider key' >&2
  exit 1
fi

# Stop any prior gateway (best-effort)
openclaw gateway stop >/dev/null 2>&1 || true

# Run onboarding but skip health checks/daemon install so we don't fail due to gateway WS flakiness.
openclaw onboard \
  --non-interactive --accept-risk \
  --flow quickstart \
  --skip-channels --skip-ui \
  --skip-health \
  --skip-daemon \
  --gateway-bind loopback \
  --gateway-auth token \
  --gateway-token "${OPENCLAW_GATEWAY_TOKEN}" \
  "${AUTH_ARGS[@]}" \
  --json >/tmp/openclaw_onboard.json

if [[ -n "${OPENCLAW_MODEL_PRIMARY:-}" ]]; then
  openclaw config set agents.defaults.model.primary "${OPENCLAW_MODEL_PRIMARY}"
fi

WS="$(openclaw config get agents.defaults.workspace)"
mkdir -p "$WS/skills"

rm -rf /tmp/pmprompt-skills || true

git clone --depth 1 --branch "$PM_SKILLS_REF" "$PM_SKILLS_REPO" /tmp/pmprompt-skills

# rsync may be missing; fall back to cp
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete /tmp/pmprompt-skills/skills/ "$WS/skills/"
else
  rm -rf "$WS/skills"/*
  cp -R /tmp/pmprompt-skills/skills/* "$WS/skills/"
fi

# Minimal workspace context files
cat > "$WS/IDENTITY.md" <<'EOF'
# IDENTITY.md

- **Name:** Kramer
- **Creature:** AI assistant / product management collaborator
- **Vibe:** Sharp but approachable, artifact-first
EOF

# Write system prompt
echo "$SYS_PROMPT_B64" | base64 -d > "$WS/SOUL.md"

cat > "$WS/USER.md" <<'EOF'
# USER.md

- **Default ICP:** professional product managers
- **Focus:** producing shippable artifacts (PRDs, decision memos, shaping pitches)
EOF

openclaw gateway start || true
openclaw gateway status || true
EOS
)"

# Run the bootstrap script inside the sprite
sprite exec bash -lc "set -euo pipefail
  echo '$BOOT_SCRIPT_B64' | base64 -d > /tmp/openclawpm_bootstrap.sh
  chmod +x /tmp/openclawpm_bootstrap.sh

  OPENCLAW_GATEWAY_TOKEN='${OPENCLAW_GATEWAY_TOKEN}' \
  SYS_PROMPT_B64='${SYS_PROMPT_B64}' \
  PM_SKILLS_REPO_B64='${PM_SKILLS_REPO_B64}' \
  PM_SKILLS_REF_B64='${PM_SKILLS_REF_B64}' \
  OPENCLAW_MODEL_PRIMARY_B64='${OPENCLAW_MODEL_PRIMARY_B64}' \
  OPENAI_API_KEY_B64='${OPENAI_API_KEY_B64}' \
  ANTHROPIC_API_KEY_B64='${ANTHROPIC_API_KEY_B64}' \
  OPENROUTER_API_KEY_B64='${OPENROUTER_API_KEY_B64}' \
  bash /tmp/openclawpm_bootstrap.sh
"

echo "[6/6] Done. Next steps"
cat <<EOF
Sprite: $NAME
- Console: sprite console -s $NAME
- Verify:  ./scripts/verify_sprite.sh --name $NAME
- CLI:     (cd cli && ./openclawpm verify $NAME)
EOF
