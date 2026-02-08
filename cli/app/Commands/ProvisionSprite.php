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

        $repoRoot = realpath(__DIR__.'/../../..');

        // Preflight: local deps + prompts for missing env vars.
        \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: true);
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true);
        $env = \App\Support\EnvPreflight::forProvisioning($repoRoot);

        $exports = '';
        foreach ($env as $k => $v) {
            if ($v === null || $v === '') continue;
            $exports .= $k.'='.escapeshellarg((string) $v).' ';
        }

        $cmd = $exports . sprintf('bash ../scripts/provision_sprite.sh --name %s', escapeshellarg($name));
        $this->info("Running: $cmd");
        passthru($cmd, $code);

        if ($code !== 0) {
            $this->error("Provision failed with exit code $code");
            return $code;
        }

        if (! $this->option('no-verify')) {
            $verifyCmd = sprintf('bash ../scripts/verify_sprite.sh --name %s', escapeshellarg($name));
            $this->info("\nRunning: $verifyCmd");
            passthru($verifyCmd, $vcode);
            if ($vcode !== 0) {
                $this->error("Verify failed with exit code $vcode");
                return $vcode;
            }
        }

        $this->info("\nDone.");
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
