<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Doctor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doctor
                            {--fix : Attempt to install missing dependencies}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check local dev machine dependencies (Sprites CLI auth, etc).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $repoRoot = realpath(__DIR__.'/../../..');
        $verbose = $this->option('verbose');

        $this->newLine();
        $this->info('🏥 OpenClaw PM Agent - Environment Check');
        $this->newLine();

        $ok = true;

        // Check 1: Sprites CLI
        $this->line('📦 Checking Sprites CLI...');
        try {
            \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: (bool) $this->option('fix'));
            $this->info('   ✓ Sprites CLI installed');
        } catch (\Throwable $e) {
            $this->error('   ✗ '.$e->getMessage());
            $ok = false;
        }

        // Check 2: Authentication
        $this->line('🔐 Checking Sprites authentication...');
        try {
            \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true, fix: (bool) $this->option('fix'));
            $this->info('   ✓ Authenticated');
        } catch (\Throwable $e) {
            $this->error('   ✗ '.$e->getMessage());
            $ok = false;
        }

        // Check 3: .env ignored
        $this->line('🔒 Checking .env is gitignored...');
        \App\Support\EnvPreflight::ensureGitignoreHasDotEnvPublic($repoRoot);
        $this->info('   ✓ .env is protected');

        $this->newLine();

        if ($ok) {
            $this->info('✅ All checks passed! Ready to provision.');
            $this->newLine();
            $this->comment('Next steps:');
            $this->line('  ./openclawpm provision <sprite-name>');

            return 0;
        }

        $this->error('❌ Doctor found issues. Fix them and re-run.');

        return 1;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
