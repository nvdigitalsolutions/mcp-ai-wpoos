<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The JIT-provisioning substrate — Proposal 149 §110, decisions 158 D2–D4.
 *
 * Two tables, one migration, because they are two halves of one decision and a
 * half-migrated `jit_provisioning_requests` would record identities an operator
 * could act on with no allowlist to check them against.
 *
 * ## `nvoos_estate_domains` (158 D3)
 *
 * The allowlist is a table rather than a setting because `nvoos_tenant_settings`
 * is `(tenant_id, key) → TEXT`: a list there is either JSON with unenforceable
 * uniqueness or a new type threaded through the catalogue, the writer and the
 * renderer. A table gives the database the uniqueness, and holds `verified_at`
 * and `created_by`, which a scalar cannot.
 *
 * `verified_at` is nullable **on purpose** and gates nothing until it is set:
 * a domain someone proposed but nobody confirmed must not provision. That is
 * 158 D3's "fail closed", expressed as a column rather than a convention.
 *
 * ## `nvoos_jit_provisioning_requests` (158 D2)
 *
 * One row per subject that authenticated and was **not** provisioned, with the
 * reason. The row is what makes "off by default" survivable: without it, a
 * denied login is an unexplained 403, which is what gets a deny-by-default
 * decision reversed under support pressure.
 *
 * `UNIQUE (tenant_id, subject)` rather than per-attempt rows: a person who tries
 * five times is one request, not five, and the operator's list must not fill
 * with duplicates. A repeat attempt refreshes `reason` and `attempted_at`
 * instead of inserting.
 *
 * `status` is `pending` until an operator promotes or dismisses. A promoted
 * request also creates the membership, so the next login resolves through the
 * `auth0_subject` link and never returns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            Schema::create('nvoos_estate_domains', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64);
                // Stored lowercase in A-label (punycode) form, so one domain has
                // one spelling and a homograph cannot be introduced by case or
                // by a Unicode label (§110.2).
                $table->string('domain', 255);
                $table->timestamp('verified_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(array('tenant_id', 'domain'));
            });
        }

        if (! Schema::hasTable('nvoos_jit_provisioning_requests')) {
            Schema::create('nvoos_jit_provisioning_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64);
                // The IdP's immutable subject identifier. Never the email: an
                // address is mutable, and this table is a record of identity.
                $table->string('subject', 191);
                $table->string('email', 191)->default('');
                $table->string('reason', 40)->default('jit_disabled');
                $table->string('status', 20)->default('pending');
                $table->timestamp('attempted_at');
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(array('tenant_id', 'subject'));
                $table->index(array('tenant_id', 'status'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nvoos_jit_provisioning_requests');
        Schema::dropIfExists('nvoos_estate_domains');
    }
};
