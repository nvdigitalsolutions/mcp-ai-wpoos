<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harness traces acquire an estate — Proposal 152, Phase 2.
 *
 * `nvoos_harness_trace_records` recorded the assistant but not the estate. That
 * is enough while `/ops/observability` — the only reader — is operator-only, and
 * not enough the moment an estate-facing trace surface exists. §18's lesson was
 * that the schema change comes **before** the surface, not after the first
 * estate administrator asks for it.
 *
 * The column carries a default so existing rows are attributed to the default
 * estate without a backfill pass, the same shape §86 gave the token tables. A
 * pre-tenancy trace records no estate, so `default` is the only defensible
 * attribution and inventing one would be worse.
 *
 * Guarded per table and column so the migration replays on SQLite.
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_harness_trace_records';

    private const INDEX = 'nvoos_harness_trace_records_tenant_id_index';

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
            $table->index('tenant_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('tenant_id');
        });
    }
};
