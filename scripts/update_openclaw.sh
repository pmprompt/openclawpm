#!/usr/bin/env bash
set -euo pipefail

# Update OpenClaw on an existing Sprite.
# Usage:
#   ./scripts/update_openclaw.sh --name pm-agent-test [--channel stable|beta|dev] [--version 2026.2.15] [--no-restart] [--verbose]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/sprite_utils.sh"

NAME=""
CHANNEL=""
VERSION=""
NO_RESTART=false
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) NAME="$2"; shift 2;;
        --channel) CHANNEL="$2"; shift 2;;
        --version) VERSION="$2"; shift 2;;
        --no-restart) NO_RESTART=true; shift;;
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

echo "🔄 Updating OpenClaw on sprite: $NAME"
[[ "$VERBOSE" == true ]] && echo "[verbose] Channel: ${CHANNEL:-current}, Version: ${VERSION:-latest}"

# Check if sprite exists
if ! sprite list 2>/dev/null | grep -q "^${NAME}$"; then
    echo "❌ Error: Sprite '$NAME' not found" >&2
    echo "Run 'sprite list' to see available sprites" >&2
    exit 1
fi

# Build the update command for inside the sprite
UPDATE_CMD="openclaw update"

if [[ -n "$VERSION" ]]; then
    UPDATE_CMD="$UPDATE_CMD --version $VERSION"
elif [[ -n "$CHANNEL" ]]; then
    UPDATE_CMD="$UPDATE_CMD --channel $CHANNEL"
fi

if [[ "$NO_RESTART" == true ]]; then
    UPDATE_CMD="$UPDATE_CMD --no-restart"
fi

echo ""
echo "[1/3] 🛑 Stopping gateway..."
sprite exec -s "$NAME" bash -c "openclaw gateway stop 2>/dev/null || true"

echo ""
echo "[2/3] ⬆️  Running update..."
sprite exec -s "$NAME" bash -c "
set -euo pipefail

# Repair PATH for OpenClaw
NPM_BIN=\"\$(npm bin -g 2>/dev/null || true)\"
NPM_PREFIX=\"\$(npm config get prefix 2>/dev/null || true)\"

if [[ -n \"\$NPM_BIN\" && -d \"\$NPM_BIN\" ]]; then
    export PATH=\"\$NPM_BIN:\$PATH\"
fi
if [[ -n \"\$NPM_PREFIX\" && -d \"\$NPM_PREFIX/bin\" ]]; then
    export PATH=\"\$NPM_PREFIX/bin:\$PATH\"
fi

# Try to find node installation dynamically
NODE_BIN_DIR=\"\$(find /.sprite/languages/node/nvm/versions/node -name 'bin' -type d 2>/dev/null | head -1 || true)\"
if [[ -n \"\$NODE_BIN_DIR\" && -d \"\$NODE_BIN_DIR\" ]]; then
    export PATH=\"\$NODE_BIN_DIR:\$PATH\"
fi

export PATH=\"\$HOME/.local/bin:\$PATH\"
hash -r

echo 'Current version:'
openclaw --version

echo ''
echo 'Running: $UPDATE_CMD'
$UPDATE_CMD

echo ''
echo 'Updated version:'
openclaw --version
"

echo ""
echo "[3/3] 🚀 Starting gateway..."
if [[ "$NO_RESTART" == false ]]; then
    sprite exec -s "$NAME" bash -c "
set -euo pipefail

# Repair PATH
NPM_BIN=\"\$(npm bin -g 2>/dev/null || true)\"
NPM_PREFIX=\"\$(npm config get prefix 2>/dev/null || true)\"
if [[ -n \"\$NPM_BIN\" && -d \"\$NPM_BIN\" ]]; then export PATH=\"\$NPM_BIN:\$PATH\"; fi
if [[ -n \"\$NPM_PREFIX\" && -d \"\$NPM_PREFIX/bin\" ]]; then export PATH=\"\$NPM_PREFIX/bin:\$PATH\"; fi
NODE_BIN_DIR=\"\$(find /.sprite/languages/node/nvm/versions/node -name 'bin' -type d 2>/dev/null | head -1 || true)\"
if [[ -n \"\$NODE_BIN_DIR\" && -d \"\$NODE_BIN_DIR\" ]]; then export PATH=\"\$NODE_BIN_DIR:\$PATH\"; fi
export PATH=\"\$HOME/.local/bin:\$PATH\"
hash -r

openclaw gateway start 2>/dev/null || true
openclaw gateway status 2>/dev/null || echo 'Gateway status check skipped'
"
else
    echo "[openclawpm] Skipping gateway restart (--no-restart)"
fi

echo ""
echo "✅ Update complete!"
