<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;

class EnvPreflight
{
    public static function ensureSpriteCliInstalled(bool $fix = false): void
    {
        $code = 0;
        @passthru('command -v sprite >/dev/null 2>&1', $code);
        if ($code === 0) {
            return;
        }

        $msg = "Sprites CLI (sprite) not found. Install it with:\n  curl -fsSL https://sprites.dev/install.sh | sh\nThen restart your shell (ensure ~/.local/bin is on PATH), and run:\n  sprite org auth";

        if (! $fix) {
            error($msg);
            throw new \RuntimeException('Missing dependency: sprite');
        }

        if (! confirm('Sprites CLI not found. Install it now via the official install script?', default: false)) {
            error($msg);
            throw new \RuntimeException('Missing dependency: sprite');
        }

        \Laravel\Prompts\info('Installing Sprites CLI...');
        $installCode = 0;
        passthru('curl -fsSL https://sprites.dev/install.sh | sh', $installCode);
        if ($installCode !== 0) {
            throw new \RuntimeException('Failed to install Sprites CLI. See output above.');
        }

        \Laravel\Prompts\info('Installed. Ensure ~/.local/bin is on your PATH, then re-run this command.');
        throw new \RuntimeException('Sprites CLI installed; restart shell and run again.');
    }

    public static function ensureGitignoreHasDotEnvPublic(string $repoRoot): void
    {
        self::ensureGitignoreHasDotEnv($repoRoot);
    }

    public static function resetSpriteAuth(bool $force = false): void
    {
        $home = rtrim((string) getenv('HOME'), '/');
        $candidates = [
            $home.'/.config/sprite',
            $home.'/.config/sprites',
            $home.'/.sprites',
            $home.'/.cache/sprite',
            $home.'/.cache/sprites',
        ];

        $existing = array_values(array_filter($candidates, fn ($p) => is_dir($p) || is_file($p)));

        if (empty($existing)) {
            error("No obvious Sprites auth/config paths found. If you're still stuck, run: sprite org auth");
            return;
        }

        \Laravel\Prompts\info("Found Sprites-related paths:\n- ".implode("\n- ", $existing));

        if (! $force) {
            $ok = confirm('Delete these local paths to reset Sprites auth?', default: false);
            if (! $ok) {
                error('Aborted.');
                return;
            }
        }

        foreach ($existing as $path) {
            self::rmrf($path);
        }

        \Laravel\Prompts\info('Removed local Sprites auth/config.');
    }

    private static function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) return;

        $items = array_diff(scandir($path) ?: [], ['.','..']);
        foreach ($items as $item) {
            self::rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }

    /**
     * Ensure required env vars exist for provisioning.
     *
     * Strategy:
     * - Prefer existing process env.
     * - If missing, prompt interactively.
     * - Optionally persist to repo-root .env (gitignored).
     */
    public static function forProvisioning(string $repoRoot): array
    {
        // We keep values in-memory and optionally write .env
        $env = [
            'OPENAI_API_KEY' => getenv('OPENAI_API_KEY') ?: null,
            'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: null,
            'OPENROUTER_API_KEY' => getenv('OPENROUTER_API_KEY') ?: null,
            'OPENCLAW_MODEL_PRIMARY' => getenv('OPENCLAW_MODEL_PRIMARY') ?: null,
            'PM_SKILLS_REPO' => getenv('PM_SKILLS_REPO') ?: 'https://github.com/pmprompt/claude-plugin-product-management.git',
            'PM_SKILLS_REF' => getenv('PM_SKILLS_REF') ?: 'main',
        ];

        // Validate provider key choice
        $hasOpenAI = ! empty($env['OPENAI_API_KEY']);
        $hasAnthropic = ! empty($env['ANTHROPIC_API_KEY']);
        $hasOpenRouter = ! empty($env['OPENROUTER_API_KEY']);

        $count = (int) $hasOpenAI + (int) $hasAnthropic + (int) $hasOpenRouter;
        if ($count > 1) {
            $choice = select(
                label: 'Multiple model API keys are set. Which provider should we use for this run?',
                options: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'openrouter' => 'OpenRouter'],
                default: $hasAnthropic ? 'anthropic' : ($hasOpenAI ? 'openai' : 'openrouter')
            );
            $env['OPENAI_API_KEY'] = $choice === 'openai' ? $env['OPENAI_API_KEY'] : null;
            $env['ANTHROPIC_API_KEY'] = $choice === 'anthropic' ? $env['ANTHROPIC_API_KEY'] : null;
            $env['OPENROUTER_API_KEY'] = $choice === 'openrouter' ? $env['OPENROUTER_API_KEY'] : null;
        }

        if (! $env['OPENAI_API_KEY'] && ! $env['ANTHROPIC_API_KEY'] && ! $env['OPENROUTER_API_KEY']) {
            $provider = select(
                label: 'No model API key found. Which provider do you want to use for this run?',
                options: ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'openrouter' => 'OpenRouter'],
                default: 'anthropic'
            );

            if ($provider === 'openai') {
                $env['OPENAI_API_KEY'] = password('Enter OPENAI_API_KEY (input hidden)');
            } elseif ($provider === 'openrouter') {
                $env['OPENROUTER_API_KEY'] = password('Enter OPENROUTER_API_KEY (input hidden)');
            } else {
                $env['ANTHROPIC_API_KEY'] = password('Enter ANTHROPIC_API_KEY (input hidden)');
            }
        }

        // Model selection (short names w/ magic mapping)
        if ($env['OPENCLAW_MODEL_PRIMARY'] === null) {
            $provider = ! empty($env['OPENROUTER_API_KEY']) ? 'openrouter'
                : (! empty($env['ANTHROPIC_API_KEY']) ? 'anthropic'
                : (! empty($env['OPENAI_API_KEY']) ? 'openai' : 'unknown'));

            $picked = null;
            if ($provider === 'openrouter') {
                $picked = select(
                    label: 'Pick a primary model (OpenRouter)',
                    options: [
                        'auto' => 'Auto (recommended) — OpenRouter picks a cost-effective model',
                        'sonnet' => 'Claude Sonnet 4.5 (quality default)',
                        'haiku' => 'Claude Haiku 3.5 (cheap + fast)',
                        'deepseek' => 'DeepSeek Chat',
                        'gemini' => 'Gemini Pro 1.5',
                        'kimi' => 'Kimi K2.5',
                        'custom' => 'Custom…',
                    ],
                    default: 'auto'
                );
            } elseif ($provider === 'anthropic') {
                $picked = select(
                    label: 'Pick a primary model (Anthropic)',
                    options: [
                        'sonnet' => 'Sonnet (recommended)',
                        'haiku' => 'Haiku (cheap + fast)',
                        'custom' => 'Custom…',
                    ],
                    default: 'sonnet'
                );
            } elseif ($provider === 'openai') {
                $picked = select(
                    label: 'Pick a primary model (OpenAI)',
                    options: [
                        'codex' => 'Codex GPT-5.2 (default)',
                        'custom' => 'Custom…',
                    ],
                    default: 'codex'
                );
            }

            $map = [
                // OpenRouter
                'openrouter:auto' => 'openrouter/openrouter/auto',
                'openrouter:sonnet' => 'openrouter/anthropic/claude-sonnet-4.5',
                'openrouter:haiku' => 'openrouter/anthropic/claude-haiku-3.5',
                'openrouter:deepseek' => 'openrouter/deepseek/deepseek-chat',
                'openrouter:gemini' => 'openrouter/google/gemini-pro-1.5',
                'openrouter:kimi' => 'openrouter/moonshotai/kimi-k2.5',

                // Anthropic
                'anthropic:sonnet' => 'sonnet',
                'anthropic:haiku' => 'anthropic/claude-haiku-4-5',

                // OpenAI
                'openai:codex' => 'openai-codex/gpt-5.2',
            ];

            if ($picked === 'custom') {
                $env['OPENCLAW_MODEL_PRIMARY'] = text(
                    label: 'OPENCLAW_MODEL_PRIMARY',
                    placeholder: $provider === 'openrouter'
                        ? 'openrouter/<author>/<slug> (e.g. openrouter/openrouter/auto)'
                        : 'model id or alias (e.g. sonnet)'
                );
            } elseif ($picked !== null) {
                $env['OPENCLAW_MODEL_PRIMARY'] = $map[$provider.':'.$picked] ?? null;
            }

            if (! empty($env['OPENCLAW_MODEL_PRIMARY'])) {
                \Laravel\Prompts\info('Using primary model: '.$env['OPENCLAW_MODEL_PRIMARY']);
            }
        }

        // Guardrail: common OpenRouter mistake
        if (! empty($env['OPENROUTER_API_KEY']) && ($env['OPENCLAW_MODEL_PRIMARY'] ?? '') === 'openrouter/auto') {
            $env['OPENCLAW_MODEL_PRIMARY'] = 'openrouter/openrouter/auto';
        }

        // Sprites auth: not always via env var; verify CLI can talk to sprites.
        // We won’t prompt for SPRITES_TOKEN unless the user wants to persist it.

        $persist = confirm('Persist these settings to a local .env file (gitignored)?', default: false);
        if ($persist) {
            self::ensureGitignoreHasDotEnv($repoRoot);
            self::writeEnvFile($repoRoot, $env);
        }

        return $env;
    }

    public static function ensureSpriteCliAuthenticated(bool $interactive = true, bool $fix = false): void
    {
        // First, do a quick non-interactive probe so we can show accurate guidance.
        $probeOut = shell_exec('sprite list 2>&1') ?? '';
        if (stripos($probeOut, 'no organizations configured') !== false || stripos($probeOut, 'sprite login') !== false) {
            $msg = "Sprites CLI is not authenticated (no org configured). Run:\n  sprite login\nThen run:\n  sprite list";

            if ($fix && confirm('Sprites needs login. Run `sprite login` now? (opens browser)', default: true)) {
                $code = 0;
                passthru('sprite login', $code);
            } else {
                error($msg);
                throw new \RuntimeException('Sprites CLI not authenticated');
            }
        }

        // Sprites may need a one-time org selection to mint a token.
        // If we suppress output, it looks like a hang. So we run interactively by default.
        $cmd = $interactive ? 'sprite list' : 'sprite list >/dev/null 2>&1';

        $code = 0;
        if ($interactive) {
            \Laravel\Prompts\info('Checking Sprites auth (you may be prompted to pick an org once)...');
            passthru($cmd, $code);
        } else {
            @passthru($cmd, $code);
        }

        if ($code !== 0) {
            error("Sprites CLI isn't authenticated yet. If this is a fresh setup run:\n  sprite login\nThen run:\n  sprite list\n(and pick an org if prompted)");
            throw new \RuntimeException('Sprites CLI not authenticated');
        }
    }

    private static function ensureGitignoreHasDotEnv(string $repoRoot): void
    {
        $path = rtrim($repoRoot, '/').'/'.'.gitignore';
        $line = ".env\n";
        if (! File::exists($path)) {
            File::put($path, $line);
            return;
        }
        $contents = File::get($path);
        if (strpos($contents, ".env") === false) {
            File::append($path, "\n.env\n");
        }
    }

    private static function writeEnvFile(string $repoRoot, array $env): void
    {
        $path = rtrim($repoRoot, '/').'/'.'.env';

        // Only write keys we manage. Keep it simple.
        $lines = [];
        $lines[] = '# Generated by openclawpm CLI';
        foreach (['OPENAI_API_KEY','ANTHROPIC_API_KEY','OPENROUTER_API_KEY','OPENCLAW_MODEL_PRIMARY','PM_SKILLS_REPO','PM_SKILLS_REF'] as $k) {
            $v = $env[$k] ?? '';
            if ($v === null) $v = '';
            // basic escaping: wrap in quotes if spaces
            if (preg_match('/\s/', (string) $v)) {
                $v = '"'.str_replace('"', '\\"', (string) $v).'"';
            }
            $lines[] = $k.'='.$v;
        }
        $lines[] = '';
        File::put($path, implode("\n", $lines));
    }
}
