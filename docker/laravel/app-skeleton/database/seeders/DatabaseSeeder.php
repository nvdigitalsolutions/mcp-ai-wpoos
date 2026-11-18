<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // The demo/bootstrap account is an administrator: the console's T0
        // surfaces (Settings, Operations, Diagnostics, Tools) are gated on
        // `manage_options`, and `role` defaults to `subscriber` for any row
        // created after the role migration's backfill. The e2e smoke signs in
        // as this user and expects those pages to render.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'administrator',
        ]);

        $this->seedOperatorMembership();

        $this->call(ProjectManagementSeeder::class);
        $this->call(ModelConfigSeeder::class);
    }

    /**
     * Attach administrators to the vendor estate (Proposal 149 T-0.3/T-0.5).
     *
     * `/ops` access is membership in the estate whose `is_operator` is true
     * plus `manage_options` — a site role alone must never grant it, which is
     * why this is an explicit seeder step rather than a model rule. Without
     * it, a fresh local install has an operator estate nobody belongs to, and
     * the panel is unreachable.
     *
     * Production never runs this seeder: the operator estate is seeded by
     * hand there, so a deploy cannot manufacture staff access.
     */
    private function seedOperatorMembership(): void
    {
        $operator = Tenant::query()->operators()->first();

        if (null === $operator) {
            return;
        }

        User::query()
            ->whereIn('role', array('administrator', 'admin'))
            ->each(function (User $user) use ($operator): void {
                $operator->users()->syncWithoutDetaching(array(
                    $user->id => array('role' => 'owner'),
                ));
            });
    }
}
