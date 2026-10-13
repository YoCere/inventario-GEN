<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ProductForm;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El formulario web y el bot tienen que arrancar con el mismo criterio.
 *
 * El aviso de stock mínimo se configura en Ajustes → Alta de productos, y el bot
 * ya lo respeta. El formulario web arrancaba en 0 y no leía ese ajuste: el mismo
 * negocio terminaba con avisos distintos según por dónde se hubiera cargado el
 * producto, sin que nadie lo hubiera decidido.
 */
class ProductFormIntakeDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_el_aviso_de_stock_arranca_con_lo_configurado_en_ajustes(): void
    {
        Setting::set('intake_default_stock_minimo', '7');

        Livewire::actingAs($this->duenia())
            ->test(ProductForm::class)
            ->call('create')
            ->assertSet('min_stock', 7);
    }

    public function test_sin_configurar_nada_usa_el_valor_de_fabrica(): void
    {
        Livewire::actingAs($this->duenia())
            ->test(ProductForm::class)
            ->call('create')
            ->assertSet('min_stock', 1);
    }

    public function test_al_editar_manda_lo_que_tiene_el_producto_no_el_valor_por_defecto(): void
    {
        Setting::set('intake_default_stock_minimo', '7');

        $producto = \App\Models\Product::factory()->create([
            'min_stock' => 3,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->duenia())
            ->test(ProductForm::class)
            ->call('edit', $producto)
            ->assertSet('min_stock', 3);
    }

    private function duenia(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        return $user;
    }
}
