<?php

namespace Tests\Feature;

use App\Http\Middleware\SetTenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Proposal 149 T-0.4 — the two panels exist, and only the right people enter.
 *
 * The policy itself is covered by AdminPanelAccessTest. What this file adds is
 * that the panels are actually registered, that `User::canAccessPanel()`
 * delegates to the policy rather than re-implementing it, and that the
 * customer panel cannot quietly gain a resource before tenancy lands.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_panels_are_registered_with_the_expected_ids_and_paths(): void
    {
        $operator = Filament::getPanel('operator');
        $admin = Filament::getPanel('admin');

        $this->assertSame('ops', $operator->getPath());
        $this->assertSame('admin', $admin->getPath());
    }

    /**
     * The brand icon, on both panels.
     *
     * Two assertions because they fail differently: a panel without
     * `favicon()` falls back to the browser's `/favicon.ico` probe (a 404 on
     * this runtime), and a panel pointing at a missing file renders a broken
     * `<link rel="icon">` — which is what a copy into a second app produces
     * when the asset is not shipped with it.
     */
    public function test_both_panels_serve_the_brand_icon(): void
    {
        $this->assertFileExists(
            public_path('nvoos-icon.svg'),
            'The brand icon must ship in public/ — panel `favicon()` points at it.'
        );

        foreach (array('admin', 'operator') as $id) {
            $this->assertSame(
                '/nvoos-icon.svg',
                Filament::getPanel($id)->getFavicon(),
                "Panel '{$id}' does not point at the brand icon."
            );
        }
    }

    /**
     * The customer panel is the product surface; `/ops` must never be the
     * landing page for a fresh login.
     */
    public function test_the_customer_panel_is_the_default(): void
    {
        $this->assertSame('admin', Filament::getDefaultPanel()->getId());
    }

    /**
     * `canAccessPanel()` must delegate to the policy, not restate it. If these
     * two ever disagree, the panel gate and the tests protecting it diverge —
     * and the tests would keep passing while the panel let the wrong people in.
     *
     * Model: `/admin` is membership-based for ANY estate, including the
     * operator's own (the proposal's rule is "tenant member with >= manager").
     * `/ops` is the extra privilege on top. So an operator reaches both, and a
     * customer reaches only `/admin` — never the other way round.
     */
    public function test_can_access_panel_delegates_to_the_policy(): void
    {
        $staff = User::factory()->admin()->create();
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $customer = User::factory()->admin()->create();
        $this->join($customer, Tenant::DEFAULT_ID, 'owner');

        // Operator: both panels (their estate + the vendor panel).
        $this->assertTrue($staff->canAccessPanel(Filament::getPanel('operator')));
        $this->assertTrue($staff->canAccessPanel(Filament::getPanel('admin')));

        // The Q-1 trap, exercised through Filament's own contract this time:
        // an estate administrator is not a vendor operator.
        $this->assertFalse($customer->canAccessPanel(Filament::getPanel('operator')));
        $this->assertTrue($customer->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_operator_status_is_what_separates_the_two_panels(): void
    {
        // Same site role, same membership role, different estate.
        $operator = User::factory()->admin()->create();
        $this->join($operator, Tenant::OPERATOR_ID, 'owner');

        $customer = User::factory()->admin()->create();
        $this->join($customer, Tenant::DEFAULT_ID, 'owner');

        $this->assertNotSame(
            $operator->canAccessPanel(Filament::getPanel('operator')),
            $customer->canAccessPanel(Filament::getPanel('operator')),
            'Operator panel access must differ on the estate flag alone, never on users.role.'
        );
    }

    public function test_an_unknown_panel_id_is_refused(): void
    {
        $staff = User::factory()->admin()->create();
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $this->assertFalse(app(\App\Policies\AdminAccessPolicy::class)->accessPanel($staff, 'unknown-panel'));
    }

    public function test_a_guest_is_redirected_to_the_operator_login(): void
    {
        $this->get('/ops')->assertRedirect('/ops/login');
    }

    public function test_a_customer_administrator_is_forbidden_from_the_operator_panel(): void
    {
        $customer = User::factory()->admin()->create();
        $this->join($customer, Tenant::DEFAULT_ID, 'owner');

        $this->actingAs($customer)->get('/ops')->assertForbidden();
    }

    public function test_an_operator_reaches_the_operator_panel(): void
    {
        $staff = User::factory()->admin()->create();
        $this->join($staff, Tenant::OPERATOR_ID, 'owner');

        $this->actingAs($staff)->get('/ops')->assertOk();
    }

    public function test_a_customer_manager_reaches_the_customer_panel(): void
    {
        $manager = User::factory()->create();
        $this->join($manager, Tenant::DEFAULT_ID, 'manager');

        // Tenancy is on (T-0.7), so /admin resolves an estate and redirects in.
        $this->actingAs($manager)
            ->get('/admin')
            ->assertRedirect('/admin/'.Tenant::DEFAULT_ID);

        $this->actingAs($manager)
            ->get('/admin/'.Tenant::DEFAULT_ID)
            ->assertOk();
    }

    public function test_a_viewer_is_forbidden_from_the_customer_panel(): void
    {
        $viewer = User::factory()->create();
        $this->join($viewer, Tenant::DEFAULT_ID, 'viewer');

        $this->actingAs($viewer)->get('/admin')->assertForbidden();
    }

    /**
     * T-0.7 — the customer panel is tenant-scoped, the operator panel is not.
     *
     * `ownershipRelationship: 'tenant'` is load-bearing: Filament resolves it to
     * `whereBelongsTo($tenant, 'tenant')` on each resource model. With the
     * default (plural `tenants`) it would look for a belongsToMany and match
     * nothing — every list would be silently empty rather than leaking, but
     * empty is still wrong.
     */
    public function test_only_the_customer_panel_is_tenant_scoped(): void
    {
        $admin = Filament::getPanel('admin');

        $this->assertTrue($admin->hasTenancy());
        $this->assertSame(Tenant::class, $admin->getTenantModel());
        $this->assertSame('slug', $admin->getTenantSlugAttribute());
        $this->assertContains(SetTenantContext::class, $admin->getTenantMiddleware());

        $this->assertFalse(
            Filament::getPanel('operator')->hasTenancy(),
            'The operator panel must see every estate; scoping it would hide the others.'
        );
    }

    /**
     * A resolved tenant is useless to the runtime unless it reaches
     * TenantContext — every assistant/team query reads that, not Filament.
     */
    public function test_the_middleware_pins_the_resolved_estate(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        // Filament's TenantSet event carries the authenticated user, so the
        // request has to look like a real panel request.
        $user = User::factory()->admin()->create();
        $this->join($user, Tenant::DEFAULT_ID, 'owner');
        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        (new SetTenantContext)->handle(
            Request::create('/admin/'.Tenant::DEFAULT_ID),
            static fn () => response('ok')
        );

        $this->assertSame(Tenant::DEFAULT_ID, TenantContext::current());
    }

    public function test_a_customer_cannot_enter_an_estate_they_do_not_belong_to(): void
    {
        $customer = User::factory()->create();
        $this->join($customer, Tenant::DEFAULT_ID, 'owner');

        $this->actingAs($customer)
            ->get('/admin/'.Tenant::OPERATOR_ID)
            ->assertNotFound();
    }

    public function test_get_tenants_lists_only_estates_the_user_can_act_in(): void
    {
        $user = User::factory()->admin()->create();
        $this->join($user, Tenant::DEFAULT_ID, 'manager');
        $this->join($user, Tenant::OPERATOR_ID, 'viewer');

        $ids = $user->getTenants(Filament::getPanel('admin'))->pluck('id')->all();

        $this->assertSame(array(Tenant::DEFAULT_ID), $ids);
    }

    /**
     * Fails closed on `viewer` because there is no read-only enforcement inside
     * Filament resources yet — admitting a viewer would hand them write
     * actions. Relax to plain membership when per-action checks land.
     */
    public function test_can_access_tenant_is_membership_scoped_and_fails_closed_on_viewer(): void
    {
        $user = User::factory()->admin()->create();
        $this->join($user, Tenant::DEFAULT_ID, 'viewer');

        $this->assertFalse($user->canAccessTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID)));
        $this->assertFalse($user->canAccessTenant(Tenant::query()->findOrFail(Tenant::OPERATOR_ID)));
    }

    public function test_a_manager_may_activate_their_own_estate(): void
    {
        $user = User::factory()->admin()->create();
        $this->join($user, Tenant::DEFAULT_ID, 'manager');

        $this->assertTrue($user->canAccessTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID)));
        $this->assertFalse($user->canAccessTenant(Tenant::query()->findOrFail(Tenant::OPERATOR_ID)));
    }

    /**
     * Filament scopes a resource with `whereBelongsTo($tenant, 'tenant')`, so
     * every tenant-scoped model must declare that relation with a `tenant_id`
     * foreign key. Without it the first resource built on the model throws —
     * late, inside a controller — instead of failing here.
     */
    public function test_tenant_scoped_models_declare_the_ownership_relationship(): void
    {
        $models = array(\App\Models\Assistant::class, \App\Models\Team::class);

        foreach ($models as $model) {
            $instance = new $model;

            $this->assertTrue(
                method_exists($instance, 'tenant'),
                $model.' must declare tenant() — the panel scopes by ownershipRelationship "tenant".'
            );

            $relation = $instance->tenant();

            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relation);
            $this->assertSame('tenant_id', $relation->getForeignKeyName());
        }
    }

    private function join(User $user, string $tenantId, string $role): void
    {
        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }
}
