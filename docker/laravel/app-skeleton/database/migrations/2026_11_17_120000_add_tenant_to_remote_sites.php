<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate ownership for remote sites — Proposal 149 §27.
 *
 * `nvoos_remote_sites` shipped without a tenant column, and `RemoteSiteStore`
 * read and wrote it unscoped. The routes already sat inside `ResolveApiTenant`,
 * so a tenant was resolved on every request and then ignored: any estate
 * administrator could list, edit and delete every other estate's connections.
 * That is a cross-tenant WRITE, the same class of defect §19 fixed for
 * assistant credentials.
 *
 * The default is `'default'` rather than null. Existing rows predate the
 * column and no signal exists that could attribute them to another estate, so
 * leaving them null would either hide real connections or require a nullable
 * branch in every query. They belong to the default estate, which is where a
 * single-estate install would have created them anyway.
 *
 * Guarded with `hasColumn`, so a dev database that grew the column out of band
 * keeps working (repo rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_remote_sites')) {
            return;
        }

        if (! Schema::hasColumn('nvoos_remote_sites', 'tenant_id')) {
            Schema::table('nvoos_remote_sites', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default');
            });
        }

        Schema::table('nvoos_remote_sites', function (Blueprint $table): void {
            // "This estate's connections, by name" is the list read, so it gets
            // a composite index rather than a single-column one.
            $table->index(array('tenant_id', 'name'), 'nvoos_remote_sites_tenant_name_index');
        });
    }

    public function down(): void
    {
        // Connections are user data; a rollback must not delete them. Dropping
        // `tenant_id` would also silently re-expose every estate's rows, so the
        // column is removed by hand if it ever should be.
    }
};
