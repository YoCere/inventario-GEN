<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->markTestSkipped(
            'Rutas /register fueron removidas de routes/auth.php. Los nuevos usuarios ' .
            'se crean exclusivamente desde el admin (/users), no por registro público. ' .
            'Re-habilitar este test si se restaura el registro abierto.'
        );
    }

    public function test_new_users_can_register(): void
    {
        $this->markTestSkipped('Registro público deshabilitado — ver test_registration_screen_can_be_rendered.');
    }
}
