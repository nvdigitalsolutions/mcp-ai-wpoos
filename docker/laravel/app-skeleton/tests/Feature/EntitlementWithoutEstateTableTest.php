<?php

namespace Tests\Feature;

use App\Support\TenantEntitlement;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Tests\TestCase;

/**
 * Proposal 149 T-0.8 — entitlement must be answerable BEFORE migrations run.
 *
 * This class deliberately does NOT use `RefreshDatabase`. That is the point:
 * `/api/v1/features` reports the plan on every request, and several capability
 * suites run without a database, so a hard `tenants` lookup made the endpoint
 * 500 with "no such table: tenants" the moment per-estate resolution landed.
 *
 * A missing estate table is not an error — it means this installation has no
 * per-estate licensing yet, so the install-level driver answers.
 */
class EntitlementWithoutEstateTableTest extends TestCase
{
    public function test_the_contract_still_resolves(): void
    {
        $this->assertInstanceOf(TenantEntitlement::class, app(ProEntitlementInterface::class));
    }

    public function test_it_falls_back_to_the_install_driver(): void
    {
        config(array('oos.pro_entitlement.driver' => 'allow'));
        app()->forgetScopedInstances();

        $this->assertTrue(app(ProEntitlementInterface::class)->allows('records'));
    }

    public function test_the_install_deny_driver_still_fails_closed(): void
    {
        config(array('oos.pro_entitlement.driver' => 'deny'));
        app()->forgetScopedInstances();

        $this->assertFalse(app(ProEntitlementInterface::class)->allows('records'));
    }

    /**
     * The regression this class exists for: the features manifest reads
     * `plan()`, which used to require the estate table.
     */
    public function test_the_features_endpoint_is_not_broken_by_a_missing_table(): void
    {
        config(array('oos.pro_entitlement.driver' => 'allow'));
        app()->forgetScopedInstances();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
