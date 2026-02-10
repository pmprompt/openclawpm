<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Warmup extends Command
{
    protected $signature = 'warmup
                            {name : Agent name}
                            {--ping : Just ping to wake up, no full verification}';

    protected $description = 'Wake up an agent (reduces first-message latency)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $justPing = (bool) $this->option('ping');

        if (! $this->validateName($name)) {
            return 1;
        }

        $this->newLine();

        if ($justPing) {
            $this->info('☕ Warming up...');
            $cmd = sprintf('sprite exec -s %s -- echo "ok" 2>/dev/null', escapeshellarg($name));
            $start = microtime(true);
            exec($cmd, $output, $code);
            $elapsed = round(microtime(true) - $start, 2);

            if ($code === 0) {
                $this->info("✅ Ready ({$elapsed}s)");
            } else {
                $this->error('❌ Agent not responding');

                return 1;
            }
        } else {
            $this->info('🔍 Verifying...');
            $cmd = sprintf('sprite exec -s %s -- openclaw --version 2>/dev/null', escapeshellarg($name));
            $start = microtime(true);
            exec($cmd, $output, $code);
            $elapsed = round(microtime(true) - $start, 2);

            if ($code === 0 && ! empty($output[0])) {
                $this->info("✅ OpenClaw {$output[0]} ({$elapsed}s)");
            } else {
                $this->error('❌ Verification failed');

                return 1;
            }
        }

        $this->newLine();

        return 0;
    }

    private function validateName(string $name): bool
    {
        if (empty($name) || ! preg_match('/^[a-z0-9-]+$/', $name)) {
            $this->error('❌ Invalid name');

            return false;
        }

        return true;
    }

    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
