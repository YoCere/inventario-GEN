<?php

namespace App\Services\Receipt;

use App\Models\Setting;
use App\Services\Agent\CostTracker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptParser
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    private const PROMPT = <<<'TXT'
Eres un extractor de datos de recibos/facturas de compra de un negocio.
Analiza la imagen y devuelve ÚNICAMENTE un objeto JSON válido (sin texto extra, sin markdown) con esta forma exacta:
{"purchase_date":"YYYY-MM-DD o null","supplier_name":"nombre o null","items":[{"raw_name":"texto del producto tal como aparece","quantity":entero,"unit_price":precio_unitario_decimal,"line_total":importe_total_de_la_linea_decimal}]}

Cómo leer las columnas (clave para no equivocarte con los precios):
- Un recibo suele tener: CANTIDAD, PRECIO UNITARIO (precio de UNA sola unidad), DESCUENTO, e IMPORTE/SUBTOTAL (total de la línea = cantidad × precio unitario).
- "unit_price" = PRECIO POR UNA UNIDAD (la columna "Precio"/"P. Unit"), NUNCA el importe total de la línea.
- "line_total" = el IMPORTE/SUBTOTAL total de esa línea (lo que se paga por toda la cantidad). Si no existe esa columna, pon line_total = unit_price × quantity.
- Lee los números con MUCHO cuidado y respeta los decimales. Ej: si el precio unitario es 2,53 (o 2.53) son "dos con 53/100" → 2.53; NO es 253 ni 30.
- El separador decimal puede ser coma o punto; interpreta el valor numérico real (2,53 equivale a 2.53).

Ejemplo de una línea con cantidad 50, precio unitario 2.53, importe 126.50:
{"raw_name":"VIDRIO NORMAL 0.33mm","quantity":50,"unit_price":2.53,"line_total":126.50}

Reglas:
- quantity es entero (si no hay cantidad clara, usa 1).
- Si un dato no aparece, usa null (para fecha/proveedor).
- No inventes productos que no estén en el recibo.
TXT;

    public function __construct(private CostTracker $costTracker) {}

    public function parse(UploadedFile $image): ReceiptData
    {
        if (! $this->costTracker->withinDailyLimit()) {
            throw new ReceiptParseException('Límite diario de costo IA alcanzado.');
        }

        $provider = Setting::get('ai_provider', 'anthropic');
        $base64   = base64_encode(file_get_contents($image->getRealPath()));
        $mime     = $image->getMimeType() ?: 'image/jpeg';

        $raw = $provider === 'openai_compatible'
            ? $this->callOpenAi($base64, $mime)
            : $this->callAnthropic($base64, $mime);

        return $this->toReceiptData($raw);
    }

    private function callAnthropic(string $base64, string $mime): string
    {
        $apiKey = (string) Setting::get('anthropic_api_key', '');
        if ($apiKey === '') {
            throw new ReceiptParseException('Configura la API key de Anthropic en Ajustes IA.');
        }
        $model = (string) Setting::get('ai_model', 'claude-haiku-4-5-20251001');

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post(self::ANTHROPIC_URL, [
                'model'       => $model,
                'max_tokens'  => 2048,
                'temperature' => 0, // extracción determinista
                'messages'    => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
            ]);
        } catch (ConnectionException $e) {
            Log::error('ReceiptParser Anthropic connection error', ['error' => $e->getMessage()]);
            throw new ReceiptParseException('No se pudo conectar con el servicio de IA (timeout o el servidor no tiene salida a api.anthropic.com).');
        }

        if ($response->failed()) {
            Log::error('ReceiptParser Anthropic error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('La IA rechazó la solicitud (HTTP ' . $response->status() . '). Revisa la API key y que el modelo (' . $model . ') soporte imágenes.');
        }

        $usage = $response->json('usage', []);
        $this->costTracker->record(
            userId: auth()->id(), chatId: null, model: $model, channel: 'web',
            action: 'receipt.parse',
            tokensIn: $usage['input_tokens'] ?? 0, tokensOut: $usage['output_tokens'] ?? 0,
            summary: 'parse recibo compra',
        );

        $parts = collect($response->json('content', []))
            ->where('type', 'text')->pluck('text')->implode("\n");
        return trim($parts);
    }

    private function callOpenAi(string $base64, string $mime): string
    {
        $apiKey = (string) Setting::get('openai_api_key', '');
        if ($apiKey === '') {
            throw new ReceiptParseException('Configura la API key de IA en Ajustes IA.');
        }
        $model   = (string) Setting::get('ai_model', 'gpt-4o-mini');
        // Mismo manejo que AgentService: ai_api_base_url puede estar guardado como
        // string vacío (no null), así que el default de Setting::get NO aplica.
        // Tratamos vacío como "usa el default" para no armar una URL rota.
        $rawBase = trim((string) Setting::get('ai_api_base_url', ''));
        $baseUrl = rtrim($rawBase !== '' ? $rawBase : 'https://api.openai.com/v1', '/');

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->timeout(60)->post($baseUrl . '/chat/completions', [
                    'model'       => $model,
                    'max_tokens'  => 2048,
                    'temperature' => 0, // extracción determinista
                    'messages'    => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => self::PROMPT],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                        ],
                    ]],
                ]);
        } catch (ConnectionException $e) {
            Log::error('ReceiptParser OpenAI connection error', ['error' => $e->getMessage()]);
            throw new ReceiptParseException('No se pudo conectar con el servicio de IA (timeout o el servidor no tiene salida a ' . $baseUrl . ').');
        }

        if ($response->failed()) {
            Log::error('ReceiptParser OpenAI error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('La IA rechazó la solicitud (HTTP ' . $response->status() . '). Revisa la API key, base URL y que el modelo (' . $model . ') soporte imágenes.');
        }

        $usage = $response->json('usage', []);
        $this->costTracker->record(
            userId: auth()->id(), chatId: null, model: $model, channel: 'web',
            action: 'receipt.parse',
            tokensIn: $usage['prompt_tokens'] ?? 0, tokensOut: $usage['completion_tokens'] ?? 0,
            summary: 'parse recibo compra',
        );

        return (string) $response->json('choices.0.message.content', '');
    }

    private function toReceiptData(string $raw): ReceiptData
    {
        if (! preg_match('/\{.*\}/s', $raw, $m)) {
            throw new ReceiptParseException('La IA no devolvió datos legibles del recibo.');
        }
        $json = json_decode($m[0], true);
        if (! is_array($json) || ! isset($json['items']) || ! is_array($json['items'])) {
            throw new ReceiptParseException('La IA no devolvió datos legibles del recibo.');
        }

        $lines = [];
        foreach ($json['items'] as $item) {
            // Defensivo: la IA a veces devuelve items como strings u otra forma.
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['raw_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty       = max(1, (int) ($item['quantity'] ?? 1));
            $unit      = max(0.0, (float) ($item['unit_price'] ?? 0));
            $lineTotal = max(0.0, (float) ($item['line_total'] ?? 0));

            // Auto-corrección: si unit_price y line_total son inconsistentes
            // (la IA leyó mal la columna de precio), confiar en line_total/cantidad,
            // que es el triple auto-consistente más fiable. Tolerancia 2%.
            $chosen = $unit;
            $unitFromTotal = ($lineTotal > 0 && $qty > 0) ? $lineTotal / $qty : 0.0;
            if ($unitFromTotal > 0) {
                if ($unit <= 0) {
                    $chosen = $unitFromTotal;
                } else {
                    $expectedTotal = $unit * $qty;
                    $ref = max($expectedTotal, $lineTotal);
                    if ($ref > 0 && abs($expectedTotal - $lineTotal) / $ref > 0.02) {
                        $chosen = $unitFromTotal;
                    }
                }
            }

            $price = max(0, (int) round($chosen * 100));
            $lines[] = new ReceiptLine($name, $qty, $price);
        }

        $date = $json['purchase_date'] ?? null;
        $date = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : null;

        $supplier = $json['supplier_name'] ?? null;
        $supplier = (is_string($supplier) && trim($supplier) !== '') ? trim($supplier) : null;

        return new ReceiptData($date, $supplier, $lines);
    }
}
