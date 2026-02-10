#!/usr/bin/env bash
# Shared utilities for OpenClaw PM Agent scripts
# Source this file: source "$(dirname "$0")/../lib/sprite_utils.sh"

set -euo pipefail

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

# Retry a command with exponential backoff
retry_with_backoff() {
    local max_attempts="${1:-3}"
    local delay="${2:-2}"
    shift 2
    local attempt=1
    
    while [[ $attempt -le $max_attempts ]]; do
        if "$@"; then
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

# Download with retry
download_with_retry() {
    local url="$1"
    local max_attempts="${2:-3}"
    retry_with_backoff "$max_attempts" 2 curl --retry "$max_attempts" --retry-delay 2 --retry-max-time 30 -fsSL "$url"
}

# Git clone with retry
git_clone_with_retry() {
    local repo="$1"
    local dest="$2"
    local ref="${3:-main}"
    local max_attempts="${4:-3}"
    
    retry_with_backoff "$max_attempts" 2 git clone --depth 1 --branch "$ref" "$repo" "$dest"
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

# Validate sprite name (lowercase alphanumeric and hyphens only - no underscores)
# Sprites API only allows: lowercase letters, numbers, and hyphens
validate_sprite_name() {
    local name="$1"
    if [[ -z "$name" ]]; then
        echo "Error: Sprite name cannot be empty" >&2
        return 1
    fi
    # Sprites naming convention: lowercase alphanumeric and hyphens only
    if [[ ! "$name" =~ ^[a-z0-9-]+$ ]]; then
        echo "Error: Sprite name must be lowercase alphanumeric with hyphens only" >&2
        echo "       Example: pm-agent-test (NOT pm_agent_test)" >&2
        return 1
    fi
    if [[ ${#name} -gt 63 ]]; then
        echo "Error: Sprite name too long (max 63 characters)" >&2
        return 1
    fi
    # Cannot start or end with hyphen
    if [[ "$name" == -* || "$name" == *- ]]; then
        echo "Error: Sprite name cannot start or end with a hyphen" >&2
        return 1
    fi
    return 0
}

# Base64 encode without newlines
b64_encode() {
    base64 | tr -d '\n' | tr -d '\r'
}

# Base64 decode
b64_decode() {
    local v="${1:-}"
    if [[ -z "$v" ]]; then
        printf ''
        return 0
    fi
    printf '%s' "$v" | base64 -d
}

export -f repair_path retry_with_backoff download_with_retry git_clone_with_retry cleanup_temp_files register_cleanup validate_sprite_name b64_encode b64_decode
