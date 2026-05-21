<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TtsService
{
    /**
     * Synthesize text to audio.
     * Returns ['content' => <binary>, 'filename' => 'reply.ogg'] or null on failure/disabled.
     */
    public function synthesize(string $text): ?array
    {
        if (Setting::get('ai_voice_reply', '0') !== '1') {
            return null;
        }

        // Strip HTML tags — agent replies may contain <b>, <i>, etc.
        $plain = strip_tags($text);
        if (empty(trim($plain))) {
            return null;
        }

        $provider = Setting::get('tts_provider', 'openai');

        return match ($provider) {
            'openai' => $this->synthesizeOpenAi($plain),
            'piper'  => $this->synthesizePiper($plain),
            default  => null,
        };
    }

    private function synthesizeOpenAi(string $text): ?array
    {
        $apiKey = Setting::get('openai_api_key', '');
        if (!$apiKey) {
            Log::warning('TTS OpenAI: openai_api_key not configured');
            return null;
        }

        $model = Setting::get('tts_model', 'tts-1');
        $voice = Setting::get('tts_voice', 'nova');

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->timeout(30)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model'           => $model,
                    'input'           => mb_substr($text, 0, 4096),
                    'voice'           => $voice,
                    'response_format' => 'opus',
                ]);

            if ($response->failed()) {
                Log::warning('TTS OpenAI failed', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return ['content' => $response->body(), 'filename' => 'reply.ogg'];
        } catch (\Throwable $e) {
            Log::warning('TTS OpenAI exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function synthesizePiper(string $text): ?array
    {
        $binary = Setting::get('tts_binary_path', '');
        $model  = Setting::get('tts_model_path', '');

        if (!$binary || !$model) {
            Log::warning('Piper TTS: binary or model path not configured');
            return null;
        }

        if (!file_exists($binary) || !file_exists($model)) {
            Log::warning('Piper TTS: binary or model file not found', ['binary' => $binary, 'model' => $model]);
            return null;
        }

        try {
            $tmpIn  = tempnam(sys_get_temp_dir(), 'tts_in_');
            $tmpOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tts_out_' . uniqid() . '.wav';

            file_put_contents($tmpIn, $text);

            $cmd = sprintf(
                '%s --model %s --output_file %s < %s',
                escapeshellarg($binary),
                escapeshellarg($model),
                escapeshellarg($tmpOut),
                escapeshellarg($tmpIn)
            );

            exec($cmd, $cmdOut, $exitCode);

            @unlink($tmpIn);

            if ($exitCode !== 0 || !file_exists($tmpOut)) {
                Log::warning('Piper TTS failed', ['exit_code' => $exitCode]);
                return null;
            }

            $content = file_get_contents($tmpOut);
            @unlink($tmpOut);

            return ['content' => $content, 'filename' => 'reply.wav'];
        } catch (\Throwable $e) {
            Log::warning('Piper TTS exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
