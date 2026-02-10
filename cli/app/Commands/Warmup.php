<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Warmup extends Command
{
    protected $signature = 'warmup
                            {name : Agent name}
                            {--ping : Just ping VM wake up, no agent init}
                            {--init : Full init including OpenClaw agent (recommended for chat)}';

    protected $description = 'Wake up an agent (reduces first-message latency)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $justPing = (bool) $this->option('ping');
        $fullInit = (bool) $this->option('init');

        if (! $this->validateName($name)) {
            return 1;
        }

        $this->newLine();

        if ($justPing) {
            // Fast VM wake only
            $this->info('☕ Warming up VM...');
            $cmd = sprintf('sprite exec -s %s -- echo "ok" 2>/dev/null', escapeshellarg($name));
            $start = microtime(true);
            exec($cmd, $output, $code);
            $elapsed = round(microtime(true) - $start, 2);

            if ($code === 0) {
                $this->info("✅ VM ready ({$elapsed}s)");
            } else {
                $this->error('❌ Agent not responding');

                return 1;
            }
        } elseif ($fullInit) {
            // Full agent initialization (what chat needs)
            $this->info('☕ Initializing agent...');
            $start = microtime(true);

            // Step 1: Wake VM
            $cmd1 = sprintf('sprite exec -s %s -- echo "ok" 2>/dev/null', escapeshellarg($name));
            exec($cmd1, $output1, $code1);

            if ($code1 !== 0) {
                $this->error('❌ VM wake failed');

                return 1;
            }

            // Step 2: Initialize OpenClaw agent
            $initScript = <<<'BASH'
set -euo pipefail
NPM_BIN="$(npm bin -g 2>/dev/null || true)"
NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then export PATH="$NPM_BIN:$PATH"; fi
if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then export PATH="$NPM_PREFIX/bin:$PATH"; fi
if [[ -d '/.sprite/languages/node/nvm/versions/node' ]]; then
    NODE_BIN_DIR="$(find /.sprite/languages/node/nvm/versions/node -name 'bin' -type d 2>/dev/null | head -1 || true)"
    [[ -n "$NODE_BIN_DIR" ]] && export PATH="$NODE_BIN_DIR:$PATH"
fi
export PATH="$HOME/.local/bin:$PATH"
hash -r

# Initialize agent (triggers skill loading, session init, etc)
timeout 5 openclaw agent --local --session-id warmup --thinking off --timeout 5 --message "init" 2>/dev/null || true
echo "done"
BASH;

            $cmd2 = sprintf('sprite exec -s %s bash -c %s 2>/dev/null',
                escapeshellarg($name),
                escapeshellarg($initScript)
            );
            exec($cmd2, $output2, $code2);

            $elapsed = round(microtime(true) - $start, 2);
            $this->info("✅ Agent ready ({$elapsed}s)");
        } else {
            // Default: just verify OpenClaw exists
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
