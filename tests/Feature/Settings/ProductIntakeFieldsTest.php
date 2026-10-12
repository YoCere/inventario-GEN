<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Settings\ProductIntakeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La fuente única de qué datos se piden al dar de alta un producto.
 *
 * Lo que protege este test es la regla que pidió la dueña: poder elegir qué le
 * pregunta el bot, pero sin poder romper el producto apagando lo esencial.
 */
class ProductIntakeFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_los_datos_esenciales_se_piden_siempre(): void
    {
        foreach (ProductIntakeFields::OBLIGATORIOS as $campo) {
            $this->assertTrue(ProductIntakeFields::pide($campo));
        }
    }

    /**
     * La defensa de verdad: deshabilitar la casilla en pantalla no alcanza,
     * porque eso se saltea desde el navegador. Aunque el valor llegue apagado a
     * la base, el dato se sigue pidiendo.
     */
    public function test_apagar_un_obligatorio_a_mano_en_la_base_no_tiene_efecto(): void
    {
        foreach (ProductIntakeFields::OBLIGATORIOS as $campo) {
            Setting::set(ProductIntakeFields::clave($campo), '0');

            $this->assertTrue(
                ProductIntakeFields::pide($campo),
                "El campo «{$campo}» es imprescindible: no se puede apagar ni escribiendo en la base."
            );
        }
    }

    public function test_por_defecto_se_pregunta_todo(): void
    {
        $this->assertSame(array_keys(ProductIntakeFields::catalogo()), ProductIntakeFields::activos());
    }

    public function test_apagar_un_dato_opcional_lo_saca_de_la_lista(): void
    {
        Setting::set(ProductIntakeFields::clave('stock_minimo'), '0');

        $this->assertFalse(ProductIntakeFields::pide('stock_minimo'));
        $this->assertNotContains('stock_minimo', ProductIntakeFields::activos());
        // Los demás no se tocan.
        $this->assertContains('categoria', ProductIntakeFields::activos());
    }

    public function test_lo_que_no_se_pregunta_tiene_un_valor_definido_y_cambiable(): void
    {
        $this->assertSame('1', ProductIntakeFields::porDefecto('stock_minimo'));

        Setting::set('intake_default_stock_minimo', '5');

        $this->assertSame('5', ProductIntakeFields::porDefecto('stock_minimo'));
    }

    public function test_los_obligatorios_no_aparecen_entre_los_configurables(): void
    {
        foreach (ProductIntakeFields::OBLIGATORIOS as $campo) {
            $this->assertNotContains($campo, ProductIntakeFields::configurables());
        }

        $this->assertContains('unidad', ProductIntakeFields::configurables());
        $this->assertContains('stock_minimo', ProductIntakeFields::configurables());
    }

    public function test_un_campo_inventado_no_se_pide_ni_rompe(): void
    {
        $this->assertFalse(ProductIntakeFields::pide('color_favorito'));
        $this->assertNull(ProductIntakeFields::porDefecto('color_favorito'));
    }
}
