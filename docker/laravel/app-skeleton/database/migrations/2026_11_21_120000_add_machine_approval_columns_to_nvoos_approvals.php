<?php

/**
 * Machine tool approvals — Proposal 159 slice 4 (149 plan §124).
 *
 * Three columns the retry-to-consume protocol needs:
 *
 *  - `tenant_id` — the live table has carried it since the approvals panel
 *    shipped, but **no migration ever created it**: a fresh `migrate` produced a
 *    table that the `Approval` model's tenant scope could not query, so every
 *    read failed with an unknown column. This closes that gap idempotently
 *    rather than leaving a schema that only matches the dev database.
 *  - `arguments_hash` — the decision is about *this* call, not the tool in
 *    general. Matching on a canonically-ordered hash means an approval for
 *    `{a:1,b:2}` cannot authorise `{a:1,b:3}`.
 *  - `consumed_at` — an approval is single-use. Without it one approved call
 *    could be replayed by the same credential indefinitely.
 *
 * Guarded by `hasColumn()` so an install whose live table already differs is
 * recorded as migrated rather than altered twice.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_approvals')) {
            return;
        }

        Schema::table('nvoos_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('nvoos_approvals', 'tenant_id')) {
                $table->string('tenant_id', 64)->default('default')->after('id');
                $table->index('tenant_id');
            }

            if (! Schema::hasColumn('nvoos_approvals', 'arguments_hash')) {
                $table->string('arguments_hash', 64)->nullable()->after('arguments');
                $table->index('arguments_hash');
            }

            if (! Schema::hasColumn('nvoos_approvals', 'consumed_at')) {
                $table->timestamp('consumed_at')->nullable()->after('resolved_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_approvals')) {
            return;
        }

        Schema::table('nvoos_approvals', function (Blueprint $table): void {
            foreach (array('consumed_at', 'arguments_hash', 'tenant_id') as $column) {
                if (Schema::hasColumn('nvoos_approvals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
