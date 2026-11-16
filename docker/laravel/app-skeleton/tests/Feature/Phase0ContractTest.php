<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase0ContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_features_advertises_the_laravel_contract(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.backend', 'laravel')
            ->assertJsonPath('data.contract', 'mcp-ai/v1')
            ->assertJsonPath('data.contract_version', 1)
            ->assertJsonPath('data.entitlement.source', 'allow')
            ->assertJsonStructure([
                'data' => [
                    'features',
                    'entitlement' => ['plan', 'source'],
                ],
            ]);
    }

    public function test_site_returns_a_name(): void
    {
        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonStructure(['name']);
    }

    public function test_tools_require_authentication(): void
    {
        $this->getJson('/api/v1/tools')->assertUnauthorized();
    }

    public function test_tools_list_the_registered_registry(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['slug' => 'color_convert']);
    }

    public function test_session_me_returns_the_spa_user_shape(): void
    {
        $user = User::factory()->create(['name' => 'Owner']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/session/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('displayName', 'Owner')
            ->assertJsonStructure(['login', 'capabilities', 'plan', 'source']);
    }

    /**
     * The console is a stateful SPA: Sanctum only starts a session for a
     * first-party origin, and the nonce route refuses to mint a token without
     * one. This request therefore has to look like the console.
     */
    public function test_session_nonce_returns_a_token_to_a_first_party_origin(): void
    {
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);

        $this->withHeader('Referer', 'http://localhost')
            ->getJson('/api/v1/session/nonce')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['nonce']);
    }

    /**
     * A caller Sanctum will not open a session for gets no token: there is no
     * session for the token to belong to, so the route answers 419 rather than
     * handing back a nonce nothing can validate against.
     */
    public function test_session_nonce_rejects_a_caller_without_a_first_party_origin(): void
    {
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);

        $this->withHeader('Referer', 'https://third-party.example')
            ->getJson('/api/v1/session/nonce')
            ->assertStatus(419)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'session_required');
    }

    public function test_ops_overview_returns_the_t0_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/pro/ops/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'tools' => ['total', 'enabled'],
                    'providers',
                    'queue' => ['connection', 'available', 'counts'],
                    'entitlement' => ['plan', 'source'],
                ],
            ]);
    }
}
