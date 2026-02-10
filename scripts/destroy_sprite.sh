#!/usr/bin/env bash
set -euo pipefail

# Destroy an Agent by name.
# Usage:
#   ./scripts/destroy_sprite.sh --name pm-agent-test [--force]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/lib/sprite_utils.sh"

NAME=""
FORCE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name) NAME="$2"; shift 2;;
        --force) FORCE=true; shift;;
        *) echo "Unknown arg: $1"; exit 1;;
    esac
done

if [[ -z "$NAME" ]]; then
    echo "Missing --name" >&2
    exit 1
fi

# Validate agent name
validate_sprite_name "$NAME" || exit 1

# Note: Confirmation is handled by the PHP CLI

echo "🗑️  Destroying agent: $NAME"
sprite destroy "$NAME"
echo "✅ Agent destroyed: $NAME"
