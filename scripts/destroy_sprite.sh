#!/usr/bin/env bash
set -euo pipefail

# Destroy a Sprite by name.
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

# Validate sprite name
validate_sprite_name "$NAME" || exit 1

if [[ "$FORCE" != true ]]; then
    echo "⚠️  This will permanently destroy sprite: $NAME"
    read -p "Are you sure? [y/N] " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 0
    fi
fi

echo "🗑️  Destroying sprite: $NAME"
sprite destroy "$NAME"
echo "✅ Sprite destroyed: $NAME"
