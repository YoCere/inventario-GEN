<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Setting;

class TelegramService
{
    private string $botToken;
    private string $apiUrl = 'https://api.telegram.org/bot';

    public function __construct()
    {
        $this->botToken = Setting::get('telegram_bot_token', '');
    }

    public function sendChatAction(string $chatId, string $action = 'typing'): array
    {
        if (!$this->botToken) {
            return [];
        }

        $response = Http::post("{$this->apiUrl}{$this->botToken}/sendChatAction", [
            'chat_id' => $chatId,
            'action' => $action,
        ]);

        return $response->json() ?? [];
    }

    /**
     * $inlineKeyboard son las filas de botones (ver App\Support\TelegramKeyboard).
     * Va como último parámetro opcional a propósito: así las decenas de llamadas
     * que ya existen siguen funcionando sin tocarlas.
     */
    public function sendMessage(string $chatId, string $text, string $parseMode = 'HTML', ?array $inlineKeyboard = null): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];

        if (!empty($inlineKeyboard)) {
            // Telegram espera el teclado como JSON dentro de reply_markup.
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        $response = Http::post("{$this->apiUrl}{$this->botToken}/sendMessage", $payload);

        return $response->json();
    }

    /**
     * Confirma a Telegram que se recibió el toque de un botón. Sin esto el
     * botón queda "girando" en el celular de la persona hasta que vence.
     * Best-effort: es solo señal visual, nunca debe tumbar el flujo.
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        if (!$this->botToken) {
            return [];
        }

        $payload = ['callback_query_id' => $callbackQueryId];

        if ($text !== '') {
            $payload['text'] = $text;
            $payload['show_alert'] = $showAlert;
        }

        try {
            $response = Http::post("{$this->apiUrl}{$this->botToken}/answerCallbackQuery", $payload);
            return $response->json() ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Cambia (o quita, pasando null) los botones de un mensaje ya enviado.
     * Se usa para apagar los botones de una acción que ya se ejecutó, de modo
     * que el mensaje viejo no invite a tocarla de nuevo.
     */
    public function editMessageReplyMarkup(string $chatId, int $messageId, ?array $inlineKeyboard = null): array
    {
        if (!$this->botToken) {
            return [];
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard ?? []]),
        ];

        try {
            $response = Http::post("{$this->apiUrl}{$this->botToken}/editMessageReplyMarkup", $payload);
            return $response->json() ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function sendPhoto(string $chatId, string $filePath, string $caption = ''): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $fileContent = Storage::disk('public')->get($filePath);

        $response = Http::attach(
            'photo',
            $fileContent,
            basename($filePath)
        )->post("{$this->apiUrl}{$this->botToken}/sendPhoto", [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);

        return $response->json();
    }

    public function getFile(string $fileId): string
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::get("{$this->apiUrl}{$this->botToken}/getFile", [
            'file_id' => $fileId,
        ]);

        if ($response->failed() || !$response->json('ok')) {
            throw new \Exception('Failed to get file from Telegram');
        }

        return $response->json('result.file_path');
    }

    public function downloadFile(string $filePath): string
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $url = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            throw new \Exception('Failed to download file from Telegram: ' . $response->status());
        }

        return $response->body();
    }

    public function sendVoice(string $chatId, string $audioContent, string $filename = 'reply.ogg'): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $endpoint = str_ends_with($filename, '.wav') ? 'sendAudio' : 'sendVoice';

        $response = Http::attach($endpoint === 'sendVoice' ? 'voice' : 'audio', $audioContent, $filename)
            ->post("{$this->apiUrl}{$this->botToken}/{$endpoint}", [
                'chat_id' => $chatId,
            ]);

        return $response->json() ?? [];
    }

    public function setWebhook(string $url, string $secret): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::post("{$this->apiUrl}{$this->botToken}/setWebhook", [
            'url' => $url,
            'secret_token' => $secret,
        ]);

        return $response->json();
    }

    public function deleteWebhook(): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::post("{$this->apiUrl}{$this->botToken}/deleteWebhook");

        return $response->json();
    }

    public function getUpdates(int $offset = 0, int $timeout = 20): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::timeout($timeout + 8)->post("{$this->apiUrl}{$this->botToken}/getUpdates", [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        if ($response->failed() || !$response->json('ok')) {
            throw new \Exception('Failed to get updates from Telegram');
        }

        return $response->json('result') ?? [];
    }
}
