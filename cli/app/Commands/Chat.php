<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Chat extends Command
{
    protected $signature = 'chat
                            {name : Sprite name}
                            {--session=tui : Session id (stored on the Sprite)}
                            {--thinking=low : Thinking level (off|minimal|low|medium|high)}
                            {--timeout=600 : Timeout seconds per turn}
                            {--no-spinner : Disable the waiting spinner}
                            {--debug : Print extra diagnostics when a turn returns no output}
                            {--passthru : Do not capture stdout/stderr; stream sprite output directly}';

    protected $description = 'Basic TUI chat: run openclaw agent --local inside a Sprite in a loop.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $sessionId = (string) $this->option('session');
        $thinking = (string) $this->option('thinking');
        $timeout = (int) $this->option('timeout');
        $spinnerEnabled = ! (bool) $this->option('no-spinner');
        $debug = (bool) $this->option('debug');
        $passthru = (bool) $this->option('passthru');

        \App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: false);
        \App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: true, fix: false);

        $this->info("Chatting with sprite: {$name}");
        $this->line("Session: {$sessionId}");
        $this->line('Type /exit to quit.');

        while (true) {
            $this->output->write("You> ");
            $input = fgets(STDIN);
            if ($input === false) {
                $this->line('\nBye.');
                return 0;
            }

            $trim = trim($input);
            if ($trim === '' ) {
                continue;
            }
            if ($trim === '/exit' || $trim === '/quit') {
                $this->line('Bye.');
                return 0;
            }

            // base64 the message so we avoid quote escaping issues.
            $msgB64 = base64_encode($trim);

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

            if ($passthru) {
                // Stream output directly to the terminal (most reliable with sprite exec).
                $this->line('Kramer: thinking...');

                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => STDOUT,
                    2 => STDERR,
                ];

                $proc = proc_open($cmd, $descriptors, $pipes);
                if (! is_resource($proc)) {
                    $this->error('Failed to start sprite exec');
                    return 1;
                }

                fwrite($pipes[0], "export MSG_B64=".escapeshellarg($msgB64)."\n");
                fwrite($pipes[0], "export SESSION_ID=".escapeshellarg($sessionId)."\n");
                fwrite($pipes[0], "export THINKING=".escapeshellarg($thinking)."\n");
                fwrite($pipes[0], "export TIMEOUT=".escapeshellarg((string) $timeout)."\n");
                fwrite($pipes[0], $script."\n");
                fclose($pipes[0]);

                $code = proc_close($proc);
                if ($code !== 0) {
                    $this->error("Turn failed (exit {$code}).");
                }
            } else {
                // Capture mode (enables spinner), but can be flaky depending on sprite exec behavior.
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

                fwrite($pipes[0], "export MSG_B64=".escapeshellarg($msgB64)."\n");
                fwrite($pipes[0], "export SESSION_ID=".escapeshellarg($sessionId)."\n");
                fwrite($pipes[0], "export THINKING=".escapeshellarg($thinking)."\n");
                fwrite($pipes[0], "export TIMEOUT=".escapeshellarg((string) $timeout)."\n");
                fwrite($pipes[0], $script."\n");
                fclose($pipes[0]);

                $start = microtime(true);
                $frames = ['|', '/', '-', '\\'];
                $i = 0;

                while (true) {
                    $st = proc_get_status($proc);
                    if (! $st['running']) {
                        break;
                    }

                    if ($spinnerEnabled) {
                        $elapsed = (int) floor(microtime(true) - $start);
                        $frame = $frames[$i % count($frames)];
                        $i++;
                        $this->output->write("\rKramer: thinking {$frame} ({$elapsed}s)");
                    }

                    usleep(200_000);
                }

                if ($spinnerEnabled) {
                    $this->output->write("\r".str_repeat(' ', 40)."\r");
                }

                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                $code = proc_close($proc);

                $out = trim((string) $stdout);
                $err = trim((string) $stderr);

                if ($out !== '') {
                    $this->line("Kramer>\n".$out."\n");
                } else {
                    $this->line("Kramer> (no output)");
                    if ($debug) {
                        $this->line("[debug] exit={$code} stdout_len=".strlen((string)$stdout)." stderr_len=".strlen((string)$stderr));
                    }
                }

                if ($err !== '') {
                    $this->error($err);
                }

                if ($code !== 0) {
                    $this->error("Turn failed (exit {$code}).");
                }

                if ($debug && $out === '' && $err === '') {
                    $this->line('[debug] If you expected output, try: ./openclawpm verify '.$name);
                }
            }
        }
    }

    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
