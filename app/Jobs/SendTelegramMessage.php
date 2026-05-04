<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Facades\Log;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $chatId,
        public string $message,
        public string $parseMode = 'HTML',
    ) {
        $this->onQueue('default');
    }

    public function handle(TelegramService $service): void
    {
        try {
            $service->sendMessage($this->chatId, $this->message, $this->parseMode);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
