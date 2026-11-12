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

    public function test_session_nonce_returns_a_token(): void
    {
        $this->getJson('/api/v1/session/nonce')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['nonce']);
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
