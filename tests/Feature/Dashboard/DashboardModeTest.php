<?php

namespace Tests\Feature\Dashboard;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_mode_switcher_with_percent_default(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Modo:')
            ->assertSeeText('Porcentajes');
    }

    public function test_staff_does_not_see_mode_switcher(): void
    {
        $staff = User::factory()->create([
            'email_verified_at' => now(),
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeText('Modo:');
    }
}

