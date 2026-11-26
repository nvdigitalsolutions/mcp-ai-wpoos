<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 T-0.5 — panel access, and the Q-1 trap.
 *
 * The policy under test is the only thing standing between a customer and the
 * vendor panel. The first test is the reason Q-1 was settled as a tenant flag
 * rather than a site role.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private AdminAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AdminAccessPolicy;
    }

    /**
     * THE REGRESSION. This user is the administrator of their own estate — the
     * strongest customer there is. Deriving `/ops` from `users.role` would let
     * them reach licence writes, DLQ drain and key rotation.
     */
    public function test_a_customer_estate_administrator_cannot_reach_the_operator_panel(): void
    {
        $customer = User::factory()->admin()->create();
        $this->join($customer, Tenant::DEFAULT_ID, 'owner');

        $this->assertTrue(
            $this->policy->accessTenantPanel($customer),
            'A customer estate owner should still get their own panel.'
        );

        $this->assertFalse(
            $this->policy->accessOperatorPanel($customer),
            'Operator status is a tenant flag, not a site role — a customer administrator must never reach /ops.'
        );
    }

    public function test_an_operator_member_with_manage_options_reaches_the_operator_panel(): void
    {
        $staff = User::factory()->admin()->create();
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $this->assertTrue($this->policy->accessOperatorPanel($staff));
    }

    /**
     * Membership alone is not enough for the vendor panel: it performs
     * platform-wide credential writes.
     */
    public function test_operator_membership_without_manage_options_is_refused(): void
    {
        $staff = User::factory()->create(); // subscriber, so no manage_options
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $this->assertFalse($this->policy->accessOperatorPanel($staff));
    }

    public function test_a_viewer_is_refused_the_customer_panel(): void
    {
        $viewer = User::factory()->create();
        $this->join($viewer, Tenant::DEFAULT_ID, 'viewer');

        $this->assertFalse($this->policy->accessTenantPanel($viewer));
    }

    public function test_a_manager_reaches_the_customer_panel(): void
    {
        $manager = User::factory()->create();
        $this->join($manager, Tenant::DEFAULT_ID, 'manager');

        $this->assertTrue($this->policy->accessTenantPanel($manager));
    }

    public function test_a_user_with_no_membership_reaches_nothing(): void
    {
        // `withoutEstate()` is the one state production cannot reach through the
        // migration, so it is requested explicitly rather than being the factory
        // default (149 §21).
        $nobody = User::factory()->admin()->withoutEstate()->create();

        $this->assertFalse($this->policy->accessTenantPanel($nobody));
        $this->assertFalse($this->policy->accessOperatorPanel($nobody));
    }

    /**
     * A panel id that is not in the policy must never be granted by default —
     * adding a third panel later should fail closed until it is registered.
     */
    public function test_an_unknown_panel_fails_closed(): void
    {
        $staff = User::factory()->admin()->create();
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $this->assertTrue($this->policy->accessPanel($staff, AdminAccessPolicy::PANEL_OPERATOR));
        $this->assertFalse($this->policy->accessPanel($staff, 'root'));
    }

    public function test_the_panel_ids_are_the_ones_the_routes_will_use(): void
    {
        $this->assertSame('operator', AdminAccessPolicy::PANEL_OPERATOR);
        $this->assertSame('admin', AdminAccessPolicy::PANEL_TENANT);
    }

    private function join(User $user, string $tenantId, string $role): void
    {
        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }
}
