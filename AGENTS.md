# AGENTS.md

How agents (and humans) should work in this repo.

## Infrastructure: Sprites

This project is built on **Sprites** ([docs.sprites.dev](https://docs.sprites.dev/)) - persistent, hardware-isolated Linux environments by Fly.io.

### Why Sprites?
- **Persistent state** between runs (unlike serverless functions)
- **Hardware-level isolation** via microVMs (not just containers)
- **Instant wake** from hibernation - no cold starts
- **Per-second billing** - compute is free when idle
- **Full Linux environment** - install any tools (Node, Python, etc.)

### Key Concepts
- **Sprites** = isolated environments where OpenClaw agents run
- **Per-user isolation** = each PM gets their own Sprite
- **Idle/Sleep/Wake cycle** = Sprites hibernate when inactive, wake on next request
- **Persistent filesystem** = ext4 storage survives restarts

### CLI Commands We Use
```bash
sprite create <name>     # Create a new Sprite
sprite use <name>        # Set default Sprite for commands
sprite exec -s <name>    # Execute commands inside a Sprite
sprite destroy <name>    # Delete a Sprite and all its data
```

## Project Context

**OpenClaw PM Agent** - A beautifully designed TUI (and eventual SaaS backend) that abstracts the complexity of setting up OpenClaw with opinionated workflows and added context for product managers.

### Vision
- **Current**: Terminal-based UI for PMs to configure and manage OpenClaw deployments
- **Future**: SaaS backend providing opinionated OpenClaw management

### Target Users
- Product Managers who need OpenClaw setup without deep technical knowledge
- Teams wanting streamlined, opinionated OpenClaw workflows

## Non-negotiables

- **No secrets**: never commit API keys, tokens, cookies, chat IDs, or private URLs.
- **Idempotent scripts**: provisioning scripts must be safe to re-run.
- **Explicit outputs**: scripts should print next-step commands and health checks.

## Dev workflow

- Prefer small, reviewable commits.
- Keep provider-specific logic behind flags.
- Document required env vars in `.env.example`.
- Use skills via the skill tool (located in `.agents/skills/`)

## Release Workflow

**IMPORTANT**: Do NOT deploy changes until explicitly instructed to run the review-and-release skill.

When changes are ready for release:
1. User must explicitly say "run the review-and-release skill" or similar
2. The review-and-release skill will perform:
   - Code review and quality checks
   - Testing
   - Documentation updates
   - Git workflow (branch, commit, PR)
3. Only then should deployment occur

This ensures all changes go through proper review before reaching production.

## Security

- Treat per-user Sprites as untrusted boundaries.
- Do not expose OpenClaw Control UI publicly.
- Restrict channels to known chat IDs during MVP.
