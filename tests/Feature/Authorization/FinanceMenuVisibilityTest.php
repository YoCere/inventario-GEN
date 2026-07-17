<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceMenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_does_not_see_finance_menu(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();

        // NOTE: 'Resumen financiero' also appears as a subtitle on an unrelated
        // dashboard widget ("Ingresos vs Gastos" chart), so it is not unique to
        // the Finanzas nav menu and can't be used to assert menu visibility.
        // 'Finanzas' only appears as the nav dropdown/accordion trigger label
        // (desktop + mobile) on this page, so it uniquely identifies the menu.
        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Finanzas');
    }

    public function test_admin_sees_finance_menu(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finanzas');
    }
}
