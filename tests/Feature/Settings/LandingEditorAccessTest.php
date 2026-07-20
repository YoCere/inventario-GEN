<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingEditorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_open_the_landing_editor(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.shop-landing'))->assertOk();
    }

    public function test_staff_without_permission_gets_403(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('settings.shop-landing'))->assertForbidden();
    }

    public function test_developer_can_open_it(): void
    {
        $dev = User::factory()->developer()->create();

        $this->actingAs($dev)->get(route('settings.shop-landing'))->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.shop-landing'))->assertRedirect(route('login'));
    }

    public function test_settings_page_links_to_the_landing_editor_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.shop-landing'), false);
    }
}
