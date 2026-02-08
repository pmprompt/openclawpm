#!/usr/bin/env bash
set -euo pipefail

# Stub: Destroy a Sprite by name
# Usage:
#   ./scripts/destroy_sprite.sh --name pm-agent-test

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

echo "Destroying sprite: $NAME"
sprite destroy -s "$NAME"
