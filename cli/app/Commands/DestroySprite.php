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
                            {name : Sprite name}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Destroy a Sprite by name (wraps scripts/destroy_sprite.sh).';

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
        $this->line("   Sprite: {$name}");
        $this->newLine();

        // Confirmation prompt
        if (! $force) {
            $confirmed = \Laravel\Prompts\confirm(
                label: 'Are you sure you want to permanently destroy this sprite?',
                default: false
            );

            if (! $confirmed) {
                $this->line('Aborted.');

                return 0;
            }
        }

        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true);

        $cmd = sprintf('bash ../scripts/destroy_sprite.sh --name %s --force', escapeshellarg($name));
        if ($verbose) {
            $cmd .= ' --verbose';
        }

        passthru($cmd, $code);

        if ($code === 0) {
            $this->newLine();
            $this->info('✅ Sprite destroyed successfully.');
        } else {
            $this->newLine();
            $this->error('❌ Failed to destroy sprite.');
        }

        return $code;
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
