<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use function Laravel\Prompts\text;

class Chat extends Command
{
    protected $signature = 'chat
                            {name : Sprite name}
                            {--session=tui : Session id (stored on the Sprite)}
                            {--thinking=low : Thinking level (off|minimal|low|medium|high)}
                            {--timeout=600 : Timeout seconds per turn}';

    protected $description = 'Basic TUI chat: run openclaw agent --local inside a Sprite in a loop.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $sessionId = (string) $this->option('session');
        $thinking = (string) $this->option('thinking');
        $timeout = (int) $this->option('timeout');

        \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: false);
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true, fix: false);

        $this->info("Chatting with sprite: {$name}");
        $this->line("Session: {$sessionId}");
        $this->line('Type /exit to quit.');

        while (true) {
            $input = text(
                label: 'You',
                placeholder: 'Ask something…',
                required: true
            );

            $trim = trim($input);
            if ($trim === '/exit' || $trim === '/quit') {
                $this->line('Bye.');
                return 0;
            }

            // base64 the message so we avoid quote escaping issues.
            $msgB64 = base64_encode($input);

            $script = <<<'BASH'
set -euo pipefail

# Repair PATH for OpenClaw installed via npm global bin.
NPM_BIN="$(npm bin -g 2>/dev/null || true)"
NPM_PREFIX="$(npm config get prefix 2>/dev/null || true)"
if [[ -n "$NPM_BIN" && -d "$NPM_BIN" ]]; then export PATH="$NPM_BIN:$PATH"; fi
if [[ -n "$NPM_PREFIX" && -d "$NPM_PREFIX/bin" ]]; then export PATH="$NPM_PREFIX/bin:$PATH"; fi
if [[ -d '/.sprite/languages/node/nvm/versions/node/v22.20.0/bin' ]]; then export PATH="/.sprite/languages/node/nvm/versions/node/v22.20.0/bin:$PATH"; fi
export PATH="$HOME/.local/bin:$PATH"
hash -r

MSG="$(printf '%s' "$MSG_B64" | base64 -d)"

openclaw agent --local \
  --session-id "$SESSION_ID" \
  --thinking "$THINKING" \
  --timeout "$TIMEOUT" \
  --message "$MSG"
BASH;

            // Send the script via stdin to avoid local shell parsing issues.
            $cmd = sprintf('sprite exec -s %s bash -s', escapeshellarg($name));

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes);
            if (! is_resource($proc)) {
                $this->error('Failed to start sprite exec');
                return 1;
            }

            // Export env vars for the script.
            fwrite($pipes[0], "export MSG_B64=".escapeshellarg($msgB64)."\n");
            fwrite($pipes[0], "export SESSION_ID=".escapeshellarg($sessionId)."\n");
            fwrite($pipes[0], "export THINKING=".escapeshellarg($thinking)."\n");
            fwrite($pipes[0], "export TIMEOUT=".escapeshellarg((string) $timeout)."\n");
            fwrite($pipes[0], $script."\n");
            fclose($pipes[0]);

            // Read output so the user actually sees the response.
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $code = proc_close($proc);

            $out = trim((string) $stdout);
            if ($out !== '') {
                $this->newLine();
                $this->line($out);
                $this->newLine();
            }

            $err = trim((string) $stderr);
            if ($err !== '') {
                $this->error($err);
            }

            if ($code !== 0) {
                $this->error("Turn failed (exit {$code}).");
            }
        }
    }

    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
