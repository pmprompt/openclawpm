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

        $this->line('Checking local dependencies...');

        $ok = true;
        try {
            \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: (bool) $this->option('fix'));
            \App\Support\EnvPreflight::ensureSpriteCliAuthenticated();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $ok = false;
        }

        // Ensure repo-root .env is ignored if user chooses to persist later.
        \App\Support\EnvPreflight::ensureGitignoreHasDotEnvPublic($repoRoot);

        if ($ok) {
            $this->info('OK: ready to provision.');
            $this->line('Next: ./openclawpm provision pm-agent-test');
            return 0;
        }

        $this->error('Doctor found issues. Fix them and re-run.');
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
