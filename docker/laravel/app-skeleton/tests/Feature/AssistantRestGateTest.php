<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePrincipalScope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The three `rest_enable_assistant_*` keys, honoured for machine principals —
 * Proposal 159 slice 2 (§5 rule 5).
 *
 * These keys existed and rendered on `/admin/settings` while nothing read them;
 * `UnimplementedSettings` named the obstacle as "a first-party vs token-call
 * split". Slice 2 supplies that split: a scoped token is a machine principal, a
 * session/Auth0 user/`['*']` token is the owner's own authority.
 *
 * The probes declare `scope:assistant` as well as the setting, because the
 * default-deny guard from 159 §10 runs first on every `api` route — a probe with
 * no scope declaration would be refused by that guard with a different error
 * code, and the test would be measuring the wrong refusal.
 */
final class AssistantRestGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $declared = array('api', 'auth:sanctum', 'scope:assistant');

        Route::middleware(array_merge($declared, array('assistant.rest:list')))
            ->get('/api/v1/_probe/rest-list', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array_merge($declared, array('assistant.rest:create')))
            ->post('/api/v1/_probe/rest-create', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array_merge($declared, array('assistant.rest:delete')))
            ->delete('/api/v1/_probe/rest-delete', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array_merge($declared, array('assistant.rest:nonsense')))
            ->get('/api/v1/_probe/rest-nonsense', static fn () => response()->json(array('ok' => true)));
    }

    // ── WordPress's defaults, which is the point of honouring the keys ──────

    public function test_listing_is_enabled_by_default(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->getJson('/api/v1/_probe/rest-list')
            ->assertOk();
    }

    public function test_listing_can_be_turned_off_for_a_machine_principal(): void
    {
        $this->set(array('rest_enable_assistant_list' => false));

        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->getJson('/api/v1/_probe/rest-list')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'assistant_rest_disabled')
            ->assertJsonPath('error.action', 'list')
            ->assertJsonPath('error.setting', 'rest_enable_assistant_list');
    }

    public function test_create_is_off_by_default(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->postJson('/api/v1/_probe/rest-create')
            ->assertForbidden()
            ->assertJsonPath('error.action', 'create')
            ->assertJsonPath('error.setting', 'rest_enable_assistant_create');
    }

    public function test_delete_is_off_by_default(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->deleteJson('/api/v1/_probe/rest-delete')
            ->assertForbidden()
            ->assertJsonPath('error.action', 'delete')
            ->assertJsonPath('error.setting', 'rest_enable_assistant_delete');
    }

    public function test_the_gate_opens_when_the_estate_enables_it(): void
    {
        $this->set(array(
            'rest_enable_assistant_create' => true,
            'rest_enable_assistant_delete' => true,
        ));

        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->postJson('/api/v1/_probe/rest-create')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->deleteJson('/api/v1/_probe/rest-delete')
            ->assertOk();
    }

    // ── First-party callers are not gated (159 §5 rule 5) ──────────────────

    public function test_a_star_token_is_not_gated(): void
    {
        $this->set(array('rest_enable_assistant_list' => false));

        // A `['*']` personal token is the owner's own authority replayed, not a
        // separate principal — 159 §5 rule 3.
        $this->withHeader('Authorization', 'Bearer '.$this->star())
            ->getJson('/api/v1/_probe/rest-list')
            ->assertOk();
    }

    public function test_a_console_session_is_not_gated(): void
    {
        $this->set(array('rest_enable_assistant_create' => false));

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/_probe/rest-create')->assertOk();
    }

    public function test_an_unknown_action_denies(): void
    {
        // An authoring mistake fails closed, the same rule the scope middleware
        // applies to a blank declaration.
        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->getJson('/api/v1/_probe/rest-nonsense')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'assistant_rest_disabled')
            ->assertJsonPath('error.action', 'nonsense')
            ->assertJsonPath('error.setting', null);
    }

    // ── Wiring: the real routes carry what the tests above assume ──────────

    public function test_the_real_assistant_routes_declare_their_setting_and_scope(): void
    {
        // Both halves are load-bearing: the scope says which slice of the estate
        // a machine may touch, the setting says whether the estate allows remote
        // management at all. A route with only one of them is either unreachable
        // or ungated.
        $this->assertRouteDeclares('api/v1/assistants', 'GET', 'assistant.rest:list');
        $this->assertRouteDeclares('api/v1/assistants', 'GET', 'scope:estate:assistants.list');

        $this->assertRouteDeclares('api/v1/assistants', 'POST', 'assistant.rest:create');
        $this->assertRouteDeclares('api/v1/assistants', 'POST', 'scope:estate:assistants.create');

        $this->assertRouteDeclares('api/v1/assistants/{assistant}', 'DELETE', 'assistant.rest:delete');
        $this->assertRouteDeclares('api/v1/assistants/{assistant}', 'DELETE', 'scope:estate:assistants.delete');
    }

    public function test_a_properly_scoped_token_reaches_the_real_catalogue_route(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->scoped(array('estate:assistants.list')))
            ->getJson('/api/v1/assistants')
            ->assertOk();
    }

    public function test_an_assistant_bound_token_does_not_reach_the_catalogue(): void
    {
        // 159 §6 rule 7: an assistant scope implies its estate, not a permission
        // inside it. Reading the catalogue is an estate permission, and it has to
        // be granted as one.
        $this->withHeader('Authorization', 'Bearer '.$this->scoped())
            ->getJson('/api/v1/assistants')
            ->assertForbidden()
            ->assertJsonPath('error.required.0', 'estate:assistants.list');
    }

    public function test_the_session_route_declares_the_escape_hatch(): void
    {
        $this->assertRouteDeclares('api/v1/session/me', 'GET', 'scope:any');
    }

    public function test_the_api_group_carries_the_scope_guard(): void
    {
        // Coverage, not a spot check: the guard is appended once to the group the
        // whole API surface already uses, so a new route is protected by default.
        $groups = app(Kernel::class)->getMiddlewareGroups();

        $this->assertContains(EnsurePrincipalScope::class, $groups['api']);
    }

    /**
     * @param list<string> $abilities
     */
    private function scoped(array $abilities = array('assistant:42')): string
    {
        return User::factory()->admin()->create()->createToken('probe', $abilities)->plainTextToken;
    }

    private function star(): string
    {
        return $this->scoped(array('*'));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function set(array $changes): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $settings = app(SettingsService::class);
        $applied = $settings->write($changes, SettingsService::SURFACE_ADMIN);

        foreach ($changes as $key => $value) {
            $this->assertContains($key, $applied, sprintf('Setting [%s] was not writable on the ops surface.', $key));
            $this->assertSame(
                $value,
                $settings->value($key),
                sprintf('Setting [%s] did not read back after writing.', $key)
            );
        }
    }

    private function assertRouteDeclares(string $uri, string $method, string $declaration): void
    {
        $found = null;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                $found = $route;
                break;
            }
        }

        $this->assertNotNull($found, sprintf('Route [%s %s] is not registered.', $method, $uri));
        $this->assertContains(
            $declaration,
            $found->gatherMiddleware(),
            sprintf('Route [%s %s] does not declare [%s].', $method, $uri, $declaration)
        );
    }
}
