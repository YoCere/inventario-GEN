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
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        $expectedSecret = (string) Setting::get('telegram_webhook_secret');

        // hash_equals compara en tiempo constante. Con `!==` el tiempo de
        // respuesta delata cuántos caracteres del secreto se acertaron, y con eso
        // se adivina carácter por carácter en vez de a ciegas. El primer
        // argumento es el secreto conocido y el segundo el que llega de afuera.
        // El secreto vacío se descarta antes: hash_equals('','') daría true y el
        // webhook quedaría abierto justo en la instalación sin configurar.
        if ($expectedSecret === '' || !hash_equals($expectedSecret, $secret)) {
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
