#!/usr/bin/env bash
set -euo pipefail

# Runs INSIDE the Sprite.
# Expects env vars (base64 where noted):
# - OPENCLAW_GATEWAY_TOKEN
# - SYS_PROMPT_B64
# - PM_SKILLS_REPO_B64, PM_SKILLS_REF_B64
# - OPENCLAW_MODEL_PRIMARY_B64 (optional)
# - OPENAI_API_KEY_B64 | ANTHROPIC_API_KEY_B64 | OPENROUTER_API_KEY_B64 (exactly one non-empty)

# ============================================================================
# INLINE UTILITY FUNCTIONS (copied from lib/sprite_utils.sh)
# ============================================================================

b64_decode() {
    local v="${1:-}"
    if [[ -z "$v" ]]; then
        printf ''
        return 0
    fi
    printf '%s' "$v" | base64 -d
}

# Repair PATH for OpenClaw installed via npm global bin
repair_path() {
    local NPM_BIN="$(npm bin -g 2>/dev/null || true)"
    local NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
    
    if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then
        export PATH="$NPM_BIN:$PATH"
    fi
    if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then
        export PATH="$NPM_PREFIX/bin:$PATH"
    fi
    
    # Try to find node installation dynamically
    local NODE_BIN_DIR
    NODE_BIN_DIR="$(find /.sprite/languages/node/nvm/versions/node -name 'bin' -type d 2>/dev/null | head -1 || true)"
    if [[ -n "$NODE_BIN_DIR" && -d "$NODE_BIN_DIR" ]]; then
        export PATH="$NODE_BIN_DIR:$PATH"
    fi
    
    export PATH="$HOME/.local/bin:$PATH"
    hash -r
}

# Download with retry
download_with_retry() {
    local url="$1"
    local max_attempts="${2:-3}"
    local attempt=1
    local delay=2
    
    while [[ $attempt -le $max_attempts ]]; do
        if curl --retry "$max_attempts" --retry-delay 2 --retry-max-time 30 -fsSL "$url"; then
            return 0
        fi
        
        if [[ $attempt -eq $max_attempts ]]; then
            echo "Failed after $max_attempts attempts" >&2
            return 1
        fi
        
        echo "Attempt $attempt failed. Retrying in ${delay}s..." >&2
        sleep $delay
        delay=$((delay * 2))
        attempt=$((attempt + 1))
    done
}

# Git clone with retry
git_clone_with_retry() {
    local repo="$1"
    local dest="$2"
    local ref="${3:-main}"
    local max_attempts="${4:-3}"
    local attempt=1
    local delay=2
    
    while [[ $attempt -le $max_attempts ]]; do
        if git clone --depth 1 --branch "$ref" "$repo" "$dest"; then
            return 0
        fi
        
        if [[ $attempt -eq $max_attempts ]]; then
            echo "Failed after $max_attempts attempts" >&2
            return 1
        fi
        
        echo "Attempt $attempt failed. Retrying in ${delay}s..." >&2
        sleep $delay
        delay=$((delay * 2))
        attempt=$((attempt + 1))
    done
}

# Cleanup temp files on exit
cleanup_temp_files() {
    local files=("$@")
    for file in "${files[@]}"; do
        if [[ -f "$file" ]]; then
            rm -f "$file"
        fi
    done
}

# Register cleanup trap
register_cleanup() {
    local temp_files=("$@")
    trap 'cleanup_temp_files "${temp_files[@]}"' EXIT INT TERM
}

# ============================================================================
# MAIN SCRIPT
# ============================================================================

# Register cleanup for temp files
TEMP_FILES=(
    "/tmp/openclawpm_bootstrap_in_sprite.sh"
    "/tmp/openclawpm_env"
    "/tmp/openclaw_onboard.json"
    "/tmp/pmprompt-skills"
)
register_cleanup "${TEMP_FILES[@]}"

# Decode environment variables
PM_SKILLS_REPO="$(b64_decode "${PM_SKILLS_REPO_B64:-}")"
PM_SKILLS_REF="$(b64_decode "${PM_SKILLS_REF_B64:-}")"
OPENCLAW_MODEL_PRIMARY="$(b64_decode "${OPENCLAW_MODEL_PRIMARY_B64:-}")"

OPENAI_API_KEY="$(b64_decode "${OPENAI_API_KEY_B64:-}")"
ANTHROPIC_API_KEY="$(b64_decode "${ANTHROPIC_API_KEY_B64:-}")"
OPENROUTER_API_KEY="$(b64_decode "${OPENROUTER_API_KEY_B64:-}")"

export OPENAI_API_KEY ANTHROPIC_API_KEY OPENROUTER_API_KEY

if ! command -v openclaw >/dev/null 2>&1; then
    echo '[openclawpm] Installing OpenClaw (skip onboard)...'
    download_with_retry "https://openclaw.ai/install.sh" 3 | bash -s -- --no-onboard
fi

# Repair PATH for current shell (Sprites uses nvm; installer warns PATH may be missing it)
repair_path

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

# Guardrail: OpenRouter auto model id
if [[ -n "${OPENROUTER_API_KEY:-}" && "${OPENCLAW_MODEL_PRIMARY:-}" == "openrouter/auto" ]]; then
    OPENCLAW_MODEL_PRIMARY="openrouter/openrouter/auto"
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

# Clone PM skills with retry
git_clone_with_retry "$PM_SKILLS_REPO" "/tmp/pmprompt-skills" "$PM_SKILLS_REF" 3

if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete /tmp/pmprompt-skills/skills/ "$WS/skills/"
else
    rm -rf "$WS/skills"/*
    cp -R /tmp/pmprompt-skills/skills/* "$WS/skills/"
fi

# Keep workspace context lean (large files increase prompt size dramatically)
cat > "$WS/AGENTS.md" <<'EOF'
# AGENTS.md

You are a product-management-focused assistant.

- Be practical and artifact-first.
- Prefer structured outputs.
- Ask minimal clarifying questions.
EOF

cat > "$WS/IDENTITY.md" <<'EOF'
# IDENTITY.md

- **Name:** Kramer
- **Creature:** AI assistant / product management collaborator
- **Vibe:** Sharp but approachable, artifact-first
EOF

echo "$SYS_PROMPT_B64" | base64 -d > "$WS/SOUL.md"

USER_CONTEXT_JSON="$(b64_decode "${USER_CONTEXT_B64:-}")"
ROLE="$(echo "$USER_CONTEXT_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("role","Product Manager"))' 2>/dev/null || echo 'Product Manager')"
PRODUCT="$(echo "$USER_CONTEXT_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("product",""))' 2>/dev/null || echo '')"
STAGE="$(echo "$USER_CONTEXT_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("stage","Growth"))' 2>/dev/null || echo 'Growth')"
INDUSTRY="$(echo "$USER_CONTEXT_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("industry",""))' 2>/dev/null || echo '')"
FOCUS="$(echo "$USER_CONTEXT_JSON" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("focus","B2B SaaS"))' 2>/dev/null || echo 'B2B SaaS')"

cat > "$WS/USER.md" <<EOF
# USER.md

- **Role:** ${ROLE}
- **Product:** ${PRODUCT}
- **Stage:** ${STAGE}
- **Industry:** ${INDUSTRY}
- **Focus:** ${FOCUS}

## Context
This user works on ${PRODUCT} in the ${FOCUS} space. They are a ${ROLE} at ${STAGE} stage.

## Preferred Artifacts
PRDs, decision memos, shaping pitches, stakeholder updates
EOF

# Remove bootstrap file so it doesn't get injected into the system prompt
rm -f "$WS/BOOTSTRAP.md" || true

openclaw gateway start || true
openclaw gateway status || true
