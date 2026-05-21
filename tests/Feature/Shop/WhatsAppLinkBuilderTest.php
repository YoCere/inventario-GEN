<?php

namespace Tests\Feature\Shop;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Services\WhatsAppLinkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppLinkBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_wa_me_url_with_sanitized_phone_and_encoded_message(): void
    {
        Setting::set('shop_whatsapp_number', '+591 700-12345');
        Setting::set('shop_business_name', 'Mi Tienda');
        Setting::set('shop_currency_symbol', 'Bs.');

        $user = User::factory()->create();
        $sale = Sale::create([
            'invoice_number' => 'INV-TEST-001',
            'customer_id' => null,
            'buyer_name' => 'Juan Pérez',
            'buyer_phone' => '70012345',
            'created_by' => $user->id,
            'sale_date' => now(),
            'status' => SaleStatus::PENDING,
            'payment_method' => PaymentMethod::CASH,
            'source' => 'web',
            'subtotal' => 6500,
            'global_discount' => 0,
            'total_discount' => 0,
            'total' => 6500,
            'cash_received' => 0,
            'change' => 0,
        ]);

        $url = app(WhatsAppLinkBuilder::class)->build($sale);

        $this->assertStringStartsWith('https://wa.me/59170012345?text=', $url);

        $decoded = rawurldecode(substr($url, strpos($url, '?text=') + 6));
        $this->assertStringContainsString('Mi Tienda', $decoded);
        $this->assertStringContainsString('INV-TEST-001', $decoded);
        $this->assertStringContainsString('Juan Pérez', $decoded);
        $this->assertStringContainsString('70012345', $decoded);
        $this->assertStringContainsString('Bs.', $decoded);
    }

    public function test_falls_back_when_no_whatsapp_number_configured(): void
    {
        Setting::set('shop_whatsapp_number', '');

        $user = User::factory()->create();
        $sale = Sale::create([
            'invoice_number' => 'INV-TEST-002',
            'created_by' => $user->id,
            'sale_date' => now(),
            'status' => SaleStatus::PENDING,
            'payment_method' => PaymentMethod::CASH,
            'source' => 'web',
            'subtotal' => 1000,
            'global_discount' => 0,
            'total_discount' => 0,
            'total' => 1000,
            'cash_received' => 0,
            'change' => 0,
        ]);

        $url = app(WhatsAppLinkBuilder::class)->build($sale);

        // Sin número, abre el selector de contacto de WhatsApp.
        $this->assertStringStartsWith('https://wa.me/?text=', $url);
    }
}
