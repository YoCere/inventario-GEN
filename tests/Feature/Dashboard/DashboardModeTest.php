<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_mode_switcher_with_percent_default(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Modo:')
            ->assertSeeText('Porcentajes');
    }

    public function test_staff_does_not_see_mode_switcher(): void
    {
        $staff = User::factory()->staff()->create(['email_verified_at' => now()]);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeText('Modo:');
    }
}

