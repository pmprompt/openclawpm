# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added

- **Skills Framework**: Added Claude skills support with skill-creator and review-and-release skills
  - `skills/skill-creator/` - Tool for creating new skills with proper structure
  - `skills/review-and-release/` - Unified workflow for code review, testing, documentation, and release automation
- **New CLI Commands**
  - `welcome` command displays the OpenClaw PM Agent welcome screen with quick start guide
- **New Welcome Command**: Beautiful TUI welcome screen showing available commands and examples

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

- **Code Quality**: Applied Laravel Pint code style fixes across all PHP files
- **Documentation**: Added skill usage instructions to AGENTS.md

## [0.1.0] - 2025-02-08

### Added

- Initial OpenClaw PM Agent CLI implementation
- Core commands: `provision`, `destroy`, `verify`, `chat`, `doctor`, `reset-sprite-auth`
- Sprite provisioning and management via bash scripts
- Integration with OpenClaw gateway and PM skills
- Environment preflight checks (`EnvPreflight`)
- System prompt for product management context
