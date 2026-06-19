<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reminder>
 */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_id' => (string) fake()->numberBetween(100000, 999999),
            'title' => fake()->sentence(3),
            'body' => null,
            'remind_at' => now()->addHour(),
            'timezone' => 'UTC',
            'recurrence' => 'none',
            'recurrence_rule' => null,
            'status' => 'pending',
            'sent_count' => 0,
            'created_via' => 'command',
        ];
    }

    public function due(): static
    {
        return $this->state(fn () => ['remind_at' => now()->subMinute()]);
    }

    public function daily(): static
    {
        return $this->state(fn () => ['recurrence' => 'daily']);
    }
}
