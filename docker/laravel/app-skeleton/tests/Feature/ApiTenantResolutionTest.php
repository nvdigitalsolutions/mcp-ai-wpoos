<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveApiTenant;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proposal 149 §21 — estate resolution for the console API.
 *
 * Before this, nothing pinned `TenantContext` outside the Filament panel, so
 * every console request read and wrote the `default` estate. That is two
 * defects in one: a leak for a caller who is not a member of `default`, and a
 * silently-wrong answer for a caller who belongs to several estates.
 *
 * The rule is the OWASP Multi-Tenant Security Cheat Sheet's: a client-supplied
 * tenant identifier is a **selector only**, and the server verifies the
 * authenticated principal may act in the selected tenant. These tests pin the
 * four outcomes that rule produces, plus the route gate that stops a new
 * endpoint from bypassing it.
 */
class ApiTenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_single_membership_resolves_without_a_header(): void
    {
        $user = User::factory()->admin()->create();
        $this->assistantIn(Tenant::DEFAULT_ID, 'Default bot');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/assistants')
            ->assertOk()
            ->assertJsonPath('assistants.0.title', 'Default bot');
    }

    public function test_the_header_selects_an_estate_the_caller_belongs_to(): void
    {
        $this->estate('estate-b');

        $this->assistantIn(Tenant::DEFAULT_ID, 'Default bot');
        $this->assistantIn('estate-b', 'Estate B bot');

        Sanctum::actingAs($this->twoEstateAdmin());

        // A two-estate caller selects explicitly for each estate. Without a
        // header it gets 409, which the ambiguity test pins.
        $default = $this->getJson('/api/v1/assistants', [ResolveApiTenant::HEADER => Tenant::DEFAULT_ID])
            ->assertOk()
            ->json('assistants');

        $this->assertSame(['Default bot'], array_column($default, 'title'));

        $selected = $this->getJson('/api/v1/assistants', [ResolveApiTenant::HEADER => 'estate-b'])
            ->assertOk()
            ->json('assistants');

        $this->assertSame(['Estate B bot'], array_column($selected, 'title'));
    }

    public function test_a_header_naming_an_estate_the_caller_does_not_belong_to_is_a_404(): void
    {
        $this->estate('estate-b');
        $this->assistantIn('estate-b', 'Estate B bot');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/assistants', [ResolveApiTenant::HEADER => 'estate-b'])
            ->assertNotFound()
            ->assertJsonPath('code', 'tenant_not_found');

        // And the same answer for an estate that does not exist, so the two are
        // indistinguishable (R4).
        $this->getJson('/api/v1/assistants', [ResolveApiTenant::HEADER => 'nope'])
            ->assertNotFound()
            ->assertJsonPath('code', 'tenant_not_found');
    }

    public function test_an_ambiguous_caller_must_select_an_estate(): void
    {
        $this->estate('estate-b');

        Sanctum::actingAs($this->twoEstateAdmin());

        $this->getJson('/api/v1/assistants')
            ->assertStatus(409)
            ->assertJsonPath('code', 'tenant_required');

        // The same caller succeeds once it selects.
        $this->getJson('/api/v1/assistants', [ResolveApiTenant::HEADER => 'estate-b'])
            ->assertOk();
    }

    public function test_an_unprovisioned_account_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->admin()->withoutEstate()->create());

        $this->getJson('/api/v1/assistants')
            ->assertForbidden()
            ->assertJsonPath('code', 'tenant_unprovisioned');
    }

    public function test_the_identity_endpoint_needs_no_selection(): void
    {
        $this->estate('estate-b');

        Sanctum::actingAs($this->twoEstateAdmin());

        $payload = $this->getJson('/api/v1/session/me')
            ->assertOk()
            ->json();

        // A multi-estate caller cannot be given a single resolved estate, but it
        // must still be able to learn who it is and what it may select.
        $this->assertNull($payload['tenant']);

        $ids = array_column($payload['estates'], 'id');
        sort($ids);
        $this->assertSame([Tenant::DEFAULT_ID, 'estate-b'], $ids);

        $this->assertSame(
            'estate-b',
            $this->getJson('/api/v1/session/me', [ResolveApiTenant::HEADER => 'estate-b'])
                ->assertOk()
                ->json('tenant')
        );
    }

    public function test_the_resolved_estate_does_not_survive_the_request(): void
    {
        $this->estate('estate-b');
        $this->assistantIn('estate-b', 'Estate B bot');

        Sanctum::actingAs(User::factory()->admin()->inEstate('estate-b', 'owner')->create());

        $this->getJson('/api/v1/assistants')->assertOk();

        $this->assertFalse(
            TenantContext::isPinned(),
            'A long-lived process must not carry one request\'s estate into the next.'
        );
    }

    /**
     * The route gate.
     *
     * OWASP §5 asks for tenant middleware "only on routes where tenant context
     * is required", which makes a missing mount a silent hole rather than a
     * crash. This asserts the inverse: every `api/v1` route except the identity
     * and session endpoints carries the resolver.
     */
    public function test_every_api_route_except_the_allowlist_resolves_an_estate(): void
    {
        $allowlist = [
            'api/v1/features',
            'api/v1/site',
            'api/v1/session/nonce',
            'api/v1/session/login',
            'api/v1/session/logout',
            'api/v1/session/me',
            // A personal token belongs to a person across every estate they can
            // reach, so requiring an estate selection before minting or listing
            // one would be a fiction — and would 409 a multi-estate user out of
            // their own credentials (149 §26).
            'api/v1/personal-tokens',
            'api/v1/personal-tokens/{token}',
        ];

        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            if (in_array($uri, $allowlist, true)) {
                continue;
            }

            if (! in_array(ResolveApiTenant::class, $route->gatherMiddleware(), true)) {
                $unprotected[] = $route->methods()[0].' '.$uri;
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            'Every tenant-scoped API route must resolve an estate. Add ResolveApiTenant::class to the group, or add the route to the allowlist with a reason.'
        );
    }

    private function assistantIn(string $tenantId, string $title): Assistant
    {
        $assistant = Assistant::query()->create([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
        ]);

        // `tenant_id` is deliberately not fillable (a request body must never
        // choose it), so it is assigned after create.
        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['id' => $tenantId],
            ['name' => 'Estate '.$tenantId, 'slug' => $tenantId, 'is_operator' => false]
        );
    }

    /**
     * A site administrator who belongs to `default` and `estate-b`.
     *
     * Built by attaching the second estate rather than by factory state: the
     * `inEstate()` state provisions ONE estate, which is exactly what a
     * single-estate fixture needs and exactly what an ambiguous one must not be.
     */
    private function twoEstateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        $user->tenants()->syncWithoutDetaching([
            'estate-b' => ['role' => 'owner'],
        ]);

        return $user;
    }
}
