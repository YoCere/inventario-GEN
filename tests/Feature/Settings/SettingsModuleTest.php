<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_grouped_settings_page(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Datos de la empresa')
            ->assertSeeText('Ajustes financieros')
            ->assertSeeText('Impuestos Bolivia');
    }

    public function test_non_admin_cannot_access_settings_page(): void
    {
        $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

        $this->actingAs($staff)
            ->get(route('settings.index'))
            ->assertForbidden();
    }
}
