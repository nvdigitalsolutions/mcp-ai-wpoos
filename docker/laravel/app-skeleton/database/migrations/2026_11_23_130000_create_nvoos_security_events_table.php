<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security-event stream — Proposal 149 §186.
 *
 * Separate from `nvoos_admin_audit` on purpose. That table answers "which admin
 * request changed what"; this one answers "who tried to get in, and did it work".
 * A failed sign-in has no admin request and no resource, and putting it in the
 * admin table would make every audit query filter a row kind it does not want.
 *
 * APPEND-ONLY, like the admin trail: no `updated_at`, and the logger only ever
 * inserts. An audit record that can be edited is not evidence.
 *
 * `user_id` is deliberately not a foreign key. A cascading delete would erase the
 * record of what a removed user did — exactly the case an audit trail exists to
 * answer.
 *
 * `tenant_id` is nullable because a failed sign-in can happen before any estate
 * is resolved, and an unauthenticated attempt is still worth recording.
 *
 * Rows exist only when `enable_security_audit_log` is on (default off), so this
 * table is empty on a default install and the write path costs nothing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nvoos_security_events')) {
            return;
        }

        Schema::create('nvoos_security_events', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64)->nullable();
            $table->string('event', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('context', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The two reads this table serves: "recent events" and "events of one
            // kind over a window". The retention sweep scans `created_at`.
            $table->index(array('event', 'created_at'));
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_security_events');
    }
};
