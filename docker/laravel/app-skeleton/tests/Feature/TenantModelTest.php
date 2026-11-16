<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proposal 149 T-0.3 — the estate model.
 *
 * The estate is the tenancy unit and it now carries two decisions that used to
 * have no home: operator status (Q-1) and the licence (Q-2).
 */
class TenantModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `Assistant::DEFAULT_TENANT` and `Tenant::DEFAULT_ID` are two constants
     * for one string. If they drift, every query scoped by TenantContext stops
     * matching the estate it claims to belong to — silently, because both are
     * valid-looking strings.
     */
    public function test_the_default_tenant_id_cannot_drift_from_the_assistant_constant(): void
    {
        $this->assertSame(Assistant::DEFAULT_TENANT, Tenant::DEFAULT_ID);
    }

    public function test_migrations_create_the_default_estate(): void
    {
        $this->assertDatabaseHas('tenants', [
            'id' => Tenant::DEFAULT_ID,
            'is_operator' => false,
        ]);
    }

    public function test_the_operator_estate_is_seeded_outside_production_only(): void
    {
        // The suite runs in `testing`, so the vendor estate exists here. A
        // production deploy must not create it: manufacturing an estate that
        // sees every customer is not something a migration should do.
        $this->assertDatabaseHas('tenants', [
            'id' => Tenant::OPERATOR_ID,
            'is_operator' => true,
        ]);

        $this->assertSame(1, Tenant::query()->operators()->count());
    }

    /**
     * A licence key is a credential. Storing it in plaintext would leak it into
     * every database dump and every `toArray()`.
     */
    public function test_the_licence_key_is_encrypted_at_rest(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->licence_key = 'PRO-PLAINTEXT-CANARY';
        $tenant->save();

        $raw = DB::table('tenants')->where('id', $tenant->id)->value('licence_key');

        $this->assertNotSame('PRO-PLAINTEXT-CANARY', $raw);
        $this->assertNotNull($raw);
        $this->assertSame('PRO-PLAINTEXT-CANARY', $tenant->fresh()->licence_key);
    }

    public function test_a_user_can_belong_to_two_estates_with_different_roles(): void
    {
        $user = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->attach($user->id, ['role' => 'viewer']);
        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->attach($user->id, ['role' => 'owner']);

        $this->assertSame(2, $user->tenants()->count());
        $this->assertTrue($user->isOperatorMember());
    }

    /**
     * The backfill is what keeps an existing install usable: without it every
     * pre-existing user has no membership, and the first deploy locks the
     * operator out of the panel they just gained.
     *
     * Re-running the migration's up() exercises the real backfill code on rows
     * the test just created; the table-creation guard makes it idempotent.
     */
    public function test_users_are_backfilled_with_a_role_derived_from_their_site_role(): void
    {
        $administrator = $this->userWithSiteRole('administrator');
        $editor = $this->userWithSiteRole('editor');
        $subscriber = $this->userWithSiteRole('subscriber');

        $this->runMembershipBackfill();

        $this->assertSame('owner', $this->pivotRole($administrator));
        $this->assertSame('manager', $this->pivotRole($editor));
        // Fails closed: a subscriber must not be promoted onto the panel.
        $this->assertSame('viewer', $this->pivotRole($subscriber));
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $administrator = $this->userWithSiteRole('administrator');

        $this->runMembershipBackfill();
        $this->runMembershipBackfill();

        $this->assertSame(
            1,
            DB::table('tenant_user')
                ->where('tenant_id', Tenant::DEFAULT_ID)
                ->where('user_id', $administrator->id)
                ->count()
        );
    }

    private function userWithSiteRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function runMembershipBackfill(): void
    {
        $migration = require database_path('migrations/2026_11_16_100100_create_tenant_user_table.php');
        $migration->up();
    }

    private function pivotRole(User $user): ?string
    {
        return DB::table('tenant_user')
            ->where('tenant_id', Tenant::DEFAULT_ID)
            ->where('user_id', $user->id)
            ->value('role');
    }
}
