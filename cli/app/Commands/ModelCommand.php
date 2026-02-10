<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ModelCommand extends Command
{
    protected $signature = 'model
                            {action? : Action to perform (list, set, current)}
                            {--name= : Sprite name (required for set action)}
                            {--model= : Model to set (required for set action)}';

    protected $description = 'Manage AI models for your PM Agent (list, set, or check current model)';

    private ?array $availableModels = null;

    private function loadModels(): array
    {
        if ($this->availableModels !== null) {
            return $this->availableModels;
        }

        $configPath = __DIR__.'/../../config/models.json';

        if (! file_exists($configPath)) {
            $this->warn("Config file not found: {$configPath}");

            return [];
        }

        $content = file_get_contents($configPath);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->warn('Invalid JSON in models config: '.json_last_error_msg());

            return [];
        }

        // Flatten nested structure (provider -> model_id -> data) to flat array
        $models = [];
        foreach ($data as $provider => $providerModels) {
            if (str_starts_with($provider, '_')) {
                continue; // Skip metadata keys like _comment
            }

            foreach ($providerModels as $modelId => $modelData) {
                $models[$modelId] = $modelData;
            }
        }

        $this->availableModels = $models;

        return $models;
    }

    public function handle(): int
    {
        $action = $this->argument('action') ?: 'list';

        return match ($action) {
            'list' => $this->listModels(),
            'set' => $this->setModel(),
            'current' => $this->showCurrent(),
            default => $this->showHelp(),
        };
    }

    private function listModels(): int
    {
        $models = $this->loadModels();

        $this->info('Available Models:');
        $this->newLine();

        // Group by provider
        $byProvider = [];
        foreach ($models as $key => $model) {
            $provider = $model['provider'] ?? 'unknown';
            $byProvider[$provider][] = [$key, $model];
        }

        foreach ($byProvider as $provider => $providerModels) {
            $this->line('  <fg=yellow>'.strtoupper($provider).'</>');
            foreach ($providerModels as [$key, $model]) {
                $this->line("    <fg=green>{$key}</>");
                $this->line("      <fg=cyan>{$model['name']}</> - {$model['description']}");
                $this->line("      Speed: {$model['speed']} | Cost: {$model['cost']}");
            }
            $this->newLine();
        }

        $this->line('  <fg=gray>You can also use any custom model ID your provider supports</>');
        $this->newLine();
        $this->line('  <fg=gray>Usage: ./openclawpm model set --name=sprite-name --model=kimi-k2.5</>');

        return 0;
    }

    private function setModel(): int
    {
        $name = $this->option('name');
        $model = $this->option('model');

        if (! $name) {
            $this->error('❌ Missing --name (sprite name)');
            $this->line('   Usage: ./openclawpm model set --name=my-agent --model=haiku');

            return 1;
        }

        if (! $model) {
            $this->error('❌ Missing --model');
            $this->line('   Run `./openclawpm model list` to see available models');

            return 1;
        }

        $models = $this->loadModels();

        // Allow custom model IDs - just warn if not in our curated list
        $modelName = $models[$model]['name'] ?? $model;
        $modelProvider = $models[$model]['provider'] ?? 'custom';

        if (! array_key_exists($model, $models)) {
            $this->warn("⚠️  Unknown model: {$model}");
            $this->line('   Will attempt to use as custom model ID');
            $this->newLine();
        }

        // Validate sprite exists
        $checkCmd = sprintf('sprite list 2>/dev/null | grep -q "^%s$"', escapeshellarg($name));
        exec($checkCmd, $_, $code);
        if ($code !== 0) {
            $this->error("❌ Sprite '{$name}' not found");
            $this->line('   Run `./openclawpm list` to see available sprites');

            return 1;
        }

        $this->info("🔄 Switching {$name} to {$modelName}...");

        // Update the model in the Sprite via openclaw config
        $configCmd = sprintf(
            'sprite exec -s %s bash -c %s',
            escapeshellarg($name),
            escapeshellarg("openclaw config set agents.defaults.model.primary {$model}")
        );

        exec($configCmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('❌ Failed to update model in Sprite');

            return 1;
        }

        // Also update local .env for persistence
        $this->updateLocalEnv($model);

        $this->info("✅ Model switched to {$modelName}");
        if (isset($models[$model]['speed'])) {
            $this->line("   Speed: {$models[$model]['speed']}");
        }
        $this->line('   Ready to use immediately - no restart needed!');

        return 0;
    }

    private function showCurrent(): int
    {
        $current = getenv('OPENCLAW_MODEL_PRIMARY') ?: 'default (Claude Sonnet)';
        $this->info("Current model: {$current}");

        $models = $this->loadModels();
        if (array_key_exists($current, $models)) {
            $model = $models[$current];
            $this->line("  {$model['name']} - {$model['description']}");
        }

        return 0;
    }

    private function showHelp(): int
    {
        $this->info('Model Command');
        $this->newLine();
        $this->line('  list    - Show available models');
        $this->line('  set     - Switch model for a sprite');
        $this->line('  current - Show current model');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  ./openclawpm model list');
        $this->line('  ./openclawpm model set --name=my-agent --model=haiku');
        $this->line('  ./openclawpm model current');

        return 0;
    }

    private function updateLocalEnv(string $model): void
    {
        $envPath = getcwd().'/.env';
        if (! file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        $pattern = '/^OPENCLAW_MODEL_PRIMARY=.*$/m';
        $replacement = "OPENCLAW_MODEL_PRIMARY={$model}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= "\n{$replacement}\n";
        }

        file_put_contents($envPath, $content);
    }

    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
