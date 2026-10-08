<?php

namespace Tests\Feature\Install;

use App\Models\Category;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\FinanceCategory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El alta de un cliente nuevo tiene que dejar la instancia usable y SIN rastros
 * del negocio de otro: ni productos de ejemplo, ni "Importadora El Cóndor", ni el
 * admin/password de desarrollo.
 */
class InstallClientCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = [
        '--negocio' => 'Talabartería Rosita',
        '--duenio' => 'Rosa Mamani',
        '--email' => 'rosa@talabarteriarosita.bo',
        '--password' => 'cuero-y-cintos-2026',
        '--telefono' => '70011223',
        '--direccion' => 'Calle Comercio 123, Sucre',
    ];

    public function test_deja_la_instancia_lista_con_los_datos_del_negocio(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $this->assertSame('Talabartería Rosita', Setting::get('store_name'));
        $this->assertSame('70011223', Setting::get('store_phone'));
        $this->assertSame('Calle Comercio 123, Sucre', Setting::get('store_address'));
        $this->assertSame('America/La_Paz', Setting::get('business_timezone'));
        $this->assertSame('Bs', Setting::get('currency_symbol'));

        // Estructura mínima para operar.
        $this->assertGreaterThan(0, ChartOfAccount::count(), 'Falta el plan de cuentas.');
        $this->assertDatabaseHas('accounting_periods', ['status' => 'open']);
        $this->assertGreaterThan(0, FinanceCategory::count());
    }

    public function test_crea_la_duenia_con_su_clave_y_puede_entrar(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $user = User::where('email', 'rosa@talabarteriarosita.bo')->firstOrFail();

        $this->assertSame('Rosa Mamani', $user->name);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue(Hash::check('cuero-y-cintos-2026', $user->password));
        $this->assertNotNull($user->email_verified_at, 'Sin verificar, no podría entrar.');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_no_deja_datos_de_ejemplo_ni_el_negocio_de_otro(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $this->assertSame(0, Product::count(), 'La instancia nueva no debe traer productos.');
        $this->assertSame(0, Customer::count(), 'La instancia nueva no debe traer clientes.');
        $this->assertSame(1, User::count(), 'Solo debe existir la usuaria dueña.');

        $this->assertStringNotContainsString('Cóndor', (string) Setting::get('store_name'));
        $this->assertNull(User::where('email', 'admin@admin.com')->first(), 'Quedó el admin de desarrollo.');
    }

    public function test_las_categorias_de_caja_estan_en_espaniol(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $nombres = FinanceCategory::pluck('name')->all();

        $this->assertContains('Venta de productos', $nombres);
        $this->assertContains('Compra de mercadería', $nombres);
        // Restos de la plantilla original del sistema.
        $this->assertNotContains('Penjualan Produk', $nombres);
        $this->assertNotContains('Gaji Karyawan', $nombres);
    }

    public function test_el_rubro_talabarteria_sugiere_categorias_sin_cargar_productos(): void
    {
        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--rubro' => 'talabarteria']))->assertSuccessful();

        $categorias = Category::pluck('name')->all();

        $this->assertContains('Cintos', $categorias);
        $this->assertContains('Billeteras', $categorias);
        $this->assertContains('Instrumentos', $categorias);
        $this->assertSame(0, Product::count());
        $this->assertDatabaseHas('units', ['name' => 'Par']);
    }

    public function test_sin_rubro_no_crea_categorias(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $this->assertSame(0, Category::count());
    }

    public function test_la_facturacion_arranca_apagada_salvo_que_se_pida(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();
        $this->assertSame('0', Setting::get('facturacion_activada'));

        User::query()->delete();
        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--facturacion' => true, '--force' => true]))->assertSuccessful();
        $this->assertSame('1', Setting::get('facturacion_activada'));
    }

    public function test_se_niega_si_la_base_ya_tiene_datos(): void
    {
        User::factory()->create();

        $this->artisan('instalar:cliente', self::BASE)
            ->expectsOutputToContain('ya tiene usuarios o productos')
            ->assertFailed();

        $this->assertNull(Setting::get('store_name'));
    }

    public function test_rechaza_correo_invalido_rol_inexistente_y_zona_horaria_falsa(): void
    {
        // array_merge y no "+": con la unión de arreglos PHP conserva el valor de la
        // izquierda y la opción bajo prueba nunca se aplicaría.
        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--email' => 'no-es-correo']))->assertFailed();
        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--rol' => 'jefe']))->assertFailed();
        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--zona' => 'Marte/Olympus']))->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_no_pisa_un_correo_ya_usado(): void
    {
        $this->artisan('instalar:cliente', self::BASE)->assertSuccessful();

        $this->artisan('instalar:cliente', array_merge(self::BASE, ['--force' => true]))
            ->expectsOutputToContain('Ya existe un usuario')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_genera_contrasenia_segura_si_no_se_indica(): void
    {
        $opciones = self::BASE;
        unset($opciones['--password']);

        $this->artisan('instalar:cliente', $opciones)->assertSuccessful();

        $user = User::where('email', 'rosa@talabarteriarosita.bo')->firstOrFail();

        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('', $user->password));
    }
}
