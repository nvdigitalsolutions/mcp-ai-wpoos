<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media library acquires an estate — Proposal 149 §109.
 *
 * `nvoos_files` was written with no tenant, which is invisible while its only
 * callers run one-estate-per-deployment: `/api/v1` pins one estate, so listing
 * the table listed one estate's files. Putting the library on `/admin` changes
 * that, and so does the sweep: `MediaSweeper` selects across the whole table, so
 * one estate's "compress my library" action would queue another estate's files.
 *
 * The column carries a default so existing rows are attributed to the default
 * estate without a backfill, exactly as §18 did for `nvoos_workflow_runs`. That
 * is correct for a single-estate deployment and the only defensible guess for a
 * multi-estate one: the row records no owner estate, so **rows written before
 * this migration cannot recover their true estate**.
 *
 * Guarded per column, so the migration replays on SQLite as well as MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_files', 'tenant_id')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_files')) {
            return;
        }

        if (Schema::hasColumn('nvoos_files', 'tenant_id')) {
            Schema::table('nvoos_files', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
