<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token usage acquires an estate — Proposal 152.
 *
 * `nvoos_token_usage` recorded no estate, and `/api/v1/cost/*` (the
 * `manage_options` surface) read it unscoped — including `top_users` resolved
 * to names. An estate administrator could read another estate's spend. This is
 * the same shape §18 gave `nvoos_workflow_runs`: a defaulted column, an index,
 * and no backfill pass. A pre-tenancy row records no estate, so `default` is the
 * only defensible attribution and inventing one would be worse.
 *
 * Guarded per table and column so the migration replays on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_token_usage';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('tenant_id', 64)->default('default');
            $table->index('tenant_id', 'nvoos_token_usage_tenant_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex('nvoos_token_usage_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
    }
};
