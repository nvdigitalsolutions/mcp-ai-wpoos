<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site roles on users, using the WordPress vocabulary (090-g Phase 5).
 *
 * The console already speaks WP capabilities (`manage_options`, `edit_posts`)
 * and `CapabilityMap` already tried to read `$user->role` — but there was no
 * column in the schema, so every user resolved to the non-admin set and admin
 * surfaces were hidden in the UI while remaining open on the API.
 *
 * Backfill: accounts that predate the column are promoted to `administrator`.
 * Without it the deploy would leave the operator as `subscriber` (the column
 * default) and lock them out of their own console.
 *
 * The column is added only when it is missing: a database that already grew a
 * `role` column out of band keeps its values, and only rows still sitting on
 * the default are promoted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role', 32)->default('subscriber')->index();
            });
        }

        DB::table('users')
            ->where('role', 'subscriber')
            ->update(['role' => 'administrator']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            });
        }
    }
};
