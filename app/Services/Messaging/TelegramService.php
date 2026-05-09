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

    public function sendMessage(string $chatId, string $text, string $parseMode = 'HTML'): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::post("{$this->apiUrl}{$this->botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ]);

        return $response->json();
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

    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        if (!$this->botToken) {
            throw new \Exception('Telegram bot token not configured');
        }

        $response = Http::timeout(35)->post("{$this->apiUrl}{$this->botToken}/getUpdates", [
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
