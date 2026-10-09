<?php

namespace Tests\Feature\Ui;

use App\Livewire\Products\ProductForm;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Los selectores con búsqueda (categoría, unidad, cliente…) no pueden llevar
 * comentarios HTML adentro de su código JavaScript.
 *
 * El caso real, visto en producción: el selector de categoría aparecía como una
 * lista desplegable pelada, sin búsqueda y sin ninguna opción, así que no se
 * podía crear un producto desde la web. En la consola:
 *
 *     Alpine Expression Error: Unexpected identifier 'que'
 *
 * La causa: Livewire inserta marcadores `<!--[if BLOCK]><![endif]-->` donde hay
 * un `@if`, y en JavaScript `<!--` comenta hasta el final de la línea. Eso se
 * tragaba la apertura de un comentario `/* ... *\/` y el texto en español que
 * seguía se ejecutaba como código.
 *
 * Por eso lo condicional se arma en PHP y se imprime de una. Si alguien vuelve
 * a meter un `@if` adentro del `x-data`, este test lo caza.
 */
class TomSelectExpressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ningun_selector_lleva_comentarios_html_en_su_javascript(): void
    {
        $html = $this->formularioDeProducto();

        foreach ($this->expresionesXData($html) as $expresion) {
            $this->assertStringNotContainsString(
                '<!--',
                $expresion,
                'Hay un comentario HTML dentro de un x-data: en JavaScript eso comenta ' .
                'el resto de la línea y rompe el componente entero.'
            );
        }
    }

    public function test_el_selector_de_categoria_queda_enlazado_y_puede_crear(): void
    {
        $html = $this->formularioDeProducto();
        $expresiones = $this->expresionesXData($html);

        $deCategoria = collect($expresiones)
            ->first(fn (string $exp) => str_contains($exp, "entangle('category_id')"));

        $this->assertNotNull(
            $deCategoria,
            'El selector de categoría tiene que quedar enlazado a la propiedad del formulario.'
        );

        // El alta rápida de categoría (T1) viaja en la misma expresión.
        $this->assertStringContainsString("call('createCategory'", $deCategoria);
    }

    public function test_hay_al_menos_dos_selectores_en_el_formulario(): void
    {
        // Red de seguridad: si el formulario deja de renderizar los selectores,
        // los dos tests de arriba pasarían sin revisar nada.
        $expresiones = collect($this->expresionesXData($this->formularioDeProducto()))
            ->filter(fn (string $exp) => str_contains($exp, 'new TomSelect'));

        $this->assertGreaterThanOrEqual(2, $expresiones->count(), 'Faltan los selectores de categoría y unidad.');
    }

    private function formularioDeProducto(): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        return Livewire::actingAs($user)
            ->test(ProductForm::class)
            ->call('create')
            ->html();
    }

    /** @return array<int, string> */
    private function expresionesXData(string $html): array
    {
        preg_match_all('/x-data="([^"]*)"/s', $html, $coincidencias);

        $expresiones = $coincidencias[1] ?? [];

        $this->assertNotEmpty($expresiones, 'No se encontró ningún x-data en el formulario.');

        return $expresiones;
    }
}
