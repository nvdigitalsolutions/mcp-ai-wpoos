<?php

namespace Database\Seeders;

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

        $this->call(ProjectManagementSeeder::class);
    }
}
