<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ProductForm;
use App\Models\Category;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Alta de categoría desde el selector del formulario de producto.
 *
 * El caso que motivó esto: la dueña del negocio carga medio producto, descubre
 * que la categoría no existe y hasta ahora tenía que salir del formulario y
 * volver a empezar de cero.
 */
class ProductFormCategoryQuickCreateTest extends TestCase
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

    /**
     * El test que importa: nada de lo que el usuario ya cargó se puede perder al
     * crear la categoría. Si alguien reinicia el formulario, lo vuelve a montar
     * o mueve el alta a otra pantalla, este test se cae.
     */
    public function test_crear_categoria_desde_el_selector_no_pierde_ningun_dato_del_formulario(): void
    {
        $unit = Unit::factory()->create();

        // El alta de producto necesita una ubicación donde asentar el stock inicial.
        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'is_default' => true]);
        Location::create([
            'warehouse_id' => $warehouse->id,
            'name' => 'Estante Principal',
            'is_default' => true,
        ]);

        $componente = Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->set('name', 'Cinto de cuero repujado')
            ->set('sku', 'TAL-CIN-001')
            ->set('sin_code', '87654321')
            ->set('unit_id', $unit->id)
            ->set('purchase_price', 12000)
            ->set('selling_price', 25000)
            ->set('quantity', 7)
            ->set('min_stock', 2)
            ->set('description', 'Cinto hecho a mano, hebilla de bronce.')
            ->set('notes', 'Pedido de la señora Gutiérrez.')
            ->set('is_active', true)
            ->set('is_public', true)
            ->set('featured', true);

        $componente->call('createCategory', 'Cintos');

        $categoria = Category::where('name', 'Cintos')->sole();

        // La categoría nueva quedó seleccionada (id y etiqueta visible).
        $componente
            ->assertSet('category_id', $categoria->id)
            ->assertSet('categoryName', 'Cintos')
            ->assertReturned(['value' => $categoria->id, 'text' => 'Cintos']);

        // Y absolutamente todo lo demás sigue como estaba.
        $componente
            ->assertSet('name', 'Cinto de cuero repujado')
            ->assertSet('sku', 'TAL-CIN-001')
            ->assertSet('sin_code', '87654321')
            ->assertSet('unit_id', $unit->id)
            ->assertSet('purchase_price', 12000)
            ->assertSet('selling_price', 25000)
            ->assertSet('quantity', 7)
            ->assertSet('min_stock', 2)
            ->assertSet('description', 'Cinto hecho a mano, hebilla de bronce.')
            ->assertSet('notes', 'Pedido de la señora Gutiérrez.')
            ->assertSet('is_active', true)
            ->assertSet('is_public', true)
            ->assertSet('featured', true)
            ->assertSet('isEditing', false);

        // Y el producto se guarda con la categoría recién creada, sin volver a
        // tipear nada.
        $componente->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Cinto de cuero repujado',
            'category_id' => $categoria->id,
            'selling_price' => 25000,
        ]);
    }

    public function test_el_nombre_repetido_con_otra_capitalizacion_no_crea_una_segunda_categoria(): void
    {
        $existente = Category::factory()->create(['name' => 'Billeteras', 'slug' => 'billeteras']);

        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', '  BILLETERAS  ')
            ->assertSet('category_id', $existente->id)
            ->assertSet('categoryName', 'Billeteras');

        $this->assertSame(1, Category::count());
    }

    public function test_el_nombre_repetido_con_tildes_no_crea_una_segunda_categoria(): void
    {
        $existente = Category::factory()->create(['name' => 'Correas de Montura', 'slug' => 'correas-de-montura']);

        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', 'correás de montúra')
            ->assertSet('category_id', $existente->id);

        $this->assertSame(1, Category::count());
    }

    /** "Cintos" no debe reutilizar "Cintos de cuero": son categorías distintas. */
    public function test_un_nombre_parecido_pero_distinto_si_crea_categoria_nueva(): void
    {
        Category::factory()->create(['name' => 'Cintos de cuero', 'slug' => 'cintos-de-cuero']);

        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', 'Cintos');

        $this->assertSame(2, Category::count());
        $this->assertDatabaseHas('categories', ['name' => 'Cintos', 'slug' => 'cintos']);
    }

    /** La columna slug es unique: dos nombres distintos pueden chocar en el slug. */
    public function test_el_slug_se_desambigua_cuando_ya_esta_ocupado(): void
    {
        Category::factory()->create(['name' => 'Cintos / Correas', 'slug' => 'cintos-correas']);

        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', 'Cintos Correas');

        $this->assertDatabaseHas('categories', ['name' => 'Cintos Correas', 'slug' => 'cintos-correas-1']);
        $this->assertSame(2, Category::count());
    }

    public function test_sin_el_permiso_de_categorias_el_alta_devuelve_403(): void
    {
        $rol = Role::create(['name' => 'bodeguero', 'guard_name' => 'web']);
        $rol->givePermissionTo(['dashboard.view', 'products.view', 'products.manage']);

        $bodeguero = User::factory()->create(['email_verified_at' => now()]);
        $bodeguero->assignRole('bodeguero');

        Livewire::actingAs($bodeguero)
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', 'Cintos')
            ->assertForbidden();

        $this->assertSame(0, Category::count());
    }

    public function test_sin_el_permiso_de_categorias_el_selector_no_ofrece_crear(): void
    {
        $rol = Role::create(['name' => 'bodeguero', 'guard_name' => 'web']);
        $rol->givePermissionTo(['dashboard.view', 'products.view', 'products.manage']);

        $bodeguero = User::factory()->create(['email_verified_at' => now()]);
        $bodeguero->assignRole('bodeguero');

        Livewire::actingAs($bodeguero)
            ->test(ProductForm::class)
            ->assertDontSee("call('createCategory'", false)
            ->assertDontSee('Escribí el nombre y creala desde acá');

        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->assertSee("call('createCategory'", false);
    }

    public function test_un_nombre_de_una_sola_letra_no_crea_categoria(): void
    {
        Livewire::actingAs($this->userWithRole('emprendedor'))
            ->test(ProductForm::class)
            ->call('create')
            ->call('createCategory', 'C')
            ->assertReturned(null)
            ->assertSet('category_id', null);

        $this->assertSame(0, Category::count());
    }
}
