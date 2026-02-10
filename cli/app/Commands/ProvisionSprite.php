<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ProvisionSprite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'provision
                            {name : Sprite name (e.g. pm-agent-test)}
                            {--no-verify : Skip verify step}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a Sprite and bootstrap OpenClaw + PM skills (wraps scripts/provision_sprite.sh).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = (string) $this->argument('name');
        $verbose = $this->option('verbose');

        // Validate sprite name
        if (! $this->validateSpriteName($name)) {
            return 1;
        }

        $repoRoot = realpath(__DIR__.'/../../..');

        $this->info('🚀 OpenClaw PM Agent Provision');
        $this->line("   Sprite: {$name}");
        $this->newLine();

        // Gather user context for personalized agent
        $this->info('👤 Quick setup - tell me about yourself:');
        $userContext = $this->gatherUserContext();
        $this->newLine();

        // Preflight: local deps + prompts for missing env vars.
        $this->info('📋 Checking dependencies...');
        \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: true);
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true, fix: true);
        $env = \App\Support\EnvPreflight::forProvisioning($repoRoot);

        // Pass user context to provisioning script
        putenv('USER_CONTEXT_B64='.base64_encode(json_encode($userContext)));

        // Do NOT print secrets. Pass env vars via process env instead of inline shell exports.
        foreach ($env as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            putenv($k.'='.$v);
        }

        $cmd = sprintf('bash ../scripts/provision_sprite.sh --name %s', escapeshellarg($name));
        if ($verbose) {
            $cmd .= ' --verbose';
        }

        passthru($cmd, $code);

        if ($code !== 0) {
            $this->error("\n❌ Provision failed with exit code $code");

            return $code;
        }

        if (! $this->option('no-verify')) {
            $this->newLine();
            $this->info('🔍 Running verification...');
            $verifyCmd = sprintf('bash ../scripts/verify_sprite.sh --name %s', escapeshellarg($name));
            if ($verbose) {
                $verifyCmd .= ' --verbose';
            }
            passthru($verifyCmd, $vcode);
            if ($vcode !== 0) {
                $this->error("\n❌ Verify failed with exit code $vcode");

                return $vcode;
            }
        }

        $this->newLine();
        $this->info('✅ Done! Your PM Agent is ready.');

        return 0;
    }

    /**
     * Gather user context during onboarding.
     */
    private function gatherUserContext(): array
    {
        $context = [];

        $context['role'] = $this->ask('  Your role (e.g., PM, Engineer, Founder)', 'Product Manager');
        $context['product'] = $this->ask('  Product/company name', '');
        $context['stage'] = $this->choice('  Product stage', ['MVP', 'Growth', 'Scale', 'Enterprise'], 1);
        $context['industry'] = $this->ask('  Industry (optional)', '');
        $context['focus'] = $this->choice(
            '  Primary focus',
            ['B2B SaaS', 'Consumer', 'Internal tools', 'Developer tools', 'Marketplace', 'Other'],
            0
        );

        return $context;
    }

    /**
     * Validate sprite name (lowercase alphanumeric and hyphens only - Sprites API requirement).
     */
    private function validateSpriteName(string $name): bool
    {
        if (empty($name)) {
            $this->error('❌ Sprite name cannot be empty');

            return false;
        }

        // Sprites naming convention: lowercase alphanumeric and hyphens only
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            $this->error('❌ Sprite name must be lowercase alphanumeric with hyphens only');
            $this->line('   Example: pm-agent-test (NOT pm_agent_test)');

            return false;
        }

        if (strlen($name) > 63) {
            $this->error('❌ Sprite name too long (max 63 characters)');

            return false;
        }

        // Cannot start or end with hyphen
        if (str_starts_with($name, '-') || str_ends_with($name, '-')) {
            $this->error('❌ Sprite name cannot start or end with a hyphen');

            return false;
        }

        return true;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
