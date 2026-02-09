#!/usr/bin/env bash
set -euo pipefail

# Runs INSIDE the Sprite.
# Expects env vars (base64 where noted):
# - OPENCLAW_GATEWAY_TOKEN
# - SYS_PROMPT_B64
# - PM_SKILLS_REPO_B64, PM_SKILLS_REF_B64
# - OPENCLAW_MODEL_PRIMARY_B64 (optional)
# - OPENAI_API_KEY_B64 | ANTHROPIC_API_KEY_B64 | OPENROUTER_API_KEY_B64 (exactly one non-empty)

b64d() {
  local v="${1:-}"
  if [[ -z "$v" ]]; then
    printf ''
    return 0
  fi
  printf '%s' "$v" | base64 -d
}

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

  # Ensure the model is present in agents.defaults.models when using explicit model ids (esp. OpenRouter).
  python3 - <<'PY'
import json, os
from pathlib import Path
cfg_path = Path.home() / '.openclaw' / 'openclaw.json'
model = os.environ.get('OPENCLAW_MODEL_PRIMARY','')
if not cfg_path.exists() or not model:
    raise SystemExit(0)

cfg = json.loads(cfg_path.read_text())
agents = cfg.setdefault('agents', {}).setdefault('defaults', {})
models = agents.setdefault('models', {})
models.setdefault(model, {})

cfg_path.write_text(json.dumps(cfg, indent=2, sort_keys=True) + "\n")
print(f"[openclawpm] ensured model present in config: {model}")
PY
fi

WS="$(openclaw config get agents.defaults.workspace)"
mkdir -p "$WS/skills"

rm -rf /tmp/pmprompt-skills || true

git clone --depth 1 --branch "$PM_SKILLS_REF" "$PM_SKILLS_REPO" /tmp/pmprompt-skills

if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete /tmp/pmprompt-skills/skills/ "$WS/skills/"
else
  rm -rf "$WS/skills"/*
  cp -R /tmp/pmprompt-skills/skills/* "$WS/skills/"
fi

cat > "$WS/IDENTITY.md" <<'EOF'
# IDENTITY.md

- **Name:** Kramer
- **Creature:** AI assistant / product management collaborator
- **Vibe:** Sharp but approachable, artifact-first
EOF

echo "$SYS_PROMPT_B64" | base64 -d > "$WS/SOUL.md"

cat > "$WS/USER.md" <<'EOF'
# USER.md

- **Default ICP:** professional product managers
- **Focus:** producing shippable artifacts (PRDs, decision memos, shaping pitches)
EOF

openclaw gateway start || true
openclaw gateway status || true
