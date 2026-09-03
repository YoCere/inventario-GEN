<?php

namespace Tests\Feature\Onboarding;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmprendedorChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function emprendedor(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('emprendedor');
        return $user;
    }

    public function test_checklist_visible_sin_productos(): void
    {
        $this->actingAs($this->emprendedor());
        $this->get(route('dashboard'))->assertOk()->assertSee('Cargá tus productos');
    }

    public function test_checklist_oculto_con_productos_y_ventas(): void
    {
        $user = $this->emprendedor();

        Product::factory()->create();

        Sale::create([
            'invoice_number'  => 'INV.260903.000001',
            'created_by'      => $user->id,
            'sale_date'       => now(),
            'status'          => SaleStatus::COMPLETED,
            'payment_method'  => PaymentMethod::CASH,
            'source'          => 'pos',
            'subtotal'        => 10000,
            'total_discount'  => 0,
            'total'           => 10000,
            'cash_received'   => 10000,
            'change'          => 0,
            'global_discount' => 0,
            'iva_amount'      => 0,
            'it_amount'       => 0,
            'wants_invoice'   => false,
        ]);

        $this->actingAs($user);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Cargá tus productos');
    }
}
