<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Models\TelegramConversation;
use Illuminate\Support\Facades\Log;

class TelegramPollingCommand extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Start Telegram bot polling (development only)';

    private static function pidFile(): string
    {
        return storage_path('framework/telegram-poll.pid');
    }

    private static function stopFile(): string
    {
        return storage_path('framework/telegram-poll.stop');
    }

    public function handle(TelegramService $telegram, BotHandler $handler): int
    {
        // Reject if another instance is already running
        $pidFile = self::pidFile();
        if (file_exists($pidFile)) {
            $existingPid = trim((string) file_get_contents($pidFile));
            $this->warn("Already running (PID {$existingPid}). Use php artisan telegram:stop to stop it.");
            return Command::FAILURE;
        }

        file_put_contents($pidFile, (string) getmypid());
        @unlink(self::stopFile()); // clear any stale stop flag

        $this->info('Telegram polling started (PID ' . getmypid() . '). Use php artisan telegram:stop to stop.');

        $offset = 0;

        while (true) {
            // Graceful stop check
            if (file_exists(self::stopFile())) {
                @unlink(self::stopFile());
                @unlink($pidFile);
                $this->info('Stop signal received. Exiting.');
                return Command::SUCCESS;
            }

            try {
                $updates = $telegram->getUpdates($offset);

                if (empty($updates)) {
                    sleep(1);
                    continue;
                }

                foreach ($updates as $update) {
                    $handler->dispatch($update);
                    $offset = $update['update_id'] + 1;
                }

                TelegramConversation::cleanExpired();

            } catch (\Exception $e) {
                $this->error('Polling error: ' . $e->getMessage());
                Log::error('Telegram polling error', ['error' => $e->getMessage()]);
                sleep(5);
            }
        }
    }
}
