<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La pantalla de cobro, pensada para cobrar con una mano y el cliente esperando.
 *
 * Los cuatro reclamos que la motivaron: incómoda en el celular, cuesta cobrar
 * rápido en efectivo, demasiados campos juntos, y no se entiende qué falta para
 * cerrar. Los cuatro salían de lo mismo: siete bloques compitiendo por la
 * pantalla, y lo más usado mezclado con lo que casi nunca se toca.
 *
 * La pantalla es Alpine dentro del Blade, así que esto se verifica sobre el
 * código que se manda al navegador. **No reemplaza probarla en un celular de
 * verdad** — lo que hace es impedir que estas decisiones se deshagan sin que
 * nadie se entere.
 */
class PosCobroTest extends TestCase
{
    use RefreshDatabase;

    private function pantalla(): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        $respuesta = $this->actingAs($user)->get(route('sales.create'));
        $respuesta->assertOk();

        return $respuesta->getContent();
    }

    /** Lo que antes era un botón gris y mudo. */
    public function test_cuando_el_efectivo_no_alcanza_el_boton_dice_cuanto_falta(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString("'Faltan ' + formatCurrency(faltante)", $pantalla);
        $this->assertStringContainsString('get faltante()', $pantalla);
    }

    /** Para un total de 350, ofrecer "50" no sirve de nada. */
    public function test_los_montos_rapidos_se_calculan_desde_el_total(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString('get montosSugeridos()', $pantalla);
        $this->assertStringContainsString('x-for="monto in montosSugeridos"', $pantalla);

        // La lista fija vieja no puede volver.
        $this->assertStringNotContainsString('x-for="amount in [50, 100, 200]"', $pantalla);

        // Los totales viajan en CENTAVOS: un total de Bs 50,50 es 5050. Calcular
        // los billetes como si fueran bolivianos ofrecía "Bs 51" para ese total,
        // un botón que no sirve para nada. Se descubrió abriendo la pantalla, no
        // leyendo el código.
        $this->assertStringContainsString('const BS = 100;', $pantalla);
    }

    public function test_cobrar_el_monto_justo_es_un_solo_toque(): void
    {
        $this->assertStringContainsString(
            'payment.cash_received = total; cashInputDraft = formatNumber(total)',
            $this->pantalla(),
            'Tiene que haber un botón que deje el monto exacto de una.'
        );
    }

    public function test_lo_que_casi_nunca_se_usa_arranca_plegado(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString('masOpciones: false', $pantalla);
        $this->assertStringContainsString('x-show="masOpciones"', $pantalla);
    }

    /**
     * Plegar no puede ser esconder: si hay un descuento, un cliente o un pedido
     * reservado, la persona tiene que verlo antes de cobrar.
     */
    public function test_si_hay_algo_cargado_en_lo_plegado_se_avisa(): void
    {
        $this->assertStringContainsString(
            'x-show="selectedCustomer || globalDiscount > 0 || payment.notes || saleStatus !== \'completed\' || wantsInvoice"',
            $this->pantalla()
        );
    }

    /**
     * El selector de cliente se inicializa una sola vez al cargar la pantalla.
     * Si alguien cambia este x-show por un x-if, el campo desaparece del DOM y
     * deja de funcionar sin dar ningún error visible.
     */
    public function test_el_selector_de_cliente_sigue_existiendo_en_la_pagina(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString('x-ref="customerSelect"', $pantalla);
        $this->assertMatchesRegularExpression(
            '/x-show="masOpciones"/',
            $pantalla,
            'El bloque plegado tiene que usar x-show, no x-if: x-if lo sacaría del DOM.'
        );
    }

    public function test_el_vuelto_se_muestra_cuando_corresponde(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString('x-show="change > 0"', $pantalla);
        $this->assertStringContainsString('Vuelto', $pantalla);
    }

    /** Todo lo que se plegó tiene que seguir estando. */
    public function test_no_se_perdio_ninguna_funcion_al_simplificar(): void
    {
        $pantalla = $this->pantalla();

        foreach ([
            'globalDiscount = unformatNumber' => 'el descuento global',
            'x-model="payment.notes"' => 'las notas',
            "saleStatus = 'pending'" => 'reservar un pedido',
            'openCustomerModal()' => 'crear un cliente',
        ] as $marca => $queEs) {
            $this->assertStringContainsString($marca, $pantalla, "Se perdió {$queEs} al simplificar la pantalla.");
        }
    }

    /**
     * El tiempo de la venta se cuenta desde que entra el primer producto al
     * carrito. Sin esto el servidor mide desde que la pantalla quedó libre, y
     * un mostrador vacío de veinte minutos se anota como una venta lenta de
     * veinte minutos — una métrica que miente.
     */
    public function test_la_venta_se_mide_desde_que_entro_el_primer_producto(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString(
            'if (this.cart.length === 0) this.atendidoDesde = new Date().toISOString();',
            $pantalla
        );
        $this->assertStringContainsString('atendido_desde: this.atendidoDesde,', $pantalla);
        $this->assertStringContainsString('this.atendidoDesde = null;', $pantalla);
    }

    /**
     * La casilla de factura solo existe en los negocios que facturan, así que
     * hay que encender esa opción para verla. Por eso va en su propio test: con
     * la facturación apagada, el bloque no se renderiza y el anterior pasaría
     * sin comprobar nada.
     */
    public function test_la_casilla_de_factura_sigue_estando_donde_se_factura(): void
    {
        \App\Models\Setting::set('facturacion_activada', '1');

        $this->assertStringContainsString('x-model="wantsInvoice"', $this->pantalla());
    }
}
