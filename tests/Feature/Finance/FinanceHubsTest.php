<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceHubsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_accounting_hub_with_its_cards(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('finance.hub.accounting'))
            ->assertOk()
            ->assertSee('Contabilidad')
            ->assertSee('Plan de cuentas')
            ->assertSee('Libro diario')
            ->assertSee('Asiento de apertura')
            ->assertSee('Período contable')
            ->assertSee('Hoja teórica')
            ->assertSee('Estados financieros')
            ->assertSee('Balance de Sumas y Saldos')
            ->assertSee('Kardex valorizado');
    }

    public function test_admin_sees_modules_hub_with_its_cards(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('finance.hub.modules'))
            ->assertOk()
            ->assertSee('Módulos')
            ->assertSee('Activos Fijos')
            ->assertSee('Categorías de activo')
            ->assertSee('Préstamos')
            ->assertSee('Presupuestos')
            ->assertSee('Producción')
            ->assertSee('Recetas (BOM)')
            ->assertSee('Planilla');
    }

    public function test_admin_sees_treasury_hub_with_its_cards(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('finance.hub.treasury'))
            ->assertOk()
            ->assertSee('Tesorería')
            ->assertSee('Transacciones')
            ->assertSee('Categorías');
    }

    public function test_staff_is_forbidden_from_all_finance_hubs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('finance.hub.accounting'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.hub.modules'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.hub.treasury'))->assertForbidden();
    }

    public function test_kardex_only_permission_grants_access_to_accounting_hub(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('products.kardex');

        $this->actingAs($user)->get(route('finance.hub.accounting'))
            ->assertOk()
            ->assertSee('Kardex valorizado')
            ->assertDontSee('Plan de cuentas');
    }
}
