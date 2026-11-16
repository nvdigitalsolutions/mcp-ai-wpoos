<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Support\CapabilityMap;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
     * The estate the created user joins, or null to leave them unprovisioned.
     *
     * Defaulting to the default estate is not a convenience: the API resolves an
     * estate from membership (149 §21), and the migration backfills every
     * existing user into `default`. A fixture user with no membership models a
     * state production cannot reach, so tests using one would be testing a
     * scenario that does not exist.
     */
    protected ?string $estate = Tenant::DEFAULT_ID;

    /**
     * Explicit role inside the estate. Empty derives it from the site role.
     */
    protected string $estateRole = '';

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
     * Provision the estate after the row exists.
     *
     * Overridden rather than registered in `configure()`: `state()` builds a new
     * factory instance and `configure()` only runs for `Factory::new()`, so a
     * callback registered there would read whichever instance happened to be
     * configured first. `create()` is always called on the final instance.
     */
    public function create($attributes = [], ?Model $parent = null): User
    {
        /** @var User $user */
        $user = parent::create($attributes, $parent);

        $this->provision($user);

        return $user;
    }

    /**
     * Join a specific estate instead of the default one.
     */
    public function inEstate(string $tenantId, string $role = ''): static
    {
        $this->estate = $tenantId;
        $this->estateRole = $role;

        return $this;
    }

    /**
     * Leave the user with no estate membership.
     *
     * The state the API refuses with 403; used by the test that pins that
     * refusal, and by nothing else.
     */
    public function withoutEstate(): static
    {
        $this->estate = null;
        $this->estateRole = '';

        return $this;
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

    /**
     * Attach the membership, deriving the role from the site role when the
     * caller did not pin one.
     */
    private function provision(User $user): void
    {
        if (null === $this->estate || ! Schema::hasTable('tenants')) {
            return;
        }

        if (! Tenant::query()->whereKey($this->estate)->exists()) {
            return;
        }

        $role = '' !== $this->estateRole
            ? $this->estateRole
            : CapabilityMap::estateRoleFor(CapabilityMap::roleOf($user));

        $user->tenants()->syncWithoutDetaching([
            $this->estate => ['role' => $role],
        ]);
    }
}
