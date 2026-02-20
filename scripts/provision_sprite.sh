#!/usr/bin/env bash
set -euo pipefail

# Provision a Sprite and bootstrap OpenClaw + pmprompt skills.
#
# Usage:
#   ./scripts/provision_sprite.sh --name pm-agent-test [--verbose]
#
# Required env:
#   - One provider key: OPENAI_API_KEY or ANTHROPIC_API_KEY or OPENROUTER_API_KEY
# Optional env:
#   - OPENCLAW_MODEL_PRIMARY (e.g. sonnet)
#   - PM_SKILLS_REPO (default pmprompt/claude-plugin-product-management)
#   - PM_SKILLS_REF (default main)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/sprite_utils.sh"

NAME=""
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) NAME="$2"; shift 2;;
        --verbose) VERBOSE=true; shift;;
        *) echo "Unknown arg: $1"; exit 1;;
    esac
done

if [[ -z "$NAME" ]]; then
    echo "Missing --name" >&2
    exit 1
fi

# Validate sprite name
validate_sprite_name "$NAME" || exit 1

if [[ -z "${OPENAI_API_KEY:-}" && -z "${ANTHROPIC_API_KEY:-}" && -z "${OPENROUTER_API_KEY:-}" ]]; then
    echo "Missing OPENAI_API_KEY or ANTHROPIC_API_KEY or OPENROUTER_API_KEY (set one)." >&2
    exit 1
fi

if [[ -z "${OPENCLAW_GATEWAY_TOKEN:-}" ]]; then
    OPENCLAW_GATEWAY_TOKEN="$(python3 -c 'import secrets; print(secrets.token_hex(32))')"
    export OPENCLAW_GATEWAY_TOKEN
    [[ "$VERBOSE" == true ]] && echo "[verbose] Generated gateway token"
fi

PM_SKILLS_REPO="${PM_SKILLS_REPO:-https://github.com/pmprompt/claude-plugin-product-management.git}"
PM_SKILLS_REF="${PM_SKILLS_REF:-main}"

echo "🚀 Provisioning OpenClaw PM Agent"
echo "   Sprite: $NAME"
echo "   Skills: $PM_SKILLS_REPO ($PM_SKILLS_REF)"
[[ "$VERBOSE" == true ]] && echo "[verbose] Using model: ${OPENCLAW_MODEL_PRIMARY:-default}"

SYS_PROMPT_B64="$(b64_encode < "$SCRIPT_DIR/../prompts/system-prompt-pm.txt")"

OPENAI_API_KEY_B64="$(printf %s "${OPENAI_API_KEY:-}" | b64_encode)"
ANTHROPIC_API_KEY_B64="$(printf %s "${ANTHROPIC_API_KEY:-}" | b64_encode)"
OPENROUTER_API_KEY_B64="$(printf %s "${OPENROUTER_API_KEY:-}" | b64_encode)"

OPENCLAW_MODEL_PRIMARY_B64="$(printf %s "${OPENCLAW_MODEL_PRIMARY:-}" | b64_encode)"
OPENCLAW_VERSION_B64="$(printf %s "${OPENCLAW_VERSION:-latest}" | b64_encode)"
PM_SKILLS_REPO_B64="$(printf %s "$PM_SKILLS_REPO" | b64_encode)"
PM_SKILLS_REF_B64="$(printf %s "$PM_SKILLS_REF" | b64_encode)"
USER_CONTEXT_B64="${USER_CONTEXT_B64:-}"

BOOTSTRAP_B64="$(b64_encode < "$SCRIPT_DIR/bootstrap_in_sprite.sh")"

echo ""
echo "[1/3] 📦 Creating sprite: $NAME"

# Check if sprite already exists
[[ "$VERBOSE" == true ]] && echo "[verbose] Checking if sprite exists: $NAME"
[[ "$VERBOSE" == true ]] && sprite list 2>&1 | head -20

if sprite list 2>/dev/null | grep -q "^${NAME}$"; then
    echo "[openclawpm] Sprite already exists: $NAME"
else
    # Try to create the sprite
    echo "[openclawpm] Creating new sprite: $NAME"
    if ! sprite create "$NAME" 2>&1; then
        echo "[openclawpm] Warning: sprite create failed, checking if it was created anyway..."
        # Double-check if it was created despite the error
        if ! sprite list 2>/dev/null | grep -q "^${NAME}$"; then
            echo "❌ Error: Sprite does not exist and could not be created" >&2
            echo "The API error (400) may indicate an invalid name or authentication issue." >&2
            exit 1
        fi
    fi
fi

# Verify sprite exists before proceeding
[[ "$VERBOSE" == true ]] && echo "[verbose] Verifying sprite exists in list..."
[[ "$VERBOSE" == true ]] && sprite list 2>&1 | grep "^${NAME}$" || echo "[verbose] Sprite not found in list"

if ! sprite list 2>/dev/null | grep -q "^${NAME}$"; then
    echo "❌ Error: Sprite '$NAME' not found in sprite list" >&2
    [[ "$VERBOSE" == true ]] && echo "[verbose] Available sprites:" && sprite list 2>&1
    exit 1
fi

echo "[openclawpm] ✓ Sprite verified: $NAME"

echo ""
echo "[2/3] ⚙️  Installing OpenClaw + PM skills (this may take a few minutes)"
[[ "$VERBOSE" == true ]] && echo "[verbose] Bootstrapping inside Sprite $NAME..."

# Verify sprite is accessible before exec (with retry)
[[ "$VERBOSE" == true ]] && echo "[verbose] Verifying sprite is accessible..."

SPRITE_READY=false
for i in 1 2 3 4 5; do
    if sprite exec -s "$NAME" bash -c "echo 'Sprite accessible'" >/dev/null 2>&1; then
        SPRITE_READY=true
        break
    fi
    echo "[openclawpm] Waiting for sprite to be ready... (attempt $i/5)"
    sleep 2
done

if [[ "$SPRITE_READY" != true ]]; then
    echo "❌ Error: Cannot execute commands in sprite '$NAME'" >&2
    echo "The sprite may still be initializing or there may be an authentication issue." >&2
    [[ "$VERBOSE" == true ]] && sprite exec -s "$NAME" bash -c "echo 'test'" 2>&1
    exit 1
fi

# Write bootstrap script + env into sprite, then run it. This avoids fragile nested quoting.
sprite exec -s "$NAME" bash -s <<EOS
set -euo pipefail

[[ "$VERBOSE" == true ]] && echo "[verbose] Decoding bootstrap script..."
printf '%s' "$BOOTSTRAP_B64" | base64 -d > /tmp/openclawpm_bootstrap_in_sprite.sh
chmod +x /tmp/openclawpm_bootstrap_in_sprite.sh

cat > /tmp/openclawpm_env <<EOF
OPENCLAW_GATEWAY_TOKEN=$OPENCLAW_GATEWAY_TOKEN
SYS_PROMPT_B64=$SYS_PROMPT_B64
PM_SKILLS_REPO_B64=$PM_SKILLS_REPO_B64
PM_SKILLS_REF_B64=$PM_SKILLS_REF_B64
OPENCLAW_MODEL_PRIMARY_B64=$OPENCLAW_MODEL_PRIMARY_B64
OPENCLAW_VERSION_B64=$OPENCLAW_VERSION_B64
OPENAI_API_KEY_B64=$OPENAI_API_KEY_B64
ANTHROPIC_API_KEY_B64=$ANTHROPIC_API_KEY_B64
OPENROUTER_API_KEY_B64=$OPENROUTER_API_KEY_B64
USER_CONTEXT_B64=$USER_CONTEXT_B64
VERBOSE=$VERBOSE
EOF

set -a
source /tmp/openclawpm_env
set +a

bash /tmp/openclawpm_bootstrap_in_sprite.sh
EOS

echo ""
echo "[3/3] ✅ Setup complete!"
echo ""
echo "Next steps:"
echo "  💬 Chat:   ./openclawpm chat $NAME"
echo "  🔍 Verify: ./openclawpm verify $NAME"
echo "  🖥️  Console: sprite console -s $NAME"
echo "  🗑️  Remove: ./openclawpm destroy $NAME"
echo ""
