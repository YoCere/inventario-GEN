<?php

namespace Tests\Feature\Authorization;

use App\Livewire\Products\ProductForm;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductManagePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    public function test_emprendedor_puede_abrir_alta_de_producto(): void
    {
        $emprendedor = $this->userWithRole('emprendedor');

        Livewire::actingAs($emprendedor)->test(ProductForm::class)
            ->call('create')
            ->assertOk();

        $this->actingAs($emprendedor)->get(route('products.index'))
            ->assertOk()
            ->assertSee("\$dispatch('create-product')", false);
    }

    public function test_staff_no_puede_dar_de_alta_productos(): void
    {
        $staff = $this->userWithRole('staff');

        Livewire::actingAs($staff)->test(ProductForm::class)
            ->call('create')
            ->assertForbidden();

        $this->actingAs($staff)->get(route('products.index', ['nuevo' => 1]))
            ->assertOk()
            ->assertDontSee("\$dispatch('create-product')", false)
            ->assertDontSee("Livewire.dispatch('create-product')", false);
    }
}
