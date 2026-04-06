<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
{
    $faker = \Faker\Factory::create('es_ES'); // español estándar, funciona bien
    return [
        'name' => $faker->name(),
        'email' => $faker->unique()->safeEmail(),
        'phone' => $faker->numerify('7########'),
        'address' => $faker->city() . ', Bolivia',
        'notes' => $faker->optional(0.3)->sentence(),
    ];
}
}
