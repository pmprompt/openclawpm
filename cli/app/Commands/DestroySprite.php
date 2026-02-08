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
                            {name : Sprite name}';

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

        $cmd = sprintf('bash ../scripts/destroy_sprite.sh --name %s', escapeshellarg($name));
        $this->info("Running: $cmd");
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
