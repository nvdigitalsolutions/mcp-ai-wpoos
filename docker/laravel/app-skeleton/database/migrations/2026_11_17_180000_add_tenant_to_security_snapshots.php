<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estate ownership for security snapshots — Proposal 149 §51.
 *
 * `nvoos_security_snapshots` stored a **settings payload**, and settings are
 * estate-scoped (`nvoos_tenant_settings`), but the table itself had no estate
 * column and `SecurityController` never filtered by one. Two consequences, both
 * worse than the toolkit-record case §47 fixed because the payload is
 * configuration rather than business records:
 *
 *  - **A cross-estate READ.** `GET /security/snapshots` listed every estate's
 *    labels and timestamps.
 *  - **A cross-estate WRITE.** `POST /security/restore` accepted any snapshot
 *    id and wrote that payload into the caller's settings. An estate
 *    administrator could copy another estate's configuration — provider,
 *    model, endpoints, feature flags — into their own, then read it back from
 *    the settings surface. Secrets were already masked at write time, so this
 *    was configuration disclosure rather than credential theft; that is
 *    mitigation, not a fix.
 *
 * The primary key is a UUID, so there is no unique key to move — only an index
 * for the list read, which is "this estate's snapshots, newest first".
 *
 * Default `'default'`: existing rows predate the column and no signal
 * attributes them to another estate (§27's reasoning, applied again).
 *
 * Guarded on every step, because a dev database can grow a column or an index
 * out of band (repo rule).
 */
return new class extends Migration
{
    private const TABLE = 'nvoos_security_snapshots';

    private const INDEX = 'nvoos_security_snapshots_tenant_created_index';

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

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(array('tenant_id', 'created_at'), self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // Snapshots are user data. Dropping `tenant_id` would silently
        // re-expose every estate's configuration history, so the column is
        // removed by hand if it ever should be.
    }
};
