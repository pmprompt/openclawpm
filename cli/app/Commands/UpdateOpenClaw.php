<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class UpdateOpenClaw extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update
                            {name : Sprite name}
                            {--channel= : Update channel (stable, beta, dev). Defaults to current channel}
                            {--version= : Specific version to install (e.g., 2026.2.15). Overrides --channel}
                            {--no-restart : Skip restarting the gateway after update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update OpenClaw on an existing Sprite to a newer version or different channel';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = (string) $this->argument('name');
        $verbose = $this->option('verbose');
        $channel = $this->option('channel');
        $version = $this->option('version');
        $noRestart = $this->option('no-restart');

        // Validate sprite name
        if (! $this->validateSpriteName($name)) {
            return 1;
        }

        $this->newLine();
        $this->info('🔄 OpenClaw Update');
        $this->line("   Sprite: {$name}");

        // Show what we're doing
        if ($version) {
            $this->line("   Target: version {$version}");
        } elseif ($channel) {
            $this->line("   Target: {$channel} channel");
        } else {
            $this->line('   Target: latest (current channel)');
        }

        $this->newLine();

        // Ensure sprite CLI is authenticated
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true);

        $repoRoot = realpath(__DIR__.'/../../..');

        // Build the command
        $cmd = sprintf('bash %s/../scripts/update_openclaw.sh --name %s',
            escapeshellarg($repoRoot),
            escapeshellarg($name)
        );

        if ($channel) {
            $cmd .= sprintf(' --channel %s', escapeshellarg($channel));
        }

        if ($version) {
            $cmd .= sprintf(' --version %s', escapeshellarg($version));
        }

        if ($noRestart) {
            $cmd .= ' --no-restart';
        }

        if ($verbose) {
            $cmd .= ' --verbose';
        }

        passthru($cmd, $code);

        if ($code === 0) {
            $this->newLine();
            $this->info('✅ Update complete!');
            $this->line('');
            $this->line('Next steps:');
            $this->line("  🔍 Verify: ./openclawpm verify {$name}");
            $this->line("  💬 Chat:   ./openclawpm chat {$name}");
        } else {
            $this->newLine();
            $this->error('❌ Update failed.');
            $this->line('');
            $this->line('Troubleshooting:');
            $this->line('  - Check the Sprite is running: sprite list');
            $this->line('  - Try with --verbose for more details');
            $this->line('  - You can always destroy and re-provision if needed');
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
