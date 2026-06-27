<?php

namespace Tests\Feature\Receipt;

use App\Models\Setting;
use App\Services\Receipt\ReceiptParser;
use App\Services\Receipt\ReceiptParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptParserTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImage(): File
    {
        return File::image('recibo.jpg', 600, 800);
    }

    public function test_anthropic_returns_structured_data_with_prices_in_cents(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');
        Setting::set('ai_model', 'claude-haiku-4-5-20251001');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => 'Aquí está: {"purchase_date":"2026-06-20","supplier_name":"Distribuidora X","items":[{"raw_name":"Coca Cola 2L","quantity":12,"unit_price":1500.50}]}',
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertSame('2026-06-20', $data->purchaseDate);
        $this->assertSame('Distribuidora X', $data->supplierName);
        $this->assertCount(1, $data->lines);
        $this->assertSame('Coca Cola 2L', $data->lines[0]->rawName);
        $this->assertSame(12, $data->lines[0]->quantity);
        $this->assertSame(150050, $data->lines[0]->unitPrice);
    }

    public function test_reconciles_wrong_unit_price_using_line_total(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        // La IA leyó mal el precio unitario (30) pero el importe (126.50) y la
        // cantidad (50) son correctos → debe corregir a 126.50/50 = 2.53.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"purchase_date":null,"supplier_name":null,"items":[{"raw_name":"Vidrio Normal","quantity":50,"unit_price":30,"line_total":126.50}]}']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertSame(253, $data->lines[0]->unitPrice); // 2.53 en céntimos
    }

    public function test_keeps_unit_price_when_consistent_with_line_total(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        // unit_price 2.53 × 50 = 126.50 == line_total → respeta unit_price.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"purchase_date":null,"supplier_name":null,"items":[{"raw_name":"Vidrio Normal","quantity":50,"unit_price":2.53,"line_total":126.50}]}']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertSame(253, $data->lines[0]->unitPrice);
    }

    public function test_throws_when_no_valid_json_in_response(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'No pude leer el recibo.']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->expectException(ReceiptParseException::class);
        app(ReceiptParser::class)->parse($this->fakeImage());
    }

    public function test_throws_when_api_key_missing(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', '');

        $this->expectException(ReceiptParseException::class);
        app(ReceiptParser::class)->parse($this->fakeImage());
    }

    public function test_openai_compatible_uses_image_url_data_uri(): void
    {
        Setting::set('ai_provider', 'openai_compatible');
        Setting::set('openai_api_key', 'sk-test');
        Setting::set('ai_model', 'gpt-4o-mini');
        Setting::set('ai_api_base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"purchase_date":null,"supplier_name":null,"items":[{"raw_name":"Pan","quantity":3,"unit_price":2.00}]}']]],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertNull($data->purchaseDate);
        $this->assertSame(200, $data->lines[0]->unitPrice);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][0]['content'];
            $hasImage = collect($content)->contains(fn ($c) =>
                ($c['type'] ?? '') === 'image_url'
                && str_starts_with($c['image_url']['url'] ?? '', 'data:image/')
            );
            return $hasImage;
        });
    }
}
