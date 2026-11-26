<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estates (tenants) — Proposal 149 T-0.3.
 *
 * `id` is a 64-char string, not a bigint, because every `tenant_id` column in
 * the schema is already `string(64) default 'default'` (see the teams
 * migration). A bigint here would force a type change across assistants, teams
 * and team_members for no gain.
 *
 * `is_operator` carries the Q-1 decision: operator status is a property of the
 * ESTATE, not of the user. `/ops` access means "member of a tenant where
 * is_operator is true", which is why no `users.is_operator` column exists. The
 * alternative — granting `/ops` from `users.role = 'administrator'` — would let
 * a customer's estate administrator into the vendor's platform panel.
 *
 * `licence_key` / `plan` carry Q-2: entitlement is per estate, so each estate
 * resolves its own licence through the existing ProEntitlementInterface rather
 * than one install-wide licence shared by every customer.
 *
 * Backfill is guarded and idempotent (the repo's migration rule). Existing rows
 * keep resolving because the default tenant keeps the literal id
 * `Assistant::DEFAULT_TENANT` uses, and every existing user is mapped onto it
 * with a role derived from their WordPress site role — a blanket `owner` would
 * hand `/admin` to subscribers.
 */
return new class extends Migration
{
    /**
     * The default estate id. Must stay equal to `Assistant::DEFAULT_TENANT`;
     * `TenantModelTest` asserts the two constants cannot drift.
     */
    private const DEFAULT_TENANT = 'default';

    /** The vendor's own estate. Seeded outside production only. */
    private const OPERATOR_TENANT = 'nvoos';

    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('name');
                $table->string('slug', 64)->unique();
                $table->boolean('is_operator')->default(false);
                $table->string('licence_key', 191)->nullable();
                $table->string('plan', 32)->nullable();
                $table->timestamps();

                // Cross-estate reads start from this flag ("find the operator
                // estate"), so it is indexed even though the table is tiny.
                $table->index('is_operator');
            });
        }

        $now = now();

        DB::table('tenants')->updateOrInsert(
            array('id' => self::DEFAULT_TENANT),
            array(
                'name' => 'Default estate',
                'slug' => self::DEFAULT_TENANT,
                'is_operator' => false,
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        // Outside production the vendor estate exists so `/ops` is reachable
        // locally. Production seeds it deliberately (a deploy must not be able
        // to manufacture an estate that sees every customer).
        if (app()->environment(array('local', 'testing'))) {
            DB::table('tenants')->updateOrInsert(
                array('id' => self::OPERATOR_TENANT),
                array(
                    'name' => 'NV oOS (operator)',
                    'slug' => self::OPERATOR_TENANT,
                    'is_operator' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                )
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
