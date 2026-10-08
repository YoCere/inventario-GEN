<?php

namespace Tests\Feature\Shop;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Services\WhatsAppLinkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El interruptor "Mostrar precios en la tienda" sirve para que la competencia no
 * copie la lista. No alcanza con esconder el número: el precio se deduce igual
 * desde el filtro, el orden, el buscador, el carrito o el mensaje de WhatsApp.
 */
class ShopPriceVisibilityTest extends TestCase
{
    use EnablesShop;
    use RefreshDatabase;

    private const PRECIO_CENTAVOS = 6500;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableShop();
        Setting::set('currency_symbol', 'Bs');

        $category = Category::factory()->create(['name' => 'Bebidas']);
        $this->product = Product::factory()->create([
            'name' => 'Coca-Cola 2 L',
            'selling_price' => self::PRECIO_CENTAVOS,
            'quantity' => 10,
            'is_active' => true,
            'is_public' => true,
            'category_id' => $category->id,
        ]);
    }

    private function ocultarPrecios(): void
    {
        Setting::set('shop_show_prices', '0');
    }

    public function test_por_defecto_la_tienda_muestra_los_precios(): void
    {
        $this->assertTrue(\App\Shop\ShopSettings::showPrices());

        $this->get(route('shop.catalog'))
            ->assertOk()
            ->assertSee('65.00')
            ->assertSee('Precio: menor a mayor');

        $this->get(route('shop.product', $this->product->slug))
            ->assertOk()
            ->assertSee('65.00');
    }

    public function test_apagado_el_catalogo_no_muestra_precio_ni_lo_deja_en_el_codigo(): void
    {
        $this->ocultarPrecios();

        $response = $this->get(route('shop.catalog'))->assertOk();

        $response->assertDontSee('65.00')
            ->assertSee('Consultá el precio')
            // price_cents en el HTML sería la lista de precios en el código fuente.
            ->assertDontSee('price_cents: ' . self::PRECIO_CENTAVOS, false)
            // Filtro y orden por precio también revelan: tienen que desaparecer.
            ->assertDontSee('Precio: menor a mayor')
            ->assertDontSee('Rango de precio');
    }

    public function test_apagado_la_ficha_del_producto_no_muestra_precio(): void
    {
        $this->ocultarPrecios();

        $this->get(route('shop.product', $this->product->slug))
            ->assertOk()
            ->assertDontSee('65.00')
            ->assertSee('Consultá el precio por WhatsApp')
            ->assertDontSee('price_cents: ' . self::PRECIO_CENTAVOS, false);
    }

    public function test_apagado_el_filtro_por_precio_de_la_url_se_ignora(): void
    {
        $this->ocultarPrecios();

        // Probar rangos a mano permitiría adivinar el precio por descarte.
        $this->get(route('shop.catalog', ['min' => 1000]))
            ->assertOk()
            ->assertSee('Coca-Cola 2 L');

        $this->get(route('shop.catalog', ['max' => 1]))
            ->assertOk()
            ->assertSee('Coca-Cola 2 L');
    }

    public function test_apagado_el_orden_por_precio_de_la_url_no_ordena_por_precio(): void
    {
        $this->ocultarPrecios();

        // Más caro y más nuevo: por defecto va primero, pero ordenando por precio
        // ascendente iría último. Así el test distingue un orden del otro.
        $caro = Product::factory()->create([
            'name' => 'Whisky importado',
            'selling_price' => 99900,
            'quantity' => 5,
            'is_active' => true,
            'is_public' => true,
        ]);

        $pedidoPorPrecio = $this->get(route('shop.catalog', ['sort' => 'price_asc']))->assertOk()->getContent();
        $pedidoPorDefecto = $this->get(route('shop.catalog'))->assertOk()->getContent();

        // Con el interruptor apagado, pedir orden por precio da el mismo listado que
        // el orden por defecto: si ordenara, el barato subiría al primer lugar.
        $this->assertSame(
            $this->ordenDeProductos($pedidoPorDefecto),
            $this->ordenDeProductos($pedidoPorPrecio),
            'El catálogo se ordenó por precio pese a tener los precios ocultos.'
        );
        $this->assertSame([$caro->name, 'Coca-Cola 2 L'], $this->ordenDeProductos($pedidoPorPrecio));
    }

    public function test_apagado_el_buscador_publico_no_devuelve_precios(): void
    {
        $this->ocultarPrecios();

        $json = $this->getJson(route('shop.search', ['q' => 'Coca']))->assertOk()->json();

        foreach ($json['results'] ?? [] as $resultado) {
            $this->assertNull($resultado['price'] ?? null);
            $this->assertNull($resultado['price_cents'] ?? null);
        }
    }

    public function test_apagado_el_mensaje_de_whatsapp_pide_el_precio_en_vez_de_mostrarlo(): void
    {
        $this->ocultarPrecios();

        $mensaje = app(WhatsAppLinkBuilder::class)->composeMessage($this->reservaDeEjemplo());

        $this->assertStringNotContainsString('65.00', $mensaje);
        $this->assertStringNotContainsString('Total:', $mensaje);
        $this->assertStringContainsString('1× Coca-Cola 2 L', $mensaje);
        $this->assertStringContainsString('confirmame el precio', $mensaje);
    }

    public function test_encendido_el_mensaje_de_whatsapp_sigue_llevando_el_total(): void
    {
        $mensaje = app(WhatsAppLinkBuilder::class)->composeMessage($this->reservaDeEjemplo());

        $this->assertStringContainsString('65.00', $mensaje);
        $this->assertStringContainsString('Total:', $mensaje);
    }

    public function test_el_duenio_sigue_viendo_los_montos_en_reservas_web(): void
    {
        $this->ocultarPrecios();
        $this->reservaDeEjemplo();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('shop.admin.reservations'))
            ->assertOk()
            ->assertSee('65.00');
    }

    /** @return array<int, string> nombres de producto en el orden en que aparecen */
    private function ordenDeProductos(string $html): array
    {
        preg_match_all('/<h3[^>]*>\s*([^<]+?)\s*<\/h3>/', $html, $m);

        return array_values(array_filter($m[1], fn ($n) => str_contains($n, 'Coca-Cola') || str_contains($n, 'Whisky')));
    }

    private function reservaDeEjemplo(): Sale
    {
        $user = User::factory()->create();

        $sale = Sale::create([
            'invoice_number' => 'W-000001',
            'buyer_name' => 'María Quispe',
            'buyer_phone' => '70012345',
            'created_by' => $user->id,
            'sale_date' => now(),
            'status' => SaleStatus::PENDING,
            'payment_method' => PaymentMethod::CASH,
            'source' => 'web',
            'subtotal' => self::PRECIO_CENTAVOS,
            'total_discount' => 0,
            'total' => self::PRECIO_CENTAVOS,
            'cash_received' => 0,
            'change' => 0,
            'global_discount' => 0,
            'iva_amount' => 0,
            'it_amount' => 0,
            'wants_invoice' => false,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => self::PRECIO_CENTAVOS,
            'cost_price' => 4000,
            'discount' => 0,
            'subtotal' => self::PRECIO_CENTAVOS,
            'final_price' => self::PRECIO_CENTAVOS,
        ]);

        return $sale->refresh();
    }
}
