<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin audit trail and saved table views — Proposal 149 T-0.9.
 *
 * `nvoos_admin_audit` is APPEND-ONLY: there is no `updated_at`, and
 * `AdminAuditLogger` only ever inserts. An audit trail that can be edited is
 * not evidence.
 *
 * `user_id` is deliberately NOT a foreign key. A cascading delete would erase
 * the record of what a removed user did, which is exactly the case an audit
 * trail exists to answer. The actor id is stored as a plain column.
 *
 * `tenant_id` is nullable because the operator panel is not tenant-scoped: an
 * action taken there belongs to no single estate.
 *
 * `nvoos_admin_views` is the saved-view store (filters + sort + column
 * visibility per user, per resource, per estate). It is separate from the
 * audit table because it is mutable: a user renames and deletes their own
 * views.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_admin_audit')) {
            Schema::create('nvoos_admin_audit', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('panel', 32);
                $table->string('method', 8);
                $table->string('path', 255);
                $table->string('route', 191)->nullable();
                $table->unsignedSmallInteger('status');
                $table->json('payload')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable();

                // "What happened on this estate, newest first" is the primary
                // read, so it gets a composite index rather than two singles.
                $table->index(array('tenant_id', 'created_at'));
                $table->index(array('user_id', 'created_at'));
                $table->index('route');
            });
        }

        if (! Schema::hasTable('nvoos_admin_views')) {
            Schema::create('nvoos_admin_views', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64)->default('default');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('resource', 120);
                $table->string('name', 120);
                $table->json('state');
                $table->timestamps();

                // One view name per user, per resource, per estate. A user who
                // moves between estates keeps separate saved views for each.
                $table->unique(array('tenant_id', 'user_id', 'resource', 'name'));
                $table->index(array('tenant_id', 'user_id', 'resource'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_admin_views');
        Schema::dropIfExists('nvoos_admin_audit');
    }
};
