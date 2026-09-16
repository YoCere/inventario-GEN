<?php

namespace Tests\Feature\Dashboard;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Livewire\Dashboard\Dashboard;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardInicioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['name' => 'Ana Pérez', 'email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    public function test_staff_ve_ventas_pero_no_cifras_financieras(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Vendiste')
            ->assertSeeText('Nueva venta')
            ->assertDontSeeText('Ganaste')
            ->assertDontSeeText('Movimiento de caja')
            ->assertDontSeeText('Registrar compra')
            ->assertDontSeeText('Registrar gasto');
    }

    public function test_admin_ve_todas_las_cifras_y_atajos(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Ana')
            ->assertSeeText('Ganaste')
            ->assertSeeText('Movimiento de caja')
            ->assertSeeText('Registrar compra')
            ->assertSeeText('Registrar gasto')
            ->assertSeeText('Nuevo producto')
            ->assertSeeText('Esta semana')
            ->assertDontSeeText('Porcentajes');
    }

    public function test_emprendedor_ve_ganancia_pero_no_caja(): void
    {
        $this->actingAs($this->userWithRole('emprendedor'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Ganaste')
            ->assertDontSeeText('Movimiento de caja')
            ->assertDontSeeText('Registrar gasto');
    }

    public function test_cifras_del_dia_y_ticket_promedio(): void
    {
        $admin = $this->userWithRole('admin');

        foreach ([10000, 30000] as $i => $total) {
            Sale::create([
                'invoice_number'  => 'INV.TEST.00000' . $i,
                'created_by'      => $admin->id,
                'sale_date'       => now(),
                'status'          => SaleStatus::COMPLETED,
                'payment_method'  => PaymentMethod::CASH,
                'source'          => 'pos',
                'subtotal'        => $total,
                'total_discount'  => 0,
                'total'           => $total,
                'cash_received'   => $total,
                'change'          => 0,
                'global_discount' => 0,
                'iva_amount'      => 0,
                'it_amount'       => 0,
                'wants_invoice'   => false,
            ]);
        }

        $component = Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertSet('stats.total_sales', 40000.0)
            ->assertSet('stats.sales_count', 2)
            ->assertSeeText('2 ventas');

        $this->assertSame(20000.0, $component->instance()->averageTicket);
        $this->assertCount(7, $component->get('weekSales'));
    }

    public function test_selector_de_periodo_ignora_valores_invalidos(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))->test(Dashboard::class)
            ->call('setPeriod', 'this_month')
            ->assertSet('dateFilter', 'this_month')
            ->call('setPeriod', 'cualquiera')
            ->assertSet('dateFilter', 'today');
    }

    public function test_stock_bajo_aparece_en_atencion(): void
    {
        Product::factory()->create(['name' => 'Aceite Fino', 'quantity' => 2, 'min_stock' => 10, 'is_active' => true]);

        $this->actingAs($this->userWithRole('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('1 producto por acabarse')
            ->assertSeeText('Aceite Fino')
            ->assertSeeText('Reponer');
    }

    public function test_atajo_nuevo_abre_formulario_de_producto(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('products.index', ['nuevo' => 1]))
            ->assertOk()
            ->assertSee("Livewire.dispatch('create-product')", false);
    }
}
