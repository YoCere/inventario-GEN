<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Models\WebConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_or_create_returns_single_row_per_user(): void
    {
        $user = User::factory()->create();

        $a = WebConversation::getOrCreate($user);
        $b = WebConversation::getOrCreate($user);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, WebConversation::where('user_id', $user->id)->count());
    }

    public function test_history_round_trips_and_trims(): void
    {
        $user = User::factory()->create();
        $conv = WebConversation::getOrCreate($user);

        $messages = [];
        for ($i = 0; $i < 50; $i++) {
            $messages[] = ['role' => 'user', 'content' => "m{$i}"];
        }

        $conv->saveHistory($messages, limit: 12);

        $this->assertCount(12, $conv->fresh()->history());
        $this->assertSame('m49', $conv->fresh()->history()[11]['content']);
    }

    public function test_trim_drops_leading_orphan_tool_result(): void
    {
        $user = User::factory()->create();
        $conv = WebConversation::getOrCreate($user);

        // Build messages so the slice boundary lands on a tool_result user message.
        $messages = [
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'x', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => '{}']]],
            ['role' => 'assistant', 'content' => 'respuesta final'],
        ];

        $conv->saveHistory($messages, limit: 2);

        $history = $conv->fresh()->history();
        // slice(-2) would start with the orphan tool_result user message → must be dropped
        $first = $history[0] ?? null;
        $this->assertNotNull($first);
        $isOrphanToolResult = ($first['role'] ?? '') === 'user'
            && is_array($first['content'] ?? null)
            && (($first['content'][0]['type'] ?? '') === 'tool_result');
        $this->assertFalse($isOrphanToolResult, 'history must not start with an orphan tool_result');
    }

    public function test_clear_empties_history(): void
    {
        $user = User::factory()->create();
        $conv = WebConversation::getOrCreate($user);
        $conv->saveHistory([['role' => 'user', 'content' => 'hola']], limit: 12);

        $conv->clear();

        $this->assertSame([], $conv->fresh()->history());
    }
}
