<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhisperService
{
    /**
     * Transcribe audio bytes to text.
     * Uses the configured OpenAI-compatible endpoint (Groq, OpenAI, etc.).
     */
    public function transcribe(string $audioContent, string $filename, int $durationSeconds = 0): string
    {
        $apiKey = Setting::get('openai_api_key', '');
        if (!$apiKey) {
            throw new \RuntimeException('openai_api_key no configurada para Whisper.');
        }

        // whisper_provider is independent from ai_provider (user may use Anthropic for chat + Groq for STT)
        $whisperProvider = Setting::get('whisper_provider', 'openai');

        switch ($whisperProvider) {
            case 'groq':
                $baseUrl      = 'https://api.groq.com/openai/v1';
                $defaultModel = 'whisper-large-v3-turbo';
                break;
            case 'openai':
            default:
                $baseUrl      = 'https://api.openai.com/v1';
                $defaultModel = 'whisper-1';
                break;
        }

        $model    = Setting::get('whisper_model', '') ?: $defaultModel;
        $language = Setting::get('whisper_language', 'es');

        // .oga is same codec as .ogg but Groq/OpenAI reject the .oga extension
        $filename = preg_replace('/\.oga$/i', '.ogg', $filename ?: 'audio.ogg') ?: 'audio.ogg';

        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
            ->timeout(30)
            ->attach('file', $audioContent, $filename)
            ->post($baseUrl . '/audio/transcriptions', [
                'model'           => $model,
                'language'        => $language,
                'response_format' => 'text',
            ]);

        if ($response->failed()) {
            $err = $response->json('error.message') ?? $response->status();
            Log::error('Whisper transcription failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Error en transcripción: ' . $err);
        }

        return trim($response->body());
    }
}
