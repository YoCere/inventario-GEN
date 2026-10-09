<?php

namespace Tests\Feature\Ui;

use App\Livewire\Products\ProductForm;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Los errores de los formularios tienen que estar en español.
 *
 * El caso real: al crear un producto sin categoría, la pantalla mostraba
 * "validation.required" — la clave interna, no un mensaje. Pasaba porque el
 * proyecto no tenía `lang/es/validation.php` y tanto `locale` como
 * `fallback_locale` son `es`, así que no había de dónde sacar el texto.
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_reglas_de_validacion_tienen_texto_en_espaniol(): void
    {
        // Las más usadas en los formularios del sistema.
        foreach (['required', 'integer', 'email', 'unique', 'exists', 'image', 'uploaded'] as $regla) {
            $mensaje = trans("validation.{$regla}");

            $this->assertNotSame(
                "validation.{$regla}",
                $mensaje,
                "La regla '{$regla}' no tiene traducción: la pantalla mostraría la clave cruda."
            );
        }
    }

    public function test_el_formulario_de_producto_muestra_el_error_con_el_nombre_del_campo(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        $componente = Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->call('create')
            ->call('save');

        $errores = $componente->errors();

        $this->assertTrue($errores->has('category_id'));

        $mensaje = $errores->first('category_id');

        $this->assertStringNotContainsString('validation.', $mensaje);
        // "la categoría", no "category_id": el mensaje se lee como lo diría una persona.
        $this->assertStringContainsString('categoría', $mensaje);
        $this->assertStringNotContainsString('category_id', $mensaje);
    }
}
