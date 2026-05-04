<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Models\TelegramConversation;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramService $telegram, BotHandler $handler)
    {
        // Validate webhook secret
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expectedSecret = Setting::get('telegram_webhook_secret');

        if (!$expectedSecret || $secret !== $expectedSecret) {
            Log::warning('Invalid Telegram webhook secret');
            return response()->json(['ok' => false], 401);
        }

        try {
            $update = $request->json()->all();

            // Clean expired conversations
            TelegramConversation::cleanExpired();

            // Dispatch to handler
            $handler->dispatch($update);

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error', ['error' => $e->getMessage()]);
            return response()->json(['ok' => false], 500);
        }
    }
}
