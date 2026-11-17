<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lo que nunca más puede volver al repositorio.
 *
 * El repositorio es público. Dos cosas que había y que, por ser públicas,
 * dejaban de ser descuidos y pasaban a ser agujeros:
 *
 *   1. Doce volcados de datos en `storage/backups/`, con 41 productos y sus
 *      precios de compra y de venta. O sea, la estructura de costos y márgenes
 *      de un cliente, legible por cualquiera.
 *   2. Un seeder que creaba `admin@admin.com` con la contraseña `password` y
 *      rol developer. Con el código a la vista, esa credencial está publicada.
 *
 * Estas pruebas no arreglan nada: impiden que vuelva sin que nadie se entere.
 */
class RepositorioLimpioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mide la métrica M11 del plan de endurecimiento: cero archivos con datos
     * de negocio versionados.
     */
    public function test_no_hay_volcados_de_datos_versionados(): void
    {
        $salida = [];
        $codigo = 0;
        exec('git ls-files storage/backups/ 2>&1', $salida, $codigo);

        if ($codigo !== 0) {
            $this->markTestSkipped('git no está disponible en este entorno.');
        }

        // El .gitignore de la carpeta sí puede estar versionado: sirve para que
        // la carpeta exista en un clon nuevo.
        $datos = array_filter(
            $salida,
            fn (string $ruta) => ! str_ends_with(trim($ruta), '.gitignore') && trim($ruta) !== ''
        );

        $this->assertSame(
            [],
            array_values($datos),
            "Hay volcados de datos versionados en un repositorio público:\n" . implode("\n", $datos)
        );
    }

    public function test_el_seeder_no_crea_el_usuario_de_clave_conocida_en_produccion(): void
    {
        // Dos trampas que costaron un rato:
        //
        //  - `config(['app.env' => ...])` no sirve: `app()->environment()` lee
        //    `$app['env']`, que se resuelve al arrancar y no vuelve a mirar la
        //    configuración. Con config() la prueba pasaba sin probar nada.
        //  - `$this->seed()` tampoco: el comando `db:seed` trae su propia
        //    confirmación de producción y aborta antes de llegar a la guarda
        //    del seeder. Hay que instanciarlo, que es lo que se quiere probar.
        $this->app->detectEnvironment(fn () => 'production');

        (new UserSeeder())->run();

        $this->assertNull(
            User::where('email', 'admin@admin.com')->first(),
            'El seeder creó la cuenta de clave conocida en producción.'
        );
    }

    /**
     * En desarrollo sí tiene que seguir andando: la base es descartable y la
     * clave fija ahorra tiempo todos los días. Si esto se rompe, nadie puede
     * levantar el proyecto.
     */
    public function test_en_desarrollo_el_seeder_sigue_sirviendo(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        (new UserSeeder())->run();

        $admin = User::where('email', 'admin@admin.com')->first();

        $this->assertNotNull($admin, 'El seeder dejó de servir para desarrollo.');
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->hasRole('developer'));
    }
}
