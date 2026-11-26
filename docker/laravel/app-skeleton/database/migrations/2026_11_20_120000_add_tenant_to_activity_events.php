<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity feed acquires an estate — Proposal 149 §106.
 *
 * `nvoos_activity_events` was written with no tenant, which is invisible while
 * its only reader is the console's dashboard: `/api/v1` pins one tenant per
 * deployment, so every reader already saw a single estate's rows. Putting the
 * same feed on `/admin` changes that — an estate's administrator must not read
 * another estate's activity, and the `context` column carries workflow run
 * detail, which is the kind of object property API3/BOLA is about.
 *
 * The column carries a default so existing rows are attributed to the default
 * estate without a backfill pass, exactly as §18 did for
 * `nvoos_workflow_runs`. That is the correct attribution for a single-estate
 * deployment (every deployment that predates tenancy) and the only defensible
 * guess for a multi-estate one: the row records no author estate, so inventing
 * one would be worse than the documented default. **Rows written before this
 * migration cannot recover their true estate.**
 *
 * Guarded per column, so the migration replays on SQLite as well as MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_activity_events')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_activity_events', 'tenant_id')) {
            Schema::table('nvoos_activity_events', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_activity_events')) {
            return;
        }

        if (Schema::hasColumn('nvoos_activity_events', 'tenant_id')) {
            Schema::table('nvoos_activity_events', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
