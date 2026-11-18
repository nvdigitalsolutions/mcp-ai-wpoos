<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate ownership for toolkit records — Proposal 149 §47.
 *
 * `nvoos_toolkit_records` shipped without a tenant column. `ToolkitsController`
 * (now `ToolkitEngine`) read and wrote it scoped only by `toolkit` + `entity`,
 * while every `/pro/toolkits` route already sat inside `ResolveApiTenant` — so
 * an estate was resolved on every request and then ignored. The result was both
 * halves of the defect §27 fixed for remote sites:
 *
 *  - a cross-tenant READ: one estate listed, edited and deleted another's rows;
 *  - a cross-tenant COLLISION: the unique key was
 *    `(toolkit, entity, external_id)` **globally**, so two estates could not
 *    both hold `SKU-1` — the second create failed with a duplicate that was
 *    really about someone else's data.
 *
 * Adding the column alone would convert the silent read into a constraint
 * failure on write, so the unique has to become composite in the same migration.
 *
 * The default is `'default'`, not null: existing rows predate the column and no
 * signal attributes them to another estate, so they belong where a
 * single-estate install would have created them (§27's reasoning).
 *
 * Guarded on every step, because a dev database can grow a column or an index
 * out of band (repo rule).
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_toolkit_records';

    private const OLD_UNIQUE = 'nvoos_toolkit_records_toolkit_entity_external_id_unique';

    private const NEW_UNIQUE = 'nvoos_toolkit_records_tenant_toolkit_entity_external_id_unique';

    private const OLD_INDEX = 'nvoos_toolkit_records_toolkit_entity_index';

    private const NEW_INDEX = 'nvoos_toolkit_records_tenant_toolkit_entity_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
            });
        }

        // Explicit backfill: a column that grew nullable out of band would
        // otherwise keep nulls, and `where tenant_id = ?` never matches null.
        DB::table(self::TABLE)->whereNull('tenant_id')->update(array('tenant_id' => 'default'));

        // The global unique becomes estate-scoped. Dropped first: MySQL will not
        // accept a second index with the same columns while this one exists.
        if (Schema::hasIndex(self::TABLE, self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    array('tenant_id', 'toolkit', 'entity', 'external_id'),
                    self::NEW_UNIQUE
                );
            });
        }

        // The list read is "this estate's records of this entity", so the
        // non-unique index leads with the estate too.
        if (Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::OLD_INDEX);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    array('tenant_id', 'toolkit', 'entity'),
                    self::NEW_INDEX
                );
            });
        }
    }

    public function down(): void
    {
        // Records are user data; a rollback must not delete them. Dropping
        // `tenant_id` would also silently re-expose every estate's rows and
        // restore a unique key that can no longer express the data, so the
        // column is removed by hand if it ever should be.
    }
};
