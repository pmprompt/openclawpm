<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class DestroySprite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'destroy
                            {name : Agent name}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Destroy an Agent by name (wraps scripts/destroy_sprite.sh).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = (string) $this->argument('name');
        $force = (bool) $this->option('force');
        $verbose = $this->option('verbose');

        // Validate sprite name
        if (! $this->validateSpriteName($name)) {
            return 1;
        }

        $this->newLine();
        $this->info('🗑️  Destroy OpenClaw PM Agent');
        $this->newLine();

        // Confirmation prompt
        if (! $force) {
            $confirmed = \Laravel\Prompts\confirm(
                label: "Permanently destroy '{$name}'?",
                default: false
            );

            if (! $confirmed) {
                $this->line('Aborted.');

                return 0;
            }
        }

        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: false);

        $cmd = sprintf('bash ../scripts/destroy_sprite.sh --name %s --force', escapeshellarg($name));
        if ($verbose) {
            $cmd .= ' --verbose';
        }

        passthru($cmd, $code);

        if ($code === 0) {
            $this->newLine();
            $this->info('✅ Agent destroyed successfully.');
        } else {
            $this->newLine();
            $this->error('❌ Failed to destroy agent.');
        }

        return $code;
    }

    /**
     * Validate agent name (lowercase alphanumeric and hyphens only).
     */
    private function validateSpriteName(string $name): bool
    {
        if (empty($name)) {
            $this->error('❌ Agent name cannot be empty');

            return false;
        }

        // Agent naming convention: lowercase alphanumeric and hyphens only
        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            $this->error('❌ Agent name must be lowercase alphanumeric with hyphens only');
            $this->line('   Example: pm-agent-test (NOT pm_agent_test)');

            return false;
        }

        if (strlen($name) > 63) {
            $this->error('❌ Agent name too long (max 63 characters)');

            return false;
        }

        // Cannot start or end with hyphen
        if (str_starts_with($name, '-') || str_ends_with($name, '-')) {
            $this->error('❌ Agent name cannot start or end with a hyphen');

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
