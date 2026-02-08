# AGENTS.md

How agents (and humans) should work in this repo.

## Non-negotiables

- **No secrets**: never commit API keys, tokens, cookies, chat IDs, or private URLs.
- **Idempotent scripts**: provisioning scripts must be safe to re-run.
- **Explicit outputs**: scripts should print next-step commands and health checks.

## Dev workflow

- Prefer small, reviewable commits.
- Keep provider-specific logic behind flags.
- Document required env vars in `.env.example`.

## Security

- Treat per-user Sprites as untrusted boundaries.
- Do not expose OpenClaw Control UI publicly.
- Restrict channels to known chat IDs during MVP.
