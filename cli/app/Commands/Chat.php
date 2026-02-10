<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Chat extends Command
{
    private bool $thinkingShown = false;

    private string $borderColor = 'gray';

    private string $userColor = 'green';

    private string $agentColor = 'cyan';

    private ?string $lastResponse = null;

    private ?string $lastResponseRaw = null;

    private bool $hintShown = false;

    protected $signature = 'chat
                            {name : Sprite name}
                            {--session=tui : Session id (stored on the Sprite)}
                            {--thinking=low : Thinking level (off|minimal|low|medium|high)}
                            {--timeout=600 : Timeout seconds per turn}
                            {--no-spinner : Disable the waiting spinner}
                            {--debug : Print extra diagnostics when a turn returns no output}
                            {--passthru : Do not capture stdout/stderr; stream sprite output directly}';

    protected $description = 'Chat with your PM Agent';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $sessionId = (string) $this->option('session');
        $thinking = (string) $this->option('thinking');
        $timeout = (int) $this->option('timeout');
        $spinnerEnabled = ! (bool) $this->option('no-spinner');
        $debug = (bool) $this->option('debug');
        $passthru = (bool) $this->option('passthru');

        if (! $this->validateSpriteName($name)) {
            return 1;
        }

        // Quick auth check without verbose output
        try {
            @\App\Support\EnvPreflight::ensureSpriteCliInstalled(fix: false);
            @\App\Support\EnvPreflight::ensureSpriteCliAuthenticated(interactive: false, fix: false);
        } catch (\Throwable $e) {
            // Silent fail
        }

        $this->renderHeader($name);
        $this->renderWelcomeMessage();

        while (true) {
            $this->renderInputArea();
            $input = fgets(STDIN);

            if ($input === false) {
                return 0;
            }

            $trim = trim($input);
            if ($trim === '') {
                continue;
            }

            if ($trim === '/exit' || $trim === '/quit') {
                return 0;
            }

            if ($trim === '/copy') {
                $this->copyLastResponse();

                continue;
            }

            if ($trim === '/copy-md') {
                $this->copyLastResponseMarkdown();

                continue;
            }

            // Move to new line after input
            $this->newLine();

            // Process the message
            $this->processMessage($trim, $name, $sessionId, $thinking, $timeout, $spinnerEnabled, $passthru, $debug);
        }
    }

    private function renderHeader(string $name): void
    {
        $model = $this->getModelDisplayName();

        $this->newLine();
        // Simple centered header without box
        $this->line("  <fg={$this->agentColor}>◆ OpenClaw PM Agent</> <fg=gray>{$name} • {$model}</>");
        $this->line("  <fg={$this->borderColor}>".str_repeat('─', 56).'</>');
        $this->newLine();
    }

    private function renderWelcomeMessage(): void
    {
        $this->line("  <fg={$this->agentColor}>● Kramer</>");
        $this->line("  <fg={$this->borderColor}>│</>");
        $this->line("  <fg={$this->borderColor}>│</> What are we working on today?");
        $this->newLine();
    }

    private function showCopyHint(): void
    {
        if (! $this->hintShown && $this->lastResponse !== null) {
            // Only show copy hint if we actually have an artifact
            if ($this->hasArtifact($this->lastResponse)) {
                $this->newLine();
                $this->line('  <fg=blue>copy: /copy (clean text) · /copy-md (markdown)</>');
            }
            $this->hintShown = true;
        }
    }

    private function hasArtifact(string $text): bool
    {
        // Check for common artifact markers
        $artifactPatterns = [
            '/^#{1,6}\s+.+$/m',                    // Headers (## PRD, ### 1. Problem)
            '/^(Project\s+Pitch|PRD|Product\s+Requirements)/mi', // Document titles
            '/^(The\s+(Core\s+)?Job|Job\s+Map)/mi', // JTBD artifacts
            '/^\d+\.\s+\w+/m',                     // Numbered sections (1. Introduction)
            '/^\*\s+/m',                            // Bullet lists
            '/^(-\s+|•\s+)/m',                     // Other list formats
        ];

        foreach ($artifactPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private function renderInputArea(): void
    {
        $this->output->write("  <fg={$this->userColor}>▶</> ");
    }

    private function processMessage(
        string $message,
        string $name,
        string $sessionId,
        string $thinking,
        int $timeout,
        bool $spinnerEnabled,
        bool $passthru,
        bool $debug
    ): void {
        $this->resetThinkingState();
        $msgB64 = base64_encode($message);
        $script = $this->getBashScript();
        $cmd = sprintf('sprite exec -s %s bash -s', escapeshellarg($name));

        if ($passthru) {
            $this->renderThinking();
            $this->executePassthru($cmd, $msgB64, $sessionId, $thinking, $timeout, $script);
        } else {
            $result = $this->executeWithSpinner($cmd, $msgB64, $sessionId, $thinking, $timeout, $script, $spinnerEnabled);
            $this->renderResponse($result, $debug);
        }
    }

    private function executePassthru(
        string $cmd,
        string $msgB64,
        string $sessionId,
        string $thinking,
        int $timeout,
        string $script
    ): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            $this->error('Failed to start sprite exec');

            return;
        }

        fwrite($pipes[0], 'export MSG_B64='.escapeshellarg($msgB64)."\n");
        fwrite($pipes[0], 'export SESSION_ID='.escapeshellarg($sessionId)."\n");
        fwrite($pipes[0], 'export THINKING='.escapeshellarg($thinking)."\n");
        fwrite($pipes[0], 'export TIMEOUT='.escapeshellarg((string) $timeout)."\n");
        fwrite($pipes[0], $script."\n");
        fclose($pipes[0]);

        proc_close($proc);
    }

    private function executeWithSpinner(
        string $cmd,
        string $msgB64,
        string $sessionId,
        string $thinking,
        int $timeout,
        string $script,
        bool $spinnerEnabled
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            return ['error' => 'Failed to start sprite exec'];
        }

        fwrite($pipes[0], 'export MSG_B64='.escapeshellarg($msgB64)."\n");
        fwrite($pipes[0], 'export SESSION_ID='.escapeshellarg($sessionId)."\n");
        fwrite($pipes[0], 'export THINKING='.escapeshellarg($thinking)."\n");
        fwrite($pipes[0], 'export TIMEOUT='.escapeshellarg((string) $timeout)."\n");
        fwrite($pipes[0], $script."\n");
        fclose($pipes[0]);

        $start = microtime(true);
        $this->renderThinking();

        while (true) {
            $st = proc_get_status($proc);
            if (! $st['running']) {
                break;
            }

            if ($spinnerEnabled) {
                $elapsed = (int) floor(microtime(true) - $start);
                $this->renderThinking($elapsed);
            }

            usleep(100_000);
        }

        $this->clearThinking();

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($proc);

        return [
            'stdout' => trim((string) $stdout),
            'stderr' => trim((string) $stderr),
            'code' => $code,
        ];
    }

    private function renderThinking(int $elapsed = 0): void
    {
        if (! posix_isatty(STDOUT)) {
            if (! $this->thinkingShown) {
                $this->output->write('  <fg=gray>Thinking...</>');
                $this->thinkingShown = true;
            }

            return;
        }

        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $frame = $frames[$elapsed % count($frames)];
        $timeStr = $elapsed > 0 ? " {$elapsed}s" : '';

        $this->output->write("\r  <fg=gray>{$frame} Thinking{$timeStr}</>");
        $this->thinkingShown = true;
    }

    private function clearThinking(): void
    {
        if (posix_isatty(STDOUT)) {
            $this->output->write("\r\033[K");
        } else {
            $this->newLine();
        }
    }

    private function renderResponse(array $result, bool $debug): void
    {
        $stdout = $result['stdout'] ?? '';
        $stderr = $result['stderr'] ?? '';
        $code = $result['code'] ?? 0;

        if ($stdout !== '') {
            $data = json_decode($stdout, true);
            if ($data && isset($data['payloads'][0]['text'])) {
                $text = $data['payloads'][0]['text'];
                $this->lastResponseRaw = $text; // Keep raw markdown
                $this->lastResponse = $text; // For artifact extraction
                $this->renderAgentMessage($text);
                $this->showCopyHint();

                if ($debug && isset($data['meta'])) {
                    $this->renderDebugInfo($data['meta']);
                }
            } else {
                $this->lastResponseRaw = $stdout;
                $this->lastResponse = $stdout;
                $this->renderAgentMessage($stdout);
                $this->showCopyHint();
            }
        } elseif ($debug) {
            $this->warn('  ⚠️  No response received');
        }

        if ($stderr !== '' && $debug) {
            $this->error("  {$stderr}");
        }

        if ($code !== 0 && $debug) {
            $this->error("  Error (exit {$code})");
        }
    }

    private function copyLastResponse(): void
    {
        if ($this->lastResponse === null) {
            $this->warn('  ⚠️  No response to copy yet');

            return;
        }

        // Extract just the artifact (skip intro/outro conversational text)
        $artifact = $this->extractArtifact($this->lastResponse);

        // Try to copy to clipboard using system commands
        $copied = false;

        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS
            $proc = proc_open('pbcopy', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $artifact);
                fclose($pipes[0]);
                proc_close($proc);
                $copied = true;
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            // Try xclip or xsel
            $proc = proc_open('xclip -selection clipboard', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $artifact);
                fclose($pipes[0]);
                proc_close($proc);
                $copied = true;
            } else {
                // Try xsel
                $proc = proc_open('xsel --clipboard --input', [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes);
                if (is_resource($proc)) {
                    fwrite($pipes[0], $artifact);
                    fclose($pipes[0]);
                    proc_close($proc);
                    $copied = true;
                }
            }
        }

        if ($copied) {
            $this->info('  ✅ Copied artifact to clipboard');
        } else {
            $this->warn('  ⚠️  Could not copy to clipboard (install xclip or xsel on Linux)');
        }
        $this->newLine();
    }

    private function extractArtifact(string $text): string
    {
        // Look for common artifact markers
        $markers = [
            '/^(#{1,6}\s+.+)$/m',           // Headers like # PRD or ## Project Pitch
            '/^(Project\s+Pitch:.+)$/mi',    // Project Pitch: title
            '/^(PRD:.+)$/mi',                // PRD: title
            '/^(Product\s+Requirements)/mi', // Product Requirements
            '/^(##\s*The\s+Problem)/mi',     // ## The Problem
            '/^(##\s*1\.\s*)/mi',            // ## 1. Section
        ];

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                // Found a marker - extract from here to end
                $startPos = $matches[0][1];
                $artifact = substr($text, $startPos);

                // Remove trailing conversational text
                $artifact = $this->removeTrailingFluff($artifact);

                return trim($artifact);
            }
        }

        // No artifact marker found - return full text but try to clean it up
        return $this->removeTrailingFluff($text);
    }

    private function removeTrailingFluff(string $text): string
    {
        // Remove trailing questions or conversational endings
        $fluffPatterns = [
            '/\n\s*Would you like me to.*$/is',
            '/\n\s*Let me know if.*$/is',
            '/\n\s*Does this help.*$/is',
            '/\n\s*What do you think.*$/is',
            '/\n\s*Shall I.*$/is',
            '/\n\s*Feel free to.*$/is',
        ];

        foreach ($fluffPatterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        return trim($text);
    }

    private function copyLastResponseMarkdown(): void
    {
        if ($this->lastResponseRaw === null) {
            $this->warn('  ⚠️  No response to copy yet');

            return;
        }

        // Extract just the artifact but keep markdown formatting
        $artifact = $this->extractArtifact($this->lastResponseRaw);

        // Try to copy to clipboard using system commands
        $copied = false;

        if (PHP_OS_FAMILY === 'Darwin') {
            $proc = proc_open('pbcopy', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $artifact);
                fclose($pipes[0]);
                proc_close($proc);
                $copied = true;
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $proc = proc_open('xclip -selection clipboard', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (is_resource($proc)) {
                fwrite($pipes[0], $artifact);
                fclose($pipes[0]);
                proc_close($proc);
                $copied = true;
            } else {
                $proc = proc_open('xsel --clipboard --input', [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes);
                if (is_resource($proc)) {
                    fwrite($pipes[0], $artifact);
                    fclose($pipes[0]);
                    proc_close($proc);
                    $copied = true;
                }
            }
        }

        if ($copied) {
            $this->info('  ✅ Copied markdown artifact to clipboard');
        } else {
            $this->warn('  ⚠️  Could not copy to clipboard');
        }
        $this->newLine();
    }

    private function renderAgentMessage(string $text): void
    {
        $text = trim($text);

        // Strip markdown formatting for terminal display
        $text = $this->stripMarkdown($text);

        // Format markdown tables
        if (strpos($text, '|') !== false && strpos($text, '---') !== false) {
            $text = $this->formatMarkdownTable($text);
        }

        // Compress multiple blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Split into lines and process
        $lines = explode("\n", $text);
        $allLines = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Empty line - just add border
            if ($line === '') {
                $allLines[] = '';

                continue;
            }

            // Wrap long lines with proper continuation
            if (strlen($line) > 68) {
                $wrapped = wordwrap($line, 65, "\n", true);
                $wrappedLines = explode("\n", $wrapped);
                foreach ($wrappedLines as $j => $wrappedLine) {
                    $allLines[] = $wrappedLine;
                }
            } else {
                $allLines[] = $line;
            }
        }

        // Output with proper formatting
        $this->line("  <fg={$this->agentColor}>● Kramer</>");

        // Content with left border
        foreach ($allLines as $line) {
            if ($line === '') {
                $this->line("  <fg={$this->borderColor}>│</>");
            } else {
                $this->line("  <fg={$this->borderColor}>│</> {$line}");
            }
        }

        $this->newLine();
    }

    private function stripMarkdown(string $text): string
    {
        // Remove horizontal rules (--- or ***)
        $text = preg_replace('/^\s*[-*]{3,}\s*$/m', '', $text);

        // Remove headers (###, ##, #)
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);

        // Remove bold markers **text**
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text);

        // Remove italic markers *text* (but not bullet points)
        $text = preg_replace('/(?<!\*)\*(?!\s)(.*?)\*(?!\*)/', '$1', $text);

        // Remove blockquote markers >
        $text = preg_replace('/^>\s?/m', '', $text);

        // Remove inline code markers `text`
        $text = preg_replace('/`([^`]+)`/', '$1', $text);

        // Remove code block markers ```
        $text = preg_replace('/```\w*\n?/', '', $text);

        // Clean up extra blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function formatMarkdownTable(string $text): string
    {
        $lines = explode("\n", $text);
        $formatted = [];

        foreach ($lines as $line) {
            if (strpos($line, '|') !== false) {
                $line = trim($line, '| ');
                $cells = array_map('trim', explode('|', $line));

                if (strpos($line, '---') !== false) {
                    continue;
                }

                if (count($cells) >= 2) {
                    $name = trim($cells[0], '* ');
                    $desc = trim($cells[1]);
                    if (strlen($desc) > 50) {
                        $desc = substr($desc, 0, 47).'...';
                    }
                    $formatted[] = "• {$name}: {$desc}";
                }
            } else {
                $formatted[] = $line;
            }
        }

        return implode("\n", $formatted);
    }

    private function renderDebugInfo(array $meta): void
    {
        $this->newLine();
        $this->line('  <fg=gray>───</>');
        if (isset($meta['agentMeta']['model'])) {
            $this->line("  <fg=gray>Model: {$meta['agentMeta']['model']}</>");
        }
        if (isset($meta['durationMs'])) {
            $this->line('  <fg=gray>Duration: '.round($meta['durationMs'] / 1000, 1).'s</>');
        }
    }

    private function getBashScript(): string
    {
        return <<<'BASH'
set -euo pipefail

# Repair PATH for OpenClaw
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

MSG="$(printf '%s' "$MSG_B64" | base64 -d)"

# Run and force a machine-readable output.
openclaw agent --local \
  --session-id "$SESSION_ID" \
  --thinking "$THINKING" \
  --timeout "$TIMEOUT" \
  --json \
  --message "$MSG" 2>/dev/null
BASH;
    }

    private function resetThinkingState(): void
    {
        $this->thinkingShown = false;
    }

    /**
     * Validate sprite name (lowercase alphanumeric and hyphens only - Sprites API requirement).
     */
    private function validateSpriteName(string $name): bool
    {
        if (empty($name)) {
            $this->error('  ❌ Sprite name cannot be empty');

            return false;
        }

        if (! preg_match('/^[a-z0-9-]+$/', $name)) {
            $this->error('  ❌ Sprite name must be lowercase alphanumeric with hyphens only');
            $this->line('     Example: pm-agent-test (NOT pm_agent_test)');

            return false;
        }

        if (strlen($name) > 63) {
            $this->error('  ❌ Sprite name too long (max 63 characters)');

            return false;
        }

        if (str_starts_with($name, '-') || str_ends_with($name, '-')) {
            $this->error('  ❌ Sprite name cannot start or end with a hyphen');

            return false;
        }

        return true;
    }

    /**
     * Get a human-readable model display name.
     */
    private function getModelDisplayName(): string
    {
        $model = getenv('OPENCLAW_MODEL_PRIMARY');
        if (empty($model)) {
            return 'default';
        }

        $map = [
            'sonnet' => 'Claude Sonnet',
            'anthropic/claude-sonnet-4.5' => 'Claude Sonnet 4.5',
            'openrouter/anthropic/claude-sonnet-4.5' => 'Claude Sonnet 4.5',
            'haiku' => 'Claude Haiku',
            'openrouter/openrouter/auto' => 'Auto',
        ];

        return $map[$model] ?? $model;
    }

    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
