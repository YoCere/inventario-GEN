<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda de productos por imagen vía modelos multimodales.
 *
 * Soporta dos proveedores (mismo que el agente principal):
 *   - anthropic       → Claude vision via /v1/messages (default si ai_provider=anthropic).
 *   - openai_compatible → /chat/completions con content image_url (OpenAI, Groq, etc.).
 *
 * Flujo:
 *   1. Recibe binario de imagen (foto del cliente).
 *   2. Envía al proveedor configurado.
 *   3. Recibe descripción en lenguaje natural en español.
 *   4. Caller usa esa descripción como query para ProductSearchService (fuzzy + IA).
 *
 * Activación: requiere ai_vision_enabled='1' + la API key del proveedor activo.
 */
class VisionService
{
    public function isEnabled(): bool
    {
        if (Setting::get('ai_vision_enabled') !== '1') {
            return false;
        }

        return $this->resolveProviderKey() !== null;
    }

    /**
     * Razón legible por la cual el servicio no está disponible. Útil para que el
     * bot devuelva un mensaje accionable en lugar de "nada".
     */
    public function unavailableReason(): string
    {
        if (Setting::get('ai_vision_enabled') !== '1') {
            return 'La búsqueda por imagen está desactivada. Actívala en Configuración → IA → Vision.';
        }

        $provider = Setting::get('ai_provider', 'anthropic');
        $keyName  = $provider === 'openai_compatible' ? 'OpenAI / compatible' : 'Anthropic';
        return "Falta configurar la API key de {$keyName} para usar búsqueda por imagen.";
    }

    /**
     * Describe brevemente un producto a partir de una imagen.
     *
     * @param string $imageBinary Binario crudo de la imagen (jpeg/png/webp)
     * @param string|null $mimeType MIME detectado del binario (opcional, default jpeg)
     * @param string|null $hint Texto opcional del usuario (caption) para guiar la descripción.
     * @return string|null Descripción en español o null si falla / deshabilitado
     */
    public function describeProductImage(string $imageBinary, ?string $mimeType = null, ?string $hint = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if ($mimeType === null) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageBinary) ?: 'image/jpeg';
            finfo_close($finfo);
        }

        $hint = $hint ? trim($hint) : null;
        $prompt = $this->buildPrompt($hint);

        $provider = Setting::get('ai_provider', 'anthropic');

        try {
            $text = $provider === 'openai_compatible'
                ? $this->describeWithOpenAi($imageBinary, $mimeType, $prompt)
                : $this->describeWithAnthropic($imageBinary, $mimeType, $prompt);

            $text = trim((string) $text);
            if ($text === '' || str_contains($text, 'NO_PRODUCT')) {
                return null;
            }

            return $text;
        } catch (\Throwable $e) {
            Log::error('Vision service error', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildPrompt(?string $hint): string
    {
        $base = <<<PROMPT
Eres un asistente de inventario de tienda. Identifica el producto en esta imagen y descríbelo en español en una sola línea de máximo 15 palabras.

REGLAS:
- Solo la descripción, sin frases introductorias ("La imagen muestra…", "Veo…", etc.).
- Si hay marca o modelo visible, inclúyelo SIEMPRE (ej: "Labubu", "Pop Mart", "Squishmallow", "Funko Pop", "Logitech G502").
- Incluye: tipo de producto, marca/colección si aplica, color principal y características obvias.
- Juguetes, figuras, muñecos de peluche, coleccionables y accesorios SON productos vendibles — NO uses NO_PRODUCT para ellos.
- Solo responde NO_PRODUCT si la imagen no contiene ningún objeto físico (ej: fondo vacío, texto puro, persona sin producto).

EJEMPLOS DE BUENAS RESPUESTAS:
- Muñeco peluche Labubu Pop Mart rosado orejas de conejo
- Figura coleccionable Funko Pop Naruto edición especial
- Mouse gamer Logitech G502 negro con luces RGB
- Botella Coca-Cola 2 litros sabor original
- Audífonos JBL Tune 510 inalámbricos azules
- Cafetera eléctrica negra 12 tazas con jarra de vidrio
- Llavero peluche personaje animado multicolor
PROMPT;

        if ($hint) {
            $base .= "\n\nCONTEXTO DEL USUARIO (puede incluir marca o modelo): \"{$hint}\"";
        }

        return $base;
    }

    private function describeWithAnthropic(string $imageBinary, string $mimeType, string $prompt): string
    {
        $apiKey = Setting::get('anthropic_api_key', '');
        $model  = Setting::get('ai_vision_model', '');
        if ($model === '' || str_starts_with($model, 'gpt-')) {
            // Fallback al modelo principal o default vision-capable
            $model = Setting::get('ai_model', 'claude-haiku-4-5-20251001');
        }

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 80,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mimeType,
                            'data'       => base64_encode($imageBinary),
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        if ($response->failed()) {
            Log::warning('Vision API (Anthropic) failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return '';
        }

        return (string) $response->json('content.0.text', '');
    }

    private function describeWithOpenAi(string $imageBinary, string $mimeType, string $prompt): string
    {
        $apiKey  = Setting::get('openai_api_key', '');
        $baseUrl = rtrim(Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');
        $model   = Setting::get('ai_vision_model', 'gpt-4o-mini');

        $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageBinary);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(30)->post($baseUrl . '/chat/completions', [
            'model'       => $model,
            'max_tokens'  => 80,
            'temperature' => 0.2,
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                ],
            ]],
        ]);

        if ($response->failed()) {
            Log::warning('Vision API (OpenAI-compatible) failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return '';
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    /**
     * Retorna el provider activo si tiene API key configurada, null si ninguno
     * está utilizable. Determina si el servicio puede operar.
     */
    private function resolveProviderKey(): ?string
    {
        $provider = Setting::get('ai_provider', 'anthropic');

        if ($provider === 'openai_compatible') {
            return Setting::get('openai_api_key', '') !== '' ? 'openai_compatible' : null;
        }

        return Setting::get('anthropic_api_key', '') !== '' ? 'anthropic' : null;
    }
}
