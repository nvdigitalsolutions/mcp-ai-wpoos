<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record-level audit columns — Proposal 149 T-0.9, deferred to Wave 2.
 *
 * Wave 0 recorded one row per mutating request, which answers "who tried". It
 * could not answer "what changed": a diff needs model events, and no admin
 * resource existed until Wave 2's first surface (estates). Writing the columns
 * then would have been a column set that never fills.
 *
 * Two kinds of row now share the table, distinguished by `resource`:
 *
 *  - `resource` IS NULL     — a request row, written by `AuditAdminRequests`.
 *    `method`/`path`/`route`/`status`/`payload` are meaningful.
 *  - `resource` IS NOT NULL — a record row, written by an observer through
 *    `AdminAuditLogger::recordResource()`. `action`/`before`/`after` are
 *    meaningful, and `method` mirrors the action's HTTP verb so a single
 *    `ORDER BY created_at` still reads chronologically.
 *
 * A resource write therefore produces two rows. That is deliberate: a bulk
 * action touches many records, so one request row could not carry the diffs.
 *
 * Additive and guarded — `Schema::hasColumn` before every add — so a dev
 * database that grew the columns out of band keeps working (repo rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_admin_audit')) {
            return;
        }

        $columns = array(
            'resource' => static fn (Blueprint $table) => $table->string('resource', 120)->nullable(),
            'record_id' => static fn (Blueprint $table) => $table->string('record_id', 64)->nullable(),
            'action' => static fn (Blueprint $table) => $table->string('action', 16)->nullable(),
            'before' => static fn (Blueprint $table) => $table->json('before')->nullable(),
            'after' => static fn (Blueprint $table) => $table->json('after')->nullable(),
        );

        foreach ($columns as $name => $define) {
            if (Schema::hasColumn('nvoos_admin_audit', $name)) {
                continue;
            }

            Schema::table('nvoos_admin_audit', function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }

        // "Everything that happened to this record, newest first" is the read
        // these columns exist for, so it gets an index rather than two singles.
        Schema::table('nvoos_admin_audit', function (Blueprint $table): void {
            $table->index(array('resource', 'record_id'), 'nvoos_admin_audit_record_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nvoos_admin_audit')) {
            return;
        }

        Schema::table('nvoos_admin_audit', function (Blueprint $table): void {
            $table->dropIndex('nvoos_admin_audit_record_index');
        });

        foreach (array('after', 'before', 'action', 'record_id', 'resource') as $name) {
            if (! Schema::hasColumn('nvoos_admin_audit', $name)) {
                continue;
            }

            Schema::table('nvoos_admin_audit', function (Blueprint $table) use ($name): void {
                $table->dropColumn($name);
            });
        }
    }
};
