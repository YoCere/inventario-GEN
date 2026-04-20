<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_grouped_settings_page(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Datos de la empresa')
            ->assertSeeText('Ajustes financieros')
            ->assertSeeText('Impuestos Bolivia');
    }

    public function test_non_admin_cannot_access_settings_page(): void
    {
        $staff = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)
            ->get(route('settings.index'))
            ->assertForbidden();
    }
}

