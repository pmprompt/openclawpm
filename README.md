# OpenClaw PM Agent

A beautifully designed TUI (Terminal User Interface) for product managers to set up and manage AI-powered product management workflows using OpenClaw.

## What is this?

OpenClaw PM Agent is a CLI tool that abstracts the complexity of setting up [OpenClaw](https://github.com/anthropics/openclaw) with opinionated workflows and product management context. It runs on [Sprites](https://docs.sprites.dev/) (Fly.io's persistent, hardware-isolated Linux environments) to provide each PM with their own isolated AI assistant.

### Key Features

- **One-command provisioning** - Spin up a complete PM agent environment in minutes
- **Beautiful TUI** - Terminal interface with intuitive commands and visual feedback
- **Product Management Skills** - Pre-configured with the [pmprompt Claude plugin](https://github.com/pmprompt/claude-plugin-product-management) for PM-specific workflows
- **Isolated environments** - Each user gets their own Sprite (microVM) with persistent storage
- **Chat interface** - Interactive chat with your PM agent for brainstorming, PRDs, and more

## Prerequisites

Before you begin, you'll need:

1. **Sprites CLI** - Install from [docs.sprites.dev](https://docs.sprites.dev/quickstart/)
2. **Fly.io account** - Sign up at [fly.io](https://fly.io) and authenticate with `fly auth login`
3. **API key** for one of these providers:
   - OpenAI API key
   - Anthropic API key
   - OpenRouter API key

## Installation

### Option 1: Download the PHAR (Recommended)

```bash
# Download the latest release
curl -L -o openclawpm https://github.com/pmprompt/openclawpm/releases/latest/download/openclawpm
chmod +x openclawpm

# Move to your PATH (optional)
mv openclawpm /usr/local/bin/
```

### Option 2: Build from Source

```bash
# Clone the repository
git clone https://github.com/pmprompt/openclawpm.git
cd openclawpm/cli

# Install dependencies
composer install

# Make executable
chmod +x openclawpm
```

## Quick Start

### 1. Check Your Environment

```bash
./openclawpm doctor --fix
```

This verifies that:
- Sprites CLI is installed
- You're authenticated with Fly.io
- Your `.env` file is properly configured

### 2. Set Up Environment Variables

```bash
cp .env.example .env
```

Edit `.env` and add your API key (only one needed):

```bash
# Pick one provider:
OPENAI_API_KEY=sk-...
# OR
ANTHROPIC_API_KEY=sk-ant-...
# OR
OPENROUTER_API_KEY=sk-or-...

# Optional: specify model (defaults to Claude Sonnet)
OPENCLAW_MODEL_PRIMARY=sonnet

# Optional: pin OpenClaw version (defaults to latest)
# Use "latest", "beta", or a specific version like "2026.2.15"
OPENCLAW_VERSION=latest
```

**Important**: Never commit your `.env` file to git. It's already in `.gitignore`.

### 3. Create Your PM Agent

```bash
./openclawpm provision my-pm-agent
```

This will:
- Create a new Sprite on Fly.io
- Install OpenClaw and configure it
- Set up the PM skills pack
- Start the gateway service

### 4. Start Chatting

```bash
./openclawpm chat my-pm-agent
```

You'll enter an interactive chat session with your PM agent. Try asking:

- "Help me write a PRD for a new feature"
- "What's the difference between OKRs and KPIs?"
- "Review this user interview transcript and extract insights"
- "/exit" to quit

### 5. Verify Everything is Working

```bash
./openclawpm verify my-pm-agent
```

### 6. Clean Up (When Done)

```bash
./openclawpm destroy my-pm-agent
```

## Available Commands

| Command | Description |
|---------|-------------|
| `doctor [--fix]` | Check environment and dependencies |
| `provision <name>` | Create and configure a new PM agent |
| `chat <name>` | Interactive chat with your agent |
| `verify <name>` | Verify agent health and configuration |
| `update <name> [--channel] [--version]` | Update OpenClaw to a newer version |
| `destroy <name> [--force]` | Remove a PM agent permanently |
| `reset-sprite-auth [--force]` | Reset Sprites authentication |
| `welcome` | Display the welcome screen |
| `list` | Show all available commands |

## Version Management

### Pinning OpenClaw Version

When provisioning a new agent, you can control which version of OpenClaw gets installed by setting `OPENCLAW_VERSION` in your `.env` file:

```bash
# Use the latest stable version (default)
OPENCLAW_VERSION=latest

# Use the beta channel for early access to features
OPENCLAW_VERSION=beta

# Pin to a specific version
OPENCLAW_VERSION=2026.2.15
```

### Updating an Existing Agent

To update OpenClaw on an existing Sprite:

```bash
# Update to latest version
./openclawpm update my-pm-agent

# Switch to beta channel
./openclawpm update my-pm-agent --channel beta

# Install a specific version
./openclawpm update my-pm-agent --version 2026.2.15

# Update without restarting the gateway
./openclawpm update my-pm-agent --no-restart
```

### Checking Installed Version

To see what version of OpenClaw is installed:

```bash
./openclawpm verify my-pm-agent
```

This will show the OpenClaw version along with other health checks.

## How It Works

### Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Your Machine  │────▶│   Sprites CLI    │────▶│   Fly.io Sprite │
│  (openclawpm)   │     │   (fly/sprite)   │     │  (microVM)      │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                                                          │
                                                          ▼
                                                  ┌─────────────────┐
                                                  │   OpenClaw +    │
                                                  │   PM Skills     │
                                                  └─────────────────┘
```

1. **Local CLI** (`openclawpm`) - Your interface for managing agents
2. **Sprites** - Fly.io's infrastructure for isolated, persistent environments
3. **OpenClaw** - AI agent runtime with gateway and skills system
4. **PM Skills** - Product management workflows from [pmprompt](https://github.com/pmprompt/claude-plugin-product-management)

### Per-User Isolation

Each PM agent runs in its own Sprite with:
- **Hardware isolation** via microVMs (not just containers)
- **Persistent storage** that survives restarts
- **Instant wake** from hibernation (no cold starts)
- **Per-second billing** - compute is free when idle

## Contributing

We welcome contributions! Here's how to get started:

### Development Setup

```bash
# Clone the repo
git clone https://github.com/pmprompt/openclawpm.git
cd openclawpm

# Install PHP dependencies
cd cli && composer install

# Run tests
vendor/bin/pest

# Check code style
vendor/bin/pint
```

### Project Structure

```
openclawpm/
├── cli/                    # Laravel Zero CLI application
│   ├── app/Commands/       # CLI commands
│   ├── app/Support/        # Helper classes
│   └── tests/              # Pest tests
├── scripts/                # Bash provisioning scripts
│   ├── provision_sprite.sh
│   ├── destroy_sprite.sh
│   └── lib/sprite_utils.sh
├── prompts/                # System prompts for agents
├── skills/                 # Claude skills for development
│   ├── skill-creator/
│   └── review-and-release/
└── AGENTS.md              # Developer documentation
```

### Development Workflow

1. **Create a feature branch**: `git checkout -b feature/my-feature`
2. **Make your changes** with tests if applicable
3. **Run the review-and-release skill**: This will:
   - Run code quality checks
   - Execute tests
   - Update documentation
   - Create a PR
   - Merge and tag the release

### Guidelines

- **No secrets** - Never commit API keys, tokens, or private URLs
- **Idempotent scripts** - Provisioning scripts must be safe to re-run
- **Small commits** - Prefer reviewable, focused changes
- **Document env vars** - Add required variables to `.env.example`

## Related Projects

- **[Claude Plugin for Product Management](https://github.com/pmprompt/claude-plugin-product-management)** - The PM skills pack that powers the agent's product management capabilities
- **[OpenClaw](https://github.com/anthropics/openclaw)** - The AI agent runtime by Anthropic
- **[Sprites](https://docs.sprites.dev/)** - Persistent, hardware-isolated environments by Fly.io

## Roadmap

- [x] **MVP1**: CLI provisioning, OpenClaw installation, skills setup
- [ ] **MVP2**: HTTP bridge service for web-based chat interface
- [ ] **MVP3**: Context profiles and artifact persistence
- [ ] **Future**: SaaS backend for team collaboration

## Support

- **Issues**: [GitHub Issues](https://github.com/pmprompt/openclawpm/issues)
- **Discussions**: [GitHub Discussions](https://github.com/pmprompt/openclawpm/discussions)
- **Documentation**: See `AGENTS.md` for developer docs

## License

MIT License - see [LICENSE](LICENSE) for details.

---

Made with ❤️ for product managers who want AI superpowers.
