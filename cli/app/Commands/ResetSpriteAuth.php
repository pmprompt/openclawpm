<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ResetSpriteAuth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset-sprite-auth
                            {--force : Do not prompt for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset local Sprites CLI auth/config so org selection can be re-done.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        \App\Support\EnvPreflight::resetSpriteAuth(
            force: (bool) $this->option('force')
        );

        $this->info('Done. Next: sprite org auth');
        return 0;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
