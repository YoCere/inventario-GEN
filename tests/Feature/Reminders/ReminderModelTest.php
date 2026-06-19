<?php

namespace Tests\Feature\Reminders;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_scopes_reminders_to_a_single_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Reminder::factory()->count(2)->create(['user_id' => $alice->id]);
        Reminder::factory()->create(['user_id' => $bob->id]);

        $this->assertSame(2, Reminder::forUser($alice->id)->count());
        $this->assertSame(1, Reminder::forUser($bob->id)->count());
    }

    public function test_casts_recurrence_rule_to_array_and_detects_recurring(): void
    {
        $reminder = Reminder::factory()->daily()->create([
            'recurrence_rule' => ['days' => [1, 3]],
        ]);

        $this->assertSame(['days' => [1, 3]], $reminder->recurrence_rule);
        $this->assertTrue($reminder->isRecurring());
    }
}
