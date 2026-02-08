<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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
            Prompt::error($msg);
            throw new \RuntimeException('Missing dependency: sprite');
        }

        if (! confirm('Sprites CLI not found. Install it now via the official install script?', default: false)) {
            Prompt::error($msg);
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
            'OPENCLAW_MODEL_PRIMARY' => getenv('OPENCLAW_MODEL_PRIMARY') ?: null,
            'PM_SKILLS_REPO' => getenv('PM_SKILLS_REPO') ?: 'git@github.com:pmprompt/claude-plugin-product-management.git',
            'PM_SKILLS_REF' => getenv('PM_SKILLS_REF') ?: 'main',
        ];

        // Validate provider key choice
        $hasOpenAI = ! empty($env['OPENAI_API_KEY']);
        $hasAnthropic = ! empty($env['ANTHROPIC_API_KEY']);

        if ($hasOpenAI && $hasAnthropic) {
            $choice = select(
                label: 'Both OPENAI_API_KEY and ANTHROPIC_API_KEY are set. Which should we use for this run?',
                options: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic'],
                default: 'openai'
            );
            if ($choice === 'openai') {
                $env['ANTHROPIC_API_KEY'] = null;
            } else {
                $env['OPENAI_API_KEY'] = null;
            }
        }

        if (! $env['OPENAI_API_KEY'] && ! $env['ANTHROPIC_API_KEY']) {
            $provider = select(
                label: 'No model API key found. Which provider do you want to use for this run?',
                options: ['openai' => 'OpenAI', 'anthropic' => 'Anthropic'],
                default: 'anthropic'
            );

            if ($provider === 'openai') {
                $env['OPENAI_API_KEY'] = password('Enter OPENAI_API_KEY (input hidden)');
            } else {
                $env['ANTHROPIC_API_KEY'] = password('Enter ANTHROPIC_API_KEY (input hidden)');
            }
        }

        if ($env['OPENCLAW_MODEL_PRIMARY'] === null) {
            if (confirm('Do you want to set an OpenClaw primary model alias? (e.g. sonnet)', default: false)) {
                $env['OPENCLAW_MODEL_PRIMARY'] = text('OPENCLAW_MODEL_PRIMARY', placeholder: 'sonnet');
            }
        }

        // Sprites auth: not always via env var; verify CLI can talk to sprites.
        // We won’t prompt for SPRITES_TOKEN unless the user wants to persist it.

        $persist = confirm('Persist these settings to a local .env file (gitignored)?', default: true);
        if ($persist) {
            self::ensureGitignoreHasDotEnv($repoRoot);
            self::writeEnvFile($repoRoot, $env);
        }

        return $env;
    }

    public static function ensureSpriteCliAuthenticated(bool $interactive = true): void
    {
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
            Prompt::error("Sprites CLI isn't authenticated yet. Run:\n  sprite org auth\nThen run:\n  sprite list");
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
        foreach (['OPENAI_API_KEY','ANTHROPIC_API_KEY','OPENCLAW_MODEL_PRIMARY','PM_SKILLS_REPO','PM_SKILLS_REF'] as $k) {
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
