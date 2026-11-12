<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
     * Give the user a site role (090-g Phase 5).
     *
     * `role` is deliberately not mass-assignable — a request body must never
     * choose it — so the state force-fills the column instead.
     */
    public function role(string $role): static
    {
        return $this->afterMaking(
            fn (User $user) => $user->forceFill(['role' => $role])
        );
    }

    /**
     * An administrator (manage_options).
     */
    public function admin(): static
    {
        return $this->role('administrator');
    }

    /**
     * An editor (can edit other users' content).
     */
    public function editor(): static
    {
        return $this->role('editor');
    }
}
