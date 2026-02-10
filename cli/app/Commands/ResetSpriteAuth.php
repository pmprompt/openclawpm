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
        $this->newLine();
        $this->info('🔄 Reset Sprites CLI Authentication');
        $this->newLine();

        $this->warn('This will remove local Sprites configuration files.');
        $this->line('You will need to re-authenticate with: sprite login');
        $this->newLine();

        \App\Support\EnvPreflight::resetSpriteAuth(
            force: (bool) $this->option('force')
        );

        $this->newLine();
        $this->info('✅ Sprites auth reset.');
        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  sprite login');
        $this->line('  ./openclawpm doctor');
        $this->newLine();

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
