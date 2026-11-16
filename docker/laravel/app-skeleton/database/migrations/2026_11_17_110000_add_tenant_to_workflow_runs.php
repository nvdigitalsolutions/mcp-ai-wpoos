<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow runs acquire an estate — Proposal 149 §18.
 *
 * `nvoos_workflow_runs` was written with no tenant, which is invisible while the
 * only reader is the console: nothing pins a tenant on `/api/v1`, so every
 * request looked at the same rows. A read-only run list on `/admin` changes
 * that — an estate's administrator must not see another estate's runs, and the
 * per-node `events` payload is exactly the kind of object property API3/BOLA is
 * about.
 *
 * The column carries a default so existing rows are attributed to the default
 * estate without a backfill pass. That is the correct attribution for a
 * single-estate deployment (every deployment that predates tenancy), and it is
 * the only defensible guess for a multi-estate one: the row records no author
 * estate, so inventing one would be worse than the documented default.
 *
 * Guarded per column, so the migration replays on SQLite as well as MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_workflow_runs')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_workflow_runs', 'tenant_id')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_workflow_runs')) {
            return;
        }

        if (Schema::hasColumn('nvoos_workflow_runs', 'tenant_id')) {
            Schema::table('nvoos_workflow_runs', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
