<?php

namespace Tests\Feature\Install;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `usuario:crear` es como el proveedor se da acceso en la instancia de un
 * cliente: `instalar:cliente` crea solo a la persona dueña.
 */
class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_crea_el_usuario_developer_y_puede_entrar_a_ajustes_tecnicos(): void
    {
        $this->artisan('usuario:crear', [
            '--nombre' => 'Jose Soporte',
            '--email' => 'jose@proveedor.bo',
            '--rol' => 'developer',
            '--password' => 'clave-de-soporte-2026',
        ])->assertSuccessful();

        $user = User::where('email', 'jose@proveedor.bo')->firstOrFail();

        $this->assertTrue($user->hasRole('developer'));
        $this->assertTrue(Hash::check('clave-de-soporte-2026', $user->password));
        $this->assertNotNull($user->email_verified_at);

        // El rol developer es el único que ve la sección Sistema de Ajustes.
        $this->assertTrue($user->can('settings.edit-technical'));
        $this->actingAs($user)->get(route('settings.index', ['seccion' => 'sistema']))->assertOk();
    }

    public function test_genera_contrasenia_si_no_se_indica(): void
    {
        $this->artisan('usuario:crear', [
            '--nombre' => 'Soporte',
            '--email' => 'soporte@proveedor.bo',
        ])->assertSuccessful();

        $user = User::where('email', 'soporte@proveedor.bo')->firstOrFail();

        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertTrue($user->hasRole('developer'), 'Sin --rol debe caer en developer.');
    }

    public function test_no_repite_el_username_cuando_dos_personas_se_llaman_igual(): void
    {
        $this->artisan('usuario:crear', ['--nombre' => 'Ana Lopez', '--email' => 'ana1@x.bo'])->assertSuccessful();
        $this->artisan('usuario:crear', ['--nombre' => 'Ana Lopez', '--email' => 'ana2@x.bo'])->assertSuccessful();

        $this->assertSame(2, User::count());
        $this->assertSame(2, User::distinct()->count('username'));
    }

    public function test_rechaza_correo_repetido_rol_inexistente_y_clave_corta(): void
    {
        $this->artisan('usuario:crear', ['--nombre' => 'Uno', '--email' => 'uno@x.bo'])->assertSuccessful();

        $this->artisan('usuario:crear', ['--nombre' => 'Otro', '--email' => 'uno@x.bo'])
            ->expectsOutputToContain('Ya existe un usuario')
            ->assertFailed();

        $this->artisan('usuario:crear', ['--nombre' => 'Otro', '--email' => 'otro@x.bo', '--rol' => 'jefe'])
            ->expectsOutputToContain("El rol 'jefe' no existe")
            ->assertFailed();

        $this->artisan('usuario:crear', ['--nombre' => 'Otro', '--email' => 'otro@x.bo', '--password' => '123'])
            ->expectsOutputToContain('al menos 8 caracteres')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_rechaza_correo_invalido(): void
    {
        $this->artisan('usuario:crear', ['--nombre' => 'Uno', '--email' => 'no-es-correo'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
