# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- **OpenClaw Version Management** - Full control over OpenClaw versions during provisioning and updates
  - `OPENCLAW_VERSION` environment variable in `.env.example` (defaults to "latest")
  - Version pinning support: "latest", "beta", or specific version like "2026.2.15"
  - New `update` CLI command for updating OpenClaw on existing Sprites
  - New `update_openclaw.sh` script for in-place version updates with channel/version options
  - Enhanced `verify` command now displays installed OpenClaw version
  - Updated `bootstrap_in_sprite.sh` and `provision_sprite.sh` to support version pinning
  - Added version management documentation to README.md with examples

### Fixed

- **Warmup now initializes OpenClaw agent** - Previous warmup only woke the VM (1.7s) but OpenClaw init happened on first chat (20s). Now warmup runs `openclaw agent --local` to pre-initialize sessions, skills, and model client, making first chat instant.
- **Warmup command** - Added `--init` flag for full agent initialization (default: verify only, `--ping`: VM only, `--init`: full init)

### Added

- **Chat Performance Optimizations**
  - **Dynamic thinking levels**: First message uses `minimal` thinking for faster response (~30-50% speedup), subsequent messages use user's `--thinking` preference
  - **Background session initialization**: Session warmup runs while user reads welcome message, overlapping wait time with user input
  - **Session init indicator**: Shows "● Preparing session... (runs in background)" status
  - **VM hibernation resilience**: If Sprite sleeps during session init, first message performs brief warmup (~5-10s instead of 20s)
- **Skills Framework**: Added Claude skills support with skill-creator and review-and-release skills
  - `skills/skill-creator/` - Tool for creating new skills with proper structure
  - `skills/review-and-release/` - Unified workflow for code review, testing, documentation, and release automation
- **New CLI Commands**
  - `welcome` command displays the OpenClaw PM Agent welcome screen with quick start guide
  - `warmup` command to wake up agents and reduce first-message latency
- **New Welcome Command**: Beautiful TUI welcome screen showing available commands and examples
- **Chat Improvements**
  - Automatic warmup on chat launch (reduces first-message latency from ~17s to ~2s)
  - Timing diagnostics with `--debug` flag showing model generation time vs total time
  - Copy commands (`/copy`, `/copy-md`) to extract and copy artifacts to clipboard
- **Model Selection**: Enhanced setup flow showing curated models from `models.json` with descriptions, speed, and cost info

### Changed

- **CLI Commands**: Enhanced existing commands with consistent formatting and validation
  - All sprite name validation now uses shared `validate_sprite_name()` function
  - Improved error messages with emoji indicators
  - Added `--verbose` flag support across commands
- **Scripts**: Refactored bash scripts for better reusability
  - Extracted common utilities into `scripts/lib/sprite_utils.sh`
  - All scripts now use consistent error handling with `set -euo pipefail`
  - Added retry logic for network operations

### Improved

- **Welcome Screen UI** - Cleaner pixel art border using single-line box-drawing characters and removed bold styling for better readability
- **Quick Start Commands** - Added `update` command to the welcome screen quick start list
- **Code Quality**: Applied Laravel Pint code style fixes across all PHP files
- **Documentation**:
  - Added skill usage instructions to AGENTS.md
  - Completely rewrote README.md with human-friendly documentation, installation guide, quick start, and contributing guidelines
- **UX Improvements**
  - Replaced "Sprite" terminology with "Agent" throughout CLI for clarity
  - Simplified destroy confirmation prompts (removed duplicate confirmations)
  - Welcome screen now shows active agents with green indicators
  - Reduced auth check verbosity during command execution

## [0.1.0] - 2025-02-08

### Added

- Initial OpenClaw PM Agent CLI implementation
- Core commands: `provision`, `destroy`, `verify`, `chat`, `doctor`, `reset-sprite-auth`
- Sprite provisioning and management via bash scripts
- Integration with OpenClaw gateway and PM skills
- Environment preflight checks (`EnvPreflight`)
- System prompt for product management context
