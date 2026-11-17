<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate ownership for workflow triggers — Proposal 149 §29.
 *
 * `nvoos_triggers` shipped with no tenant column, and `TriggersController` read
 * and wrote it unscoped: `index`, `store`, `update`, `destroy` and `fire` all
 * named the table directly. Because the routes already sit inside
 * `ResolveApiTenant`, every request resolved an estate and then ignored it — so
 * any tenant user could list, rewrite, delete and **fire** another estate's
 * triggers. Same defect class as §19 (credentials) and §27 (remote sites).
 *
 * `fire` is the sharp edge: it executes the trigger's workflow with the
 * caller's user id, so a cross-estate fire ran someone else's automation under
 * the caller's identity.
 *
 * The default is `'default'` for the same reason as §27: existing rows predate
 * the column and no signal can attribute them to another estate.
 *
 * Guarded with `hasColumn`, so a dev database that grew the column out of band
 * keeps working (repo rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_triggers')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_triggers', 'tenant_id')) {
            Schema::table('nvoos_triggers', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
            });
        }

        Schema::table('nvoos_triggers', function (Blueprint $table): void {
            // "This estate's triggers, by name" is the list read, so it gets a
            // composite index rather than a single-column one.
            $table->index(array('tenant_id', 'name'), 'nvoos_triggers_tenant_name_index');
        });
    }

    public function down(): void
    {
        // Triggers are user data, and dropping `tenant_id` would silently
        // re-expose every estate's rows. Removed by hand if it ever should be.
    }
};
