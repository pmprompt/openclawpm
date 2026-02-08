<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class VerifySprite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify
                            {name : Sprite name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify an existing Sprite has OpenClaw running and PM skills installed (wraps scripts/verify_sprite.sh).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = (string) $this->argument('name');

        // Ensure sprite CLI is authenticated.
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true);

        $cmd = sprintf('bash ../scripts/verify_sprite.sh --name %s', escapeshellarg($name));
        $this->info('Verifying...');
        passthru($cmd, $code);
        return $code;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
