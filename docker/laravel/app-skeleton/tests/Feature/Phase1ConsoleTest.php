<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Jobs\NvoosToolJob;
use Tests\TestCase;

class Phase1ConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The console is a stateful SPA. Sanctum only starts the session when
        // the request looks first-party, so the session tests must look that
        // way too. CSRF validation is skipped under `runningUnitTests()`.
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_settings_schema_and_masked_values(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        app(SettingsStoreInterface::class)->set('openai_api_key', 'sk-secret');

        $this->getJson('/api/v1/settings/schema')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['tabs', 'fields']]);

        $this->getJson('/api/v1/settings/values')
            ->assertOk()
            ->assertJsonPath('data.values.openai_api_key', '**************')
            ->assertJsonPath('data.values.default_provider', 'openai');
    }

    public function test_settings_update_ignores_masked_secrets(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $store = app(SettingsStoreInterface::class);
        $store->set('openai_api_key', 'sk-secret');

        $this->postJson('/api/v1/settings/values', [
            'openai_api_key' => '**************',
            'default_model' => 'gpt-4.1-mini',
            'temperature' => 0.2,
        ])
            ->assertOk()
            ->assertJsonPath('data.values.default_model', 'gpt-4.1-mini');

        $this->assertSame('sk-secret', $store->get('openai_api_key'));
    }

    public function test_settings_update_rejects_an_unknown_provider(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/settings/values', ['default_provider' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_diagnostics_overview_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/diagnostics/overview')
            ->assertOk()
            ->assertJsonPath('data.rest_context.backend', 'laravel')
            ->assertJsonStructure([
                'data' => [
                    'health' => ['status'],
                    'system' => ['php_version'],
                    'providers',
                    'mcp_server' => ['tool_count'],
                    'queue' => ['available', 'counts'],
                ],
            ]);
    }

    public function test_dlq_lists_and_dismisses_failed_jobs(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $jobs = app(JobStatusStore::class);
        $jobs->queue('job-1', 'Tests\\Handler', ['assistant_id' => 4]);
        $jobs->markFailed('job-1', new \RuntimeException('boom'));

        $this->getJson('/api/v1/operations/dlq')
            ->assertOk()
            ->assertJsonPath('data.items.0.item_id', 'job-1')
            ->assertJsonPath('data.items.0.failure_reason', 'boom');

        $this->postJson('/api/v1/operations/dlq/job-1/dismiss')
            ->assertOk()
            ->assertJsonPath('data.dismissed', true);

        $this->assertSame('cancelled', $jobs->get('job-1')->status);
    }

    public function test_dlq_retry_requeues_the_job(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->admin()->create());
        $jobs = app(JobStatusStore::class);
        $jobs->queue('job-2', 'Tests\\Handler', ['assistant_id' => 4]);
        $jobs->markFailed('job-2', new \RuntimeException('boom'));

        $this->postJson('/api/v1/operations/dlq/job-2/retry')
            ->assertOk()
            ->assertJsonPath('data.retried', true);

        Queue::assertPushed(NvoosToolJob::class);
        $this->assertSame('queued', $jobs->get('job-2')->status);
    }

    public function test_timeline_lists_completed_jobs(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $jobs = app(JobStatusStore::class);
        $jobs->queue('job-3', 'Tests\\Handler', ['assistant_id' => 7]);
        $jobs->markRunning('job-3');
        $jobs->markCompleted('job-3', ['total_tokens' => 120, 'cost_usd' => 0.01]);

        $this->getJson('/api/v1/operations/timeline')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.runs.0.assistant_id', 7)
            ->assertJsonPath('data.runs.0.total_tokens', 120);
    }

    public function test_laravel_login_issues_a_session(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => bcrypt('secret'),
        ]);

        $nonce = $this->getJson('/api/v1/session/nonce')->json('nonce');

        $this->withHeader('X-CSRF-TOKEN', $nonce)
            ->postJson('/api/v1/session/login', [
                'email' => 'owner@example.com',
                'password' => 'secret',
            ])
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('login', 'owner@example.com');
    }

    /**
     * The Phase 0–4 `X-WP-Nonce` translation shim is gone (Phase 5.2).
     */
    /**
     * The Phase 0-4 `AcceptWpNonce` shim translated `X-WP-Nonce` into the
     * session token. It is gone, so the header must now be inert: Laravel only
     * reads `_token`, `X-CSRF-TOKEN` and `X-XSRF-TOKEN`.
     *
     * `VerifyCsrfToken::runningUnitTests()` short-circuits validation whenever
     * the app reports the `testing` environment, so this test flips the env for
     * the duration of the request to exercise the real production path.
     */
    public function test_the_wordpress_nonce_header_is_no_longer_a_csrf_token(): void
    {
        $nonce = $this->getJson('/api/v1/session/nonce')->json('nonce');

        $this->app['env'] = 'local';

        $this->withHeader('X-WP-Nonce', $nonce)
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/v1/session/login', [
                'email' => 'owner@example.com',
                'password' => 'secret',
            ])
            ->assertStatus(419);
    }

    /**
     * Counterpart to the test above: the header the console now sends is
     * accepted by the same production CSRF path.
     */
    public function test_the_console_csrf_header_is_accepted(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => bcrypt('secret'),
        ]);

        $nonce = $this->getJson('/api/v1/session/nonce')->json('nonce');

        $this->app['env'] = 'local';

        $this->withHeader('X-CSRF-TOKEN', $nonce)
            ->withHeader('Origin', 'http://localhost')
            ->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/v1/session/login', [
                'email' => 'owner@example.com',
                'password' => 'secret',
            ])
            ->assertOk();
    }

    public function test_laravel_login_rejects_bad_credentials(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => bcrypt('secret'),
        ]);

        $nonce = $this->getJson('/api/v1/session/nonce')->json('nonce');

        $this->withHeader('X-CSRF-TOKEN', $nonce)
            ->postJson('/api/v1/session/login', [
                'email' => 'owner@example.com',
                'password' => 'wrong',
            ])
            ->assertStatus(401);
    }
}
