<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TelegramStopCommand extends Command
{
    protected $signature = 'telegram:stop';
    protected $description = 'Stop the running telegram:poll process';

    public function handle(): int
    {
        $pidFile = storage_path('framework/telegram-poll.pid');
        $stopFile = storage_path('framework/telegram-poll.stop');

        if (!file_exists($pidFile)) {
            $this->warn('No running telegram:poll process found.');
            return Command::SUCCESS;
        }

        $pid = trim((string) file_get_contents($pidFile));

        touch($stopFile);

        $this->info("Stop signal sent to PID {$pid}. Process will exit after the current polling cycle.");

        return Command::SUCCESS;
    }
}
