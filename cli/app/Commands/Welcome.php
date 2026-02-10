<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class Welcome extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'welcome';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the OpenClaw PM Agent welcome screen';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>╔══════════════════════════════════════════════╗</>');
        $this->line('  <fg=cyan;options=bold>║</>  <fg=white;options=bold>OpenClaw PM Agent</>                            <fg=cyan;options=bold>║</>');
        $this->line('  <fg=cyan;options=bold>║</>  <fg=gray>Product management AI for every PM</>         <fg=cyan;options=bold>║</>');
        $this->line('  <fg=cyan;options=bold>╚══════════════════════════════════════════════╝</>');
        $this->newLine();

        $this->info('Quick Start');
        $this->newLine();

        $commands = [
            ['./openclawpm doctor --fix', 'Check your environment'],
            ['./openclawpm provision <name>', 'Create a new PM Agent'],
            ['./openclawpm chat <name>', 'Start chatting'],
            ['./openclawpm verify <name>', 'Check agent health'],
            ['./openclawpm destroy <name>', 'Remove an agent'],
        ];

        foreach ($commands as [$cmd, $desc]) {
            $this->line(sprintf('  <fg=yellow>%s</>', str_pad($cmd, 35)).$desc);
        }

        $this->newLine();
        $this->comment('Examples:');
        $this->line('  ./openclawpm provision my-pm-agent');
        $this->line('  ./openclawpm chat my-pm-agent');
        $this->newLine();

        $this->comment('Help:');
        $this->line('  ./openclawpm list            Show all commands');
        $this->line('  ./openclawpm help <command>  Show command help');
        $this->newLine();

        return 0;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // no schedule
    }
}
