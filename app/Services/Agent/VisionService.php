<?php

namespace App\Services\Agent;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda de productos por imagen vía OpenAI Vision (o compatible).
 *
 * Flujo:
 *   1. Recibe binario de imagen (foto del cliente).
 *   2. Envía a OpenAI Vision (gpt-4o-mini por default — barato + buena precisión).
 *   3. Recibe descripción en lenguaje natural en español.
 *   4. Caller usa esa descripción como query para ProductSearchService (fuzzy + IA).
 *
 * Costo aproximado: USD 0.001–0.003 por imagen con gpt-4o-mini.
 *
 * Activación: requiere ai_vision_enabled='1' + openai_api_key configurado.
 */
class VisionService
{
    public function isEnabled(): bool
    {
        return Setting::get('ai_vision_enabled') === '1'
            && !empty(Setting::get('openai_api_key', ''));
    }

    /**
     * Describe brevemente un producto a partir de una imagen.
     *
     * @param string $imageBinary Binario crudo de la imagen (jpeg/png/webp)
     * @param string|null $mimeType MIME detectado del binario (opcional, default jpeg)
     * @return string|null Descripción en español o null si falla / deshabilitado
     */
    public function describeProductImage(string $imageBinary, ?string $mimeType = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $apiKey = Setting::get('openai_api_key', '');
        $baseUrl = rtrim(Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');
        $model = Setting::get('ai_vision_model', 'gpt-4o-mini');

        // Detectar mime si no se pasó. La mayoría de fotos Telegram son JPEG.
        if ($mimeType === null) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageBinary) ?: 'image/jpeg';
            finfo_close($finfo);
        }

        $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageBinary);

        $prompt = <<<PROMPT
Identifica el producto en esta imagen y descríbelo en español en una sola línea de máximo 15 palabras.

REGLAS:
- Solo la descripción, sin frases introductorias ("La imagen muestra…", "Veo…", etc.).
- Si hay marca o modelo visible, inclúyelo (ej: "Logitech G502", "Coca-Cola 2L").
- Incluye tipo de producto, color principal y características obvias.
- Si no parece un producto vendible, responde literalmente: NO_PRODUCT.

EJEMPLOS DE BUENAS RESPUESTAS:
- Mouse gamer Logitech G502 negro con luces RGB
- Botella Coca-Cola 2 litros sabor original
- Audífonos JBL Tune 510 inalámbricos azules
- Cafetera eléctrica negra 12 tazas con jarra de vidrio
PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(30)->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'max_tokens' => 80,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                        ],
                    ],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('Vision API failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $text = trim((string) $response->json('choices.0.message.content', ''));

            if ($text === '' || str_contains($text, 'NO_PRODUCT')) {
                return null;
            }

            return $text;
        } catch (\Throwable $e) {
            Log::error('Vision service error', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
