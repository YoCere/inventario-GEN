<?php

namespace Tests\Feature\Settings;

use App\Shop\Landing\SectionTypes;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Blinda el contrato de escalabilidad: un tipo declarado en el registry DEBE tener
 * su partial de render (tienda) y su partial de formulario (editor). Si alguien
 * agrega un tipo a medias, esto falla acá y no en producción.
 */
class SectionTypesContractTest extends TestCase
{
    public function test_every_type_has_render_and_form_partials(): void
    {
        foreach (SectionTypes::keys() as $type) {
            $this->assertTrue(
                View::exists(SectionTypes::partial($type)),
                "Falta el partial de render del tipo {$type}: " . SectionTypes::partial($type)
            );
            $this->assertTrue(
                View::exists(SectionTypes::form($type)),
                "Falta el partial de formulario del tipo {$type}: " . SectionTypes::form($type)
            );
        }
    }
}
