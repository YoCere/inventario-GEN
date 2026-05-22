<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Asigna un rol spatie al usuario después de crearlo. El rol debe existir
     * (la migración migrate_user_roles_to_spatie seedea developer/admin/staff
     * en RefreshDatabase, así que tests pueden invocar ->admin() / ->staff() /
     * ->developer() libremente).
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(function ($user) use ($role) {
            \Spatie\Permission\Models\Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        });
    }

    public function admin(): static
    {
        return $this->withRole('admin');
    }

    public function developer(): static
    {
        return $this->withRole('developer');
    }

    public function staff(): static
    {
        return $this->withRole('staff');
    }
}
