<?php

namespace Tests\Feature\Sales;

use App\Livewire\Dashboard\Dashboard;
use App\Livewire\Sales\SalesTable;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BusinessTime;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El "día" del negocio es el día local (America/La_Paz, UTC-4), no el día UTC.
 *
 * Cada caso congela el reloj en un instante UTC que YA pertenece al día UTC
 * siguiente mientras en La Paz todavía es la noche anterior. Antes de la corrección
 * esas ventas se contaban para el día siguiente y el cierre de caja no cuadraba.
 */
class BusinessDayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-10-06 01:00 UTC = 2026-10-05 21:00 en La Paz. */
    private const UTC_21_LOCAL = '2026-10-06 01:00:00';

    /** 2026-10-06 03:30 UTC = 2026-10-05 23:30 en La Paz. */
    private const UTC_2330_LOCAL = '2026-10-06 03:30:00';

    private const LOCAL_DAY = '2026-10-05';
    private const UTC_DAY = '2026-10-06';

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        Setting::set('business_timezone', 'America/La_Paz');

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole('admin');

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 10000,
            'purchase_price' => 6000,
            'quantity'       => 0,
        ]);

        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $location->id,
            'quantity'    => 50,
        ]);
        $this->product->update(['quantity' => 50]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /** Venta por el POS: el controlador pone la fecha, el navegador ya no la manda. */
    private function sellFromPos(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('sales.store'), [
                'payment_method'  => 'cash',
                'status'          => 'completed',
                'cash_received'   => 10000,
                'change'          => 0,
                'global_discount' => 0,
                'items'           => [[
                    'product_id' => $this->product->id,
                    'quantity'   => 1,
                    'unit_price' => 10000,
                    'discount'   => 0,
                ]],
            ])
            ->assertCreated();
    }

    // -------------------------------------------------------------------------
    // POS
    // -------------------------------------------------------------------------

    public function test_el_pos_guarda_la_venta_con_la_fecha_local_del_negocio(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        $this->sellFromPos();

        $sale = Sale::firstOrFail();

        $this->assertSame(self::LOCAL_DAY . ' 21:00:00', $sale->sale_date->toDateTimeString());
        // created_at sigue siendo el instante UTC real: no se reinterpretan timestamps.
        $this->assertSame(self::UTC_DAY . ' 01:00:00', $sale->created_at->toDateTimeString());
    }

    /** El ingreso de caja y el asiento contable heredan el día local, no el UTC. */
    public function test_la_venta_nocturna_arrastra_el_dia_local_a_caja_y_al_asiento(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        $this->sellFromPos();

        $sale = Sale::firstOrFail();

        $this->assertDatabaseHas('finance_transactions', [
            'reference_type'   => Sale::class,
            'reference_id'     => $sale->id,
            'transaction_date' => self::LOCAL_DAY . ' 00:00:00',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Sale::class,
            'source_id'   => $sale->id,
            'entry_date'  => self::LOCAL_DAY . ' 00:00:00',
        ]);
    }

    public function test_el_pos_a_las_2330_locales_sigue_guardando_el_dia_local(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_2330_LOCAL, 'UTC'));

        $this->sellFromPos();

        $this->assertSame(self::LOCAL_DAY . ' 23:30:00', Sale::firstOrFail()->sale_date->toDateTimeString());
    }

    // -------------------------------------------------------------------------
    // Dashboard ("Vendiste" de Hoy)
    // -------------------------------------------------------------------------

    public function test_venta_de_las_21_locales_cuenta_para_el_dia_local_en_el_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        $this->sellFromPos();

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSet('dateFilter', 'today')
            ->assertSet('stats.sales_count', 1)
            ->assertSet('stats.total_sales', 10000.0);
    }

    public function test_venta_de_las_2330_locales_cuenta_para_el_dia_local_en_el_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_2330_LOCAL, 'UTC'));

        $this->sellFromPos();

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSet('stats.sales_count', 1)
            ->assertSet('stats.total_sales', 10000.0);
    }

    public function test_la_barra_de_hoy_del_dashboard_es_el_dia_local(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        $this->sellFromPos();

        $weekSales = Livewire::actingAs($this->admin)->test(Dashboard::class)->get('weekSales');

        $this->assertSame(10000, (int) $weekSales[self::LOCAL_DAY]);
        $this->assertArrayNotHasKey(self::UTC_DAY, $weekSales, 'El día UTC no debería existir en la serie local.');
    }

    public function test_al_dia_siguiente_la_venta_nocturna_ya_no_cuenta_como_hoy(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));
        $this->sellFromPos();

        // Mediodía local del día siguiente: la venta de anoche pertenece a "Ayer".
        Carbon::setTestNow(Carbon::parse('2026-10-06 16:00:00', 'UTC'));

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSet('stats.sales_count', 0)
            ->assertSet('stats.total_sales', 0.0);
    }

    // -------------------------------------------------------------------------
    // Filtro "Hoy" del listado de ventas
    // -------------------------------------------------------------------------

    public function test_filtro_hoy_del_listado_de_ventas_usa_el_dia_local(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        $this->sellFromPos();
        $invoice = Sale::firstOrFail()->invoice_number;

        Livewire::actingAs($this->admin)->test(SalesTable::class)
            ->set('filters', ['select' => ['date_period' => 'today']])
            ->assertSee($invoice);
    }

    public function test_filtro_ayer_del_listado_encuentra_la_venta_nocturna_al_dia_siguiente(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));
        $this->sellFromPos();
        $invoice = Sale::firstOrFail()->invoice_number;

        Carbon::setTestNow(Carbon::parse('2026-10-06 16:00:00', 'UTC'));

        Livewire::actingAs($this->admin)->test(SalesTable::class)
            ->set('filters', ['select' => ['date_period' => 'yesterday']])
            ->assertSee($invoice);

        Livewire::actingAs($this->admin)->test(SalesTable::class)
            ->set('filters', ['select' => ['date_period' => 'today']])
            ->assertDontSee($invoice);
    }

    // -------------------------------------------------------------------------
    // El corte lo define el Setting, no una constante
    // -------------------------------------------------------------------------

    public function test_cambiar_el_setting_business_timezone_mueve_el_corte_del_dia(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        // La Paz (UTC-4): sigue siendo el 5 por la noche.
        $this->assertSame(self::LOCAL_DAY, BusinessTime::todayString());

        // Tokio (UTC+9): el mismo instante ya es el 6 por la mañana.
        Setting::set('business_timezone', 'Asia/Tokyo');
        $this->assertSame(self::UTC_DAY, BusinessTime::todayString());

        // Sin setting cae a la zona de la app (UTC): el 6, igual que now().
        Setting::set('business_timezone', '');
        $this->assertSame(self::UTC_DAY, BusinessTime::todayString());
        $this->assertSame(config('app.timezone'), BusinessTime::timezone());
    }

    public function test_el_setting_business_timezone_decide_a_que_dia_cuenta_la_venta(): void
    {
        Carbon::setTestNow(Carbon::parse(self::UTC_21_LOCAL, 'UTC'));

        // Con el negocio en Tokio, ese instante cae el 6 y la venta se guarda con esa fecha.
        Setting::set('business_timezone', 'Asia/Tokyo');

        $this->sellFromPos();

        $this->assertSame(self::UTC_DAY, Sale::firstOrFail()->sale_date->toDateString());

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSet('stats.sales_count', 1);
    }
}
