<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Terminada una venta, la siguiente arranca en cero.
 *
 * El caso real: se cobran 350 Bs, se cierra la venta, y la venta siguiente
 * aparece con 350 Bs ya puestos en el campo de efectivo. El vendedor ve un
 * monto pagado que el sistema por dentro no tiene (ahí el valor es 0), así que
 * al cobrar le sale "pago insuficiente" sin que nada en pantalla lo explique.
 *
 * La pantalla de venta es Alpine dentro del Blade, así que esto se verifica
 * sobre el código que se manda al navegador. No reemplaza probar la pantalla a
 * mano — lo que hace es cazar la regresión: si alguien vuelve a resetear solo
 * el número y se olvida del texto visible, este test se cae.
 */
class PosResetTest extends TestCase
{
    use RefreshDatabase;

    private function pantallaDeVenta(): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('emprendedor');

        $respuesta = $this->actingAs($user)->get(route('sales.create'));
        $respuesta->assertOk();

        return $respuesta->getContent();
    }

    public function test_al_terminar_una_venta_se_limpia_el_monto_que_se_ve_en_pantalla(): void
    {
        $pantalla = $this->pantallaDeVenta();

        // El texto del campo de efectivo y el número que usa el sistema son dos
        // cosas distintas: resetear una sola deja la pantalla mintiendo.
        $this->assertMatchesRegularExpression(
            '/resetForm\(\)\s*\{.{0,900}?cashInputDraft\s*=\s*\'\'/s',
            $pantalla,
            'resetForm() tiene que limpiar también el texto visible del campo de efectivo.'
        );
    }

    public function test_al_terminar_una_venta_se_vacia_el_carrito_y_el_descuento(): void
    {
        $pantalla = $this->pantallaDeVenta();

        foreach (['this.cart = []', 'cash_received: 0', 'this.globalDiscount = 0'] as $debeEstar) {
            $this->assertStringContainsString(
                $debeEstar,
                $pantalla,
                "La venta siguiente tiene que arrancar limpia: falta «{$debeEstar}»."
            );
        }
    }
}
