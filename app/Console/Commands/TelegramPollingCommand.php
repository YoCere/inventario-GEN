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

    public function handle(TelegramService $telegram, BotHandler $handler): int
    {
        $this->info('Starting Telegram polling... Press Ctrl+C to stop');

        $offset = 0;

        while (true) {
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

                // Clean expired conversations
                TelegramConversation::cleanExpired();

            } catch (\Exception $e) {
                $this->error('Polling error: ' . $e->getMessage());
                Log::error('Telegram polling error', ['error' => $e->getMessage()]);
                sleep(5);
            }
        }
    }
}
