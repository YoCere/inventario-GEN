<?php

namespace Tests\Feature\Receipt;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseReceiptEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_matched_and_unmatched_from_receipt(): void
    {
        $user = User::factory()->staff()->create();
        Product::factory()->create(['name' => 'Coca Cola 2L', 'sku' => 'CC2L', 'is_active' => true]);

        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"purchase_date":"2026-06-20","supplier_name":null,"items":[{"raw_name":"Coca Cola 2L","quantity":12,"unit_price":1500.00},{"raw_name":"Producto XYZ","quantity":2,"unit_price":50.00}]}']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::image('recibo.jpg', 600, 800),
            ]);

        $response->assertOk();
        $response->assertJsonPath('purchase_date', '2026-06-20');
        $response->assertJsonCount(1, 'matched');
        $response->assertJsonCount(1, 'unmatched');
        $response->assertJsonPath('matched.0.unit_price', 150000);
    }

    public function test_merges_multiple_receipt_pages(): void
    {
        $user = User::factory()->staff()->create();
        Product::factory()->create(['name' => 'Coca Cola 2L', 'sku' => 'CC2L', 'is_active' => true]);

        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        // Página 1: Coca Cola x12. Página 2: Coca Cola x6 (mismo producto → suma).
        Http::fakeSequence()
            ->push(['content' => [['type' => 'text', 'text' => '{"items":[{"raw_name":"Coca Cola 2L","quantity":12,"unit_price":1500.00,"line_total":18000.00}]}']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]])
            ->push(['content' => [['type' => 'text', 'text' => '{"items":[{"raw_name":"Coca Cola 2L","quantity":6,"unit_price":1500.00,"line_total":9000.00}]}']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]]);

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipts' => [
                    File::image('p1.jpg', 600, 800),
                    File::image('p2.jpg', 600, 800),
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'matched');
        $response->assertJsonPath('matched.0.quantity', 18); // 12 + 6
    }

    public function test_rejects_non_image(): void
    {
        $user = User::factory()->staff()->create();

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::create('document.pdf', 10),
            ]);

        $response->assertStatus(422);
    }

    public function test_parse_error_returns_json_error(): void
    {
        $user = User::factory()->staff()->create();
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', '');

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::image('recibo.jpg', 600, 800),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }
}
