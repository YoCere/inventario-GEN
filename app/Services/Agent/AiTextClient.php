<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Una pregunta corta al modelo y su respuesta en texto. Nada más.
 *
 * Existe para los casos en que hace falta "que piense un segundo" sin montar
 * toda la maquinaria del agente (herramientas, historial, costos por turno):
 * elegir una opción de una lista, entender a qué se refiere una frase suelta.
 *
 * Deliberadamente simple y deliberadamente a prueba de fallas: si no hay clave,
 * si el proveedor se cae o si tarda demasiado, devuelve cadena vacía y quien
 * llama sigue con su camino de siempre. La IA acá suma, nunca es imprescindible.
 */
class AiTextClient
{
    /** Más de esto y deja de ser una ayuda: la persona ya se cansó de esperar. */
    private const TIMEOUT_SEGUNDOS = 8;

    public function disponible(): bool
    {
        return $this->claveDelProveedor() !== '';
    }

    /**
     * Devuelve la respuesta del modelo, o cadena vacía si no se pudo obtener.
     */
    public function preguntar(string $prompt, int $maxTokens = 60): string
    {
        if (! $this->disponible()) {
            return '';
        }

        try {
            return $this->esAnthropic()
                ? $this->preguntarAnthropic($prompt, $maxTokens)
                : $this->preguntarOpenAi($prompt, $maxTokens);
        } catch (\Throwable $e) {
            Log::warning('Consulta corta a la IA fallida', ['error' => $e->getMessage()]);

            return '';
        }
    }

    private function preguntarAnthropic(string $prompt, int $maxTokens): string
    {
        $respuesta = Http::withHeaders([
            'x-api-key' => Setting::get('anthropic_api_key', ''),
            'anthropic-version' => '2023-06-01',
        ])->timeout(self::TIMEOUT_SEGUNDOS)->post('https://api.anthropic.com/v1/messages', [
            'model' => Setting::get('ai_model', 'claude-haiku-4-5-20251001'),
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($respuesta->failed()) {
            Log::warning('Consulta corta a la IA rechazada', ['status' => $respuesta->status()]);

            return '';
        }

        return trim((string) $respuesta->json('content.0.text', ''));
    }

    private function preguntarOpenAi(string $prompt, int $maxTokens): string
    {
        // El ajuste se guarda como texto vacío cuando el proveedor es OpenAI
        // directo, así que el valor por defecto de Setting::get no aplica.
        $base = trim((string) Setting::get('ai_api_base_url', ''));
        $base = rtrim($base !== '' ? $base : 'https://api.openai.com/v1', '/');

        $respuesta = Http::withHeaders([
            'Authorization' => 'Bearer ' . Setting::get('openai_api_key', ''),
        ])->timeout(self::TIMEOUT_SEGUNDOS)->post($base . '/chat/completions', [
            'model' => Setting::get('ai_model', 'gpt-4o-mini'),
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($respuesta->failed()) {
            Log::warning('Consulta corta a la IA rechazada', ['status' => $respuesta->status()]);

            return '';
        }

        return trim((string) $respuesta->json('choices.0.message.content', ''));
    }

    private function esAnthropic(): bool
    {
        return Setting::get('ai_provider', 'anthropic') === 'anthropic';
    }

    private function claveDelProveedor(): string
    {
        return trim((string) Setting::get(
            $this->esAnthropic() ? 'anthropic_api_key' : 'openai_api_key',
            ''
        ));
    }
}
