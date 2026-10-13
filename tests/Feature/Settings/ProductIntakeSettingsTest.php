<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use App\Settings\ProductIntakeFields;
use App\Settings\SettingsCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La pantalla "Alta de productos" de Ajustes.
 *
 * Lo que pidió la dueña: elegir tiqueando qué le pregunta el sistema al cargar
 * un producto, sin poder destiquear lo esencial. Acá se protegen las dos mitades
 * de eso: que apagar un dato funcione de verdad, y que apagar un dato
 * imprescindible no funcione NI forzando el pedido desde el navegador.
 */
class ProductIntakeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function dueña(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        return $user;
    }

    private function pantalla(User $user)
    {
        return Livewire::actingAs($user)->test(SettingsPage::class)
            ->call('goTo', 'productos')
            ->assertSet('section', 'productos');
    }

    public function test_la_seccion_se_ve_con_cada_dato_y_su_valor_de_reemplazo(): void
    {
        $this->pantalla($this->dueña())
            ->assertSeeText('Alta de productos')
            ->assertSeeText('Nombre del producto')
            ->assertSeeText('Precio de compra')
            ->assertSeeText('Precio de venta')
            ->assertSeeText('Avisar cuando queden pocos')
            ->assertSeeText('Con cuántas unidades avisar si no se pregunta')
            ->assertSeeText('Categoría que se usa si no se pregunta')
            // Nada de nombres técnicos a la vista de la dueña.
            ->assertDontSeeText('intake_')
            ->assertDontSeeText('stock_minimo')
            // Los imprescindibles salen marcados y sin poder tocarse.
            ->assertSee('aria-label="Nombre del producto: siempre se pregunta"', false)
            ->assertSee('aria-disabled="true"', false)
            // Regresión: un componente Blade mal compilado sale como etiqueta cruda.
            ->assertDontSee('<x-', false);
    }

    /** Métrica 3: quien no toca nada sigue teniendo todo igual que antes. */
    public function test_de_fabrica_se_pregunta_todo(): void
    {
        $pantalla = $this->pantalla($this->dueña());

        foreach (array_keys(ProductIntakeFields::catalogo()) as $campo) {
            $pantalla->assertSet('values.' . ProductIntakeFields::clave($campo), '1');
        }

        $pantalla->call('save')->assertHasNoErrors();

        $this->assertSame(array_keys(ProductIntakeFields::catalogo()), ProductIntakeFields::activos());
    }

    /** Métrica 1: apagar un dato desde la pantalla deja de preguntarlo. */
    public function test_apagar_un_dato_desde_la_pantalla_lo_deja_de_pedir(): void
    {
        $this->pantalla($this->dueña())
            ->set('values.' . ProductIntakeFields::clave('stock_minimo'), '0')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('settings-saved');

        $this->assertFalse(ProductIntakeFields::pide('stock_minimo'));
        $this->assertNotContains('stock_minimo', ProductIntakeFields::activos());
        // Apagar uno no apaga a los demás.
        $this->assertTrue(ProductIntakeFields::pide('categoria'));
    }

    /**
     * Métrica 2: la de verdad. Una casilla deshabilitada en pantalla se saltea
     * desde el navegador en diez segundos, así que el pedido llega con el
     * obligatorio apagado. Tiene que rebotar del lado del servidor y no dejar
     * basura guardada.
     */
    public function test_los_imprescindibles_no_se_apagan_ni_forzando_el_pedido(): void
    {
        $pantalla = $this->pantalla($this->dueña());

        foreach (ProductIntakeFields::OBLIGATORIOS as $campo) {
            $pantalla->set('values.' . ProductIntakeFields::clave($campo), '0');
        }

        $pantalla->call('save')->assertHasNoErrors();

        foreach (ProductIntakeFields::OBLIGATORIOS as $campo) {
            $clave = ProductIntakeFields::clave($campo);

            $this->assertTrue(
                ProductIntakeFields::pide($campo),
                "«{$campo}» es imprescindible: no se puede apagar ni forzando el pedido."
            );

            // Y tampoco quedó guardado un '0' que mañana confunda a cualquiera
            // que lea la tabla de ajustes.
            $this->assertDatabaseMissing('settings', ['key' => $clave]);
        }

        // El forcejeo no arrastra a los demás datos.
        $this->assertSame(array_keys(ProductIntakeFields::catalogo()), ProductIntakeFields::activos());
    }

    /** Métrica 4: el valor de reemplazo se guarda y es el que se usa. */
    public function test_cambiar_el_valor_por_defecto_de_un_dato_apagado_se_guarda(): void
    {
        $this->pantalla($this->dueña())
            ->set('values.' . ProductIntakeFields::clave('stock_minimo'), '0')
            ->set('values.intake_default_stock_minimo', '5')
            ->set('values.intake_default_categoria', 'Abarrotes')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('5', ProductIntakeFields::porDefecto('stock_minimo'));
        $this->assertSame('Abarrotes', ProductIntakeFields::porDefecto('categoria'));
    }

    /** Dejarlo vacío no es un error: vuelve al valor de fábrica, que está escrito en pantalla. */
    public function test_borrar_el_valor_por_defecto_vuelve_al_de_fabrica(): void
    {
        Setting::set('intake_default_stock_minimo', '9');

        $this->pantalla($this->dueña())
            ->set('values.intake_default_stock_minimo', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', ProductIntakeFields::porDefecto('stock_minimo'));
    }

    /** Métrica 5: sin el permiso de la sección, no se ve ni se puede guardar. */
    public function test_sin_el_permiso_de_negocio_la_seccion_no_se_ve_ni_se_guarda(): void
    {
        // Rol que entra a Ajustes por contabilidad pero no toca ajustes de negocio.
        $rol = Role::create(['name' => 'contador', 'guard_name' => 'web']);
        $rol->syncPermissions(['settings.view', 'finance.accounting']);

        $contador = User::factory()->create(['email_verified_at' => now()]);
        $contador->assignRole($rol);

        $this->actingAs($contador)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertDontSeeText('Alta de productos');

        Livewire::actingAs($contador)->test(SettingsPage::class)
            ->call('goTo', 'productos')
            // Se queda donde estaba: la sección no existe para él.
            ->assertSet('section', 'contabilidad')
            ->set('values.' . ProductIntakeFields::clave('stock_minimo'), '0')
            ->set('values.intake_default_stock_minimo', '77')
            ->call('save');

        $this->assertDatabaseMissing('settings', ['key' => ProductIntakeFields::clave('stock_minimo')]);
        $this->assertDatabaseMissing('settings', ['key' => 'intake_default_stock_minimo']);
        $this->assertTrue(ProductIntakeFields::pide('stock_minimo'));
    }

    /**
     * Contrato con la fuente única: si mañana alguien agrega un dato al catálogo
     * de ProductIntakeFields, tiene que aparecer en la pantalla (y su valor de
     * reemplazo también). Si no, se apaga un dato y el sistema vuelve a adivinar
     * en silencio, que es justo lo que no se quiere.
     */
    public function test_la_pantalla_cubre_todo_el_catalogo(): void
    {
        $claves = array_column(SettingsCatalog::all()['productos']['fields'], 'key');
        $campos = collect(SettingsCatalog::all()['productos']['fields'])->keyBy('key');

        foreach (ProductIntakeFields::catalogo() as $campo => $dato) {
            $clave = ProductIntakeFields::clave($campo);
            $this->assertContains($clave, $claves, "Falta la casilla de «{$campo}» en Ajustes.");

            $this->assertSame(
                ProductIntakeFields::esObligatorio($campo),
                ! empty($campos[$clave]['locked']),
                "La casilla de «{$campo}» tiene mal si se puede apagar o no."
            );

            if ($dato['defecto_key'] !== null) {
                $this->assertContains(
                    $dato['defecto_key'],
                    $claves,
                    "Falta el valor de reemplazo de «{$campo}» en Ajustes."
                );
            }
        }
    }
}
