<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\PrincipalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The scope vocabulary and the `scope:` middleware — Proposal 159 slice 1.
 *
 * Two properties carry the weight, and the first is the one that protects
 * production:
 *
 *  - **`['*']` is unrestricted.** Every personal token minted before this slice
 *    carries it, and a session or Auth0 request carries no Sanctum token at all.
 *    If the middleware narrowed those, slice 2 would lock out every existing
 *    caller the moment a route gained a scope. Pinned here, before any route uses
 *    the middleware.
 *  - **Scope matching is exact, or prefix — never substring.** `assistant:4` must
 *    not be satisfied by `assistant:42`; that is the same shape of mistake the JIT
 *    allowlist refuses for domains (149 §116.2).
 *
 * The HTTP cases use **real bearer tokens**, not `Sanctum::actingAs()`: the point
 * of the middleware is what a *stored* `abilities` list means, and a mocked token
 * would test the mock.
 */
final class PrincipalScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes, registered per test. Three declarations: one that asks
        // for a class of scope, one for a different class, and one that is
        // malformed on purpose.
        Route::middleware(array('auth:sanctum', 'scope:assistant'))
            ->get('/api/v1/_probe/assistant', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array('auth:sanctum', 'scope:estate'))
            ->get('/api/v1/_probe/estate', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array('auth:sanctum', 'scope:'))
            ->get('/api/v1/_probe/blank', static fn () => response()->json(array('ok' => true)));

        // The default-deny cases have to travel through the `api` group, because
        // that is where the guard is attached — a route-level probe would only
        // exercise the declarative path a second time.
        Route::middleware(array('api', 'auth:sanctum'))
            ->get('/api/v1/_probe/unannotated', static fn () => response()->json(array('ok' => true)));

        Route::middleware(array('api', 'auth:sanctum', 'scope:any'))
            ->get('/api/v1/_probe/any', static fn () => response()->json(array('ok' => true)));
    }

    // ── The vocabulary ───────────────────────────────────────────────────────

    public function test_an_absent_or_star_ability_list_is_unrestricted(): void
    {
        $this->assertTrue(PrincipalScope::fromAbilities(null)->isUnrestricted());
        $this->assertTrue(PrincipalScope::fromAbilities('nonsense')->isUnrestricted());
        $this->assertTrue(PrincipalScope::fromAbilities(array('*'))->isUnrestricted());
    }

    public function test_an_empty_ability_list_is_restricted_to_nothing(): void
    {
        // `[]` on a real token is a deliberate "this credential gets nothing",
        // which is the opposite of `null`/absent. Both must not read as "full".
        $scope = PrincipalScope::fromAbilities(array());

        $this->assertFalse($scope->isUnrestricted());
        $this->assertFalse($scope->allows('assistant'));
        $this->assertNull($scope->assistantId());
    }

    public function test_an_assistant_scope_binds_one_assistant(): void
    {
        $scope = PrincipalScope::fromAbilities(array('assistant:42'));

        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame(42, $scope->assistantId());

        $this->assertTrue($scope->allows('assistant'));
        $this->assertTrue($scope->allows('assistant:42'));

        // Exact, not substring: the 4-in-42 trap.
        $this->assertFalse($scope->allows('assistant:4'));
        $this->assertFalse($scope->allows('assistant:43'));
        $this->assertFalse($scope->allows('estate'));
    }

    public function test_an_estate_scope_is_a_class_not_an_assistant(): void
    {
        $scope = PrincipalScope::fromAbilities(array('estate:assistants.list', 'estate:tools.read'));

        $this->assertTrue($scope->allows('estate'));
        $this->assertTrue($scope->allows('estate:assistants.list'));
        $this->assertFalse($scope->allows('estate:assistants.create'));
        $this->assertFalse($scope->allows('assistant'));
        $this->assertNull($scope->assistantId());
    }

    public function test_a_malformed_assistant_scope_binds_no_assistant(): void
    {
        $scope = PrincipalScope::fromAbilities(array('assistant:abc', 'assistant:0', 'assistant:'));

        $this->assertNull($scope->assistantId());

        // A class requirement needs a usable id, so none of these satisfy it —
        // otherwise the route would pass the check and then read a null id.
        $this->assertFalse($scope->allows('assistant'));

        // They are still literal entries, and an exact requirement is a string
        // comparison rather than a grammar check.
        $this->assertTrue($scope->allows('assistant:abc'));
        $this->assertTrue($scope->allows('assistant:0'));
    }

    // ── The middleware, over a real bearer token ────────────────────────────

    public function test_a_scoped_token_passes_a_matching_route(): void
    {
        $token = $this->bearer(array('assistant:42'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/assistant')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_a_scoped_token_is_refused_a_different_class_of_route(): void
    {
        $token = $this->bearer(array('assistant:42'));

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/estate');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required')
            ->assertJsonPath('error.required.0', 'estate')
            ->assertJsonPath('error.granted.0', 'assistant:42');
    }

    public function test_a_legacy_star_token_passes_every_scoped_route(): void
    {
        // THE compatibility rule. Every token minted before slice 1 carries `*`,
        // and a session or Auth0 user has no Sanctum token at all. Both keep the
        // owner's authority; only a *narrowed* credential is constrained.
        $token = $this->bearer(array('*'));

        foreach (array('assistant', 'estate') as $probe) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson('/api/v1/_probe/'.$probe)
                ->assertOk();
        }
    }

    public function test_a_session_principal_passes_every_scoped_route(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (array('assistant', 'estate') as $probe) {
            $this->getJson('/api/v1/_probe/'.$probe)->assertOk();
        }
    }

    public function test_a_blank_requirement_denies_a_scoped_token(): void
    {
        // Fail closed: `scope:` with no argument is an authoring mistake, and the
        // only safe reading of a blank requirement is "deny" — otherwise a missed
        // parameter silently opens the route.
        $token = $this->bearer(array('assistant:42'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/blank')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required');
    }

    public function test_an_unauthenticated_request_never_reaches_the_scope_check(): void
    {
        $this->getJson('/api/v1/_probe/assistant')->assertUnauthorized();
    }

    // ── Default-deny (159 §10) ──────────────────────────────────────────────

    public function test_a_scoped_token_is_refused_an_unannotated_route(): void
    {
        $token = $this->bearer(array('assistant:42'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/unannotated')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required')
            ->assertJsonPath('error.required', array())
            ->assertJsonPath('error.granted.0', 'assistant:42');
    }

    public function test_the_scope_check_runs_after_authentication(): void
    {
        // THE ordering proof. `PrincipalScope::forRequest()` reads a null user as
        // unrestricted, so a guard that ran before `auth:sanctum` would see no
        // principal, allow the request, and it would come back 200. The api-group
        // attach carries no arguments of its own, so a 403 here can only come
        // from the guard having observed the authenticated token.
        $token = $this->bearer(array('assistant:42'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/unannotated')
            ->assertForbidden();
    }

    public function test_a_missing_credential_is_a_401_not_a_403(): void
    {
        $this->getJson('/api/v1/_probe/unannotated')->assertUnauthorized();
    }

    public function test_a_legacy_star_token_passes_an_unannotated_route(): void
    {
        $token = $this->bearer(array('*'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/unannotated')
            ->assertOk();
    }

    public function test_a_session_passes_an_unannotated_route(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/_probe/unannotated')->assertOk();
    }

    public function test_any_is_a_vocabulary_entry_not_a_wildcard(): void
    {
        $this->assertTrue(PrincipalScope::fromAbilities(array('estate:tools.read'))->allows('any'));
        $this->assertTrue(PrincipalScope::fromAbilities(array('assistant:42'))->allows('any'));

        // `any` admits a credential that carries a scope; `[]` carries none.
        $this->assertFalse(PrincipalScope::fromAbilities(array())->allows('any'));
    }

    public function test_any_admits_a_scoped_credential_on_a_declared_route(): void
    {
        $token = $this->bearer(array('assistant:42'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/any')
            ->assertOk();
    }

    public function test_any_still_refuses_a_credential_given_nothing(): void
    {
        $token = $this->bearer(array());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/_probe/any')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required');
    }

    /**
     * A real bearer token carrying the given abilities.
     *
     * @param list<string> $abilities
     */
    private function bearer(array $abilities): string
    {
        $user = User::factory()->admin()->create();

        return $user->createToken('probe', $abilities)->plainTextToken;
    }
}
