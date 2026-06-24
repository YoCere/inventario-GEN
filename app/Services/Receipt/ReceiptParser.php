<?php

namespace App\Services\Receipt;

use App\Models\Setting;
use App\Services\Agent\CostTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptParser
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    private const PROMPT = <<<'TXT'
Eres un extractor de datos de recibos/facturas de compra de un negocio.
Analiza la imagen y devuelve ÚNICAMENTE un objeto JSON válido (sin texto extra, sin markdown) con esta forma exacta:
{"purchase_date":"YYYY-MM-DD o null","supplier_name":"nombre o null","items":[{"raw_name":"texto del producto tal como aparece","quantity":entero,"unit_price":precio_unitario_decimal}]}
Reglas:
- unit_price es el precio por unidad (no el subtotal de la línea) en la moneda del recibo, como número decimal (ej 1500.50).
- quantity es entero (si no hay cantidad clara, usa 1).
- Si un dato no aparece, usa null (para fecha/proveedor) o tu mejor estimación para items.
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

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_URL, [
            'model'      => $model,
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text', 'text' => self::PROMPT],
                ],
            ]],
        ]);

        if ($response->failed()) {
            Log::error('ReceiptParser Anthropic error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('Error al leer el recibo con IA. Verifica que el modelo configurado soporte imágenes (ej. Claude).');
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
        $baseUrl = rtrim((string) Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');

        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
            ->timeout(60)->post($baseUrl . '/chat/completions', [
                'model'      => $model,
                'max_tokens' => 2048,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                    ],
                ]],
            ]);

        if ($response->failed()) {
            Log::error('ReceiptParser OpenAI error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('Error al leer el recibo con IA. Verifica que el modelo configurado soporte imágenes.');
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
            $name = trim((string) ($item['raw_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty   = max(1, (int) ($item['quantity'] ?? 1));
            $price = (int) round(((float) ($item['unit_price'] ?? 0)) * 100);
            $lines[] = new ReceiptLine($name, $qty, $price);
        }

        $date = $json['purchase_date'] ?? null;
        $date = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : null;

        $supplier = $json['supplier_name'] ?? null;
        $supplier = (is_string($supplier) && trim($supplier) !== '') ? trim($supplier) : null;

        return new ReceiptData($date, $supplier, $lines);
    }
}
