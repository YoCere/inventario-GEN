<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-' . date('ymd') . '-' . strtoupper(fake()->unique()->bothify('????')),
            'customer_id' => null,
            'created_by' => User::factory(),
            'sale_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'status' => SaleStatus::COMPLETED,
            'subtotal' => 0,
            'global_discount' => 0,
            'total_discount' => 0,
            'total' => 0,
            'cash_received' => 0,
            'change' => 0,
            'payment_method' => PaymentMethod::CASH,
            'source' => 'pos',
            'notes' => null,
        ];
    }
}
