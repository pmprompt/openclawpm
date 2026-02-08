# openclawpm

Provisioning + automation scaffolding for a **Product-Management-focused OpenClaw agent runtime**, intended to run in **Sprites** (Fly) as a per-user isolated environment.

## What this repo is

This repo will contain scripts to:

- provision a new Sprite per user
- install / configure OpenClaw non-interactively (no wizard)
- install the pmprompt PM skills pack
- configure a chat surface (prototype: Telegram; later: bridge service + agent.pmprompt.com)

## What this repo is not

- No secrets. Do **not** commit API keys, cookies, tokens, chat IDs, private URLs.

## Quickstart

### CLI (Laravel Zero)

From `cli/`:

```bash
composer install
./openclawpm list
./openclawpm provision pm-agent-test
./openclawpm verify pm-agent-test
./openclawpm destroy pm-agent-test
```

### Scripts (direct)



1) Install Sprites CLI (see docs): https://docs.sprites.dev/quickstart/
2) Authenticate with Fly/Sprites.
3) Copy env example:

```bash
cp .env.example .env
# fill env vars in your shell, not in git
```

4) Provision a test agent:

```bash
./scripts/provision_sprite.sh --name pm-agent-test
```

5) Destroy it when done:

```bash
./scripts/destroy_sprite.sh --name pm-agent-test
```

## Required environment variables

See `.env.example`.

## Roadmap (thin slices)

- [ ] MVP1: provision Sprite + install OpenClaw + install skills + Telegram chat works
- [ ] MVP2: add HTTP bridge service (customer-safe) and route via agent.pmprompt.com
- [ ] MVP3: context profiles injection (context pack) + artifacts persistence

## License

MIT (see `LICENSE`).
