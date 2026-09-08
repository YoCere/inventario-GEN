<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use App\Services\Agent\ToolRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolRegistryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ToolRegistry
    {
        return app(ToolRegistry::class);
    }

    private function userWith(array $permissions): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    public function test_write_tools_gated_by_permission(): void
    {
        $conPermiso = $this->userWith(['sales.create', 'sales.cancel', 'products.manage']);
        $tools = $this->registry()->forUser($conPermiso)->all();
        $this->assertArrayHasKey('start_sale', $tools);
        $this->assertArrayHasKey('sell_product', $tools);
        $this->assertArrayHasKey('cancel_last_sale', $tools);
        $this->assertArrayHasKey('start_product_creation', $tools);
    }

    public function test_write_tools_excluded_without_permission(): void
    {
        $sinPermiso = $this->userWith([]);
        $tools = $this->registry()->forUser($sinPermiso)->all();
        $this->assertArrayNotHasKey('start_sale', $tools);
        $this->assertArrayNotHasKey('sell_product', $tools);
        $this->assertArrayNotHasKey('cancel_last_sale', $tools);
        $this->assertArrayNotHasKey('start_product_creation', $tools);
    }

    public function test_finance_tools_gated(): void
    {
        $conFinanzas = $this->userWith(['finance.view', 'finance.accounting']);
        $sinFinanzas = $this->userWith([]);

        $conKeys = $this->registry()->forUser($conFinanzas)->all();
        $sinKeys = $this->registry()->forUser($sinFinanzas)->all();

        $this->assertArrayHasKey('get_balance_sheet', $conKeys);
        $this->assertArrayNotHasKey('get_balance_sheet', $sinKeys);
    }

    public function test_developer_gets_all_via_gate_before(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $dev = User::factory()->create();
        $dev->assignRole('developer');

        $tools = $this->registry()->forUser($dev)->all();
        $this->assertArrayHasKey('get_balance_sheet', $tools);
        $this->assertArrayHasKey('start_sale', $tools);
        $this->assertArrayHasKey('cancel_last_sale', $tools);
    }
}
