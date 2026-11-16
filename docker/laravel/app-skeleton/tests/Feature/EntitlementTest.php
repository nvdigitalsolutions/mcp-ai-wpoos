<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Pro\ToolkitManifestLoader;
use App\Support\TenantContext;
use App\Support\TenantEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Service\Entitlement\ProPlanCatalog;
use Tests\TestCase;

/**
 * Proposal 149 T-0.8 — entitlement that means something.
 *
 * Three defects motivated this file, all invisible under the `allow` dev
 * driver:
 *
 *   1. The contract was a SINGLETON, so the first resolved instance was pinned
 *      for an Octane worker's life. Per-estate entitlement cannot work on top
 *      of that.
 *   2. The vocabulary disagreed with itself: route middleware gated on
 *      `schedules`/`records` and the toolkit manifests declared `commerce`/
 *      `documents`, but none of the four existed in `ProPlanCatalog`. With a
 *      real licence, `allows()` returned false for every plan — a licensed
 *      install denied its own Pro surface.
 *   3. Nothing resolved a licence per estate, so every customer on a shared
 *      deployment shared the host's entitlement.
 */
class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    // ── Wiring ─────────────────────────────────────────────────────────────

    public function test_the_contract_resolves_to_the_tenant_aware_decorator(): void
    {
        $this->assertInstanceOf(TenantEntitlement::class, app(ProEntitlementInterface::class));
    }

    /**
     * Octane flushes scoped bindings between operations via
     * `FlushTemporaryContainerInstances` → `forgetScopedInstances()`. A
     * singleton survives that flush, which is how one estate's entitlement
     * would leak to every later request on the worker.
     */
    public function test_the_binding_is_scoped_so_it_is_flushed_between_operations(): void
    {
        $first = app(ProEntitlementInterface::class);

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(ProEntitlementInterface::class));
    }

    // ── Resolution ─────────────────────────────────────────────────────────

    public function test_the_install_driver_is_the_fallback_for_an_estate_without_a_licence(): void
    {
        $this->setDriver('allow');

        $this->assertTrue(app(ProEntitlementInterface::class)->allows('records'));
    }

    public function test_the_deny_driver_fails_closed(): void
    {
        $this->setDriver('deny');

        $this->assertFalse(app(ProEntitlementInterface::class)->allows('records'));
    }

    /**
     * The estate's own key governs it, even when the host default is
     * permissive. No licence server is configured here, so the licensed path
     * must fail closed — proving the estate key overrode `allow` rather than
     * being ignored.
     */
    public function test_an_estate_licence_overrides_a_permissive_install_driver(): void
    {
        $this->setDriver('allow');
        $this->giveEstateALicence('PRO-TEST-KEY');

        $entitlement = app(ProEntitlementInterface::class);

        $this->assertSame('license', $entitlement->source());
        $this->assertFalse(
            $entitlement->allows('records'),
            'An estate with a licence must be governed by it, not by the permissive host default.'
        );
    }

    /**
     * Entitlement follows the pinned estate, not the process.
     */
    public function test_switching_estate_switches_the_entitlement(): void
    {
        $this->setDriver('allow');

        Tenant::query()->create(array(
            'id' => 'licensed-estate',
            'name' => 'Licensed estate',
            'slug' => 'licensed-estate',
            'is_operator' => false,
            'licence_key' => 'PRO-TEST-KEY',
        ));

        $entitlement = app(ProEntitlementInterface::class);

        TenantContext::set(Tenant::DEFAULT_ID);
        $this->assertSame('allow', $entitlement->source());
        $this->assertTrue($entitlement->allows('records'));

        TenantContext::set('licensed-estate');
        $this->assertSame('license', $entitlement->source());
        $this->assertFalse($entitlement->allows('records'));
    }

    // ── Vocabulary completeness (the durable gate) ─────────────────────────

    /**
     * Every `pro.entitlement:<feature>` on a live route must be a feature some
     * plan actually entitles. This is the guard that would have caught the
     * `schedules`/`records` outage at review time instead of at a customer's
     * first licensed request.
     */
    public function test_every_route_gated_feature_exists_in_the_plan_catalogue(): void
    {
        $features = array();

        foreach (Route::getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'pro.entitlement:')) {
                    $features[] = substr($middleware, strlen('pro.entitlement:'));
                }
            }
        }

        $this->assertNotEmpty(
            $features,
            'No pro.entitlement middleware was found on any route — this gate is not looking at real routes.'
        );

        foreach (array_unique($features) as $feature) {
            $this->assertContains(
                $feature,
                ProPlanCatalog::features('compliance'),
                "Route middleware gates on '{$feature}', which the base plan does not entitle — "
                .'a licensed install would answer 402 for that whole surface.'
            );
        }
    }

    /**
     * Same rule for the toolkit manifests: `feature` is the declared
     * entitlement hook, so it must name a real plan feature.
     */
    public function test_every_manifest_feature_exists_in_the_plan_catalogue(): void
    {
        $manifests = app(ToolkitManifestLoader::class)->all();

        $this->assertNotEmpty($manifests, 'No toolkit manifests loaded — the gate would pass vacuously.');

        foreach ($manifests as $slug => $manifest) {
            $this->assertContains(
                $manifest->feature(),
                ProPlanCatalog::features('compliance'),
                "Toolkit '{$slug}' declares feature '{$manifest->feature()}', which the base plan does not entitle."
            );
        }
    }

    // ── Route-level behaviour ──────────────────────────────────────────────

    public function test_a_pro_route_is_refused_under_the_deny_driver(): void
    {
        $this->setDriver('deny');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/pro/records/schema')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'pro_entitlement_required')
            ->assertJsonPath('error.feature', 'records');
    }

    public function test_a_pro_route_is_served_under_the_allow_driver(): void
    {
        $this->setDriver('allow');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/pro/records/schema')->assertOk();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Change the install-level driver and drop anything memoised under the old
     * configuration (both the scoped contract and the pinned tenant).
     */
    private function setDriver(string $driver): void
    {
        config(array(
            'oos.pro_entitlement.driver' => $driver,
            'oos.pro_entitlement.server_url' => '',
            'oos.pro_entitlement.license_key' => '',
            'oos.pro_entitlement.features' => array(),
        ));

        app()->forgetScopedInstances();
        TenantContext::forget();
    }

    private function giveEstateALicence(string $key): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->licence_key = $key;
        $tenant->save();
    }
}
