<?php

namespace Tests\Feature\Assistant;

use App\Livewire\Assistant\AssistantChat;
use App\Models\User;
use App\Models\WebConversation;
use App\Services\Assistant\AssistantWebHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AssistantChatComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_send_message_appends_reply(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $handler = Mockery::mock(AssistantWebHandler::class);
        $handler->shouldReceive('handle')->once()->andReturn('Hola, ¿en qué te ayudo?');
        $this->app->instance(AssistantWebHandler::class, $handler);

        Livewire::actingAs($user)
            ->test(AssistantChat::class, ['currentRoute' => 'dashboard'])
            ->set('draft', '¿cómo registro una venta?')
            ->call('send')
            ->assertSee('Hola, ¿en qué te ayudo?')
            ->assertSet('draft', '');
    }

    public function test_clear_empties_conversation(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        WebConversation::getOrCreate($user)->saveHistory([['role' => 'user', 'content' => 'x']], 12);

        Livewire::actingAs($user)
            ->test(AssistantChat::class, ['currentRoute' => 'dashboard'])
            ->call('clear');

        $this->assertSame([], WebConversation::getOrCreate($user)->history());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
