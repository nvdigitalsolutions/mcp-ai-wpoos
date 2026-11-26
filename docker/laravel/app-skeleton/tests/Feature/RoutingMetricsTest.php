<?php

namespace Tests\Feature;

use App\Models\ModelConfig;
use App\Models\User;
use App\Models\Tenant;
use App\Support\Diagnostics\RoutingMetricStore;
use App\Support\Token\TiktokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Tests\TestCase;

/**
 * 149 §71 — fallbacks stop being invisible.
 *
 * A fallback succeeds silently: the caller gets an answer and nothing records
 * that a different model produced it. These tests pin the two halves of the
 * fix — the write (one row per attempt, from the router, without touching
 * routing) and the read (the diagnostics page reports the count and the
 * tokenizer mode the pre-flight check is using).
 */
class RoutingMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_model_fallback_is_recorded_and_reported(): void
    {
        $this->row('primary-model', 'openai', ['fallback_model_id' => 'backup-model']);
        $this->row('backup-model', 'openai');

        $router = app(ProviderRouter::class);
        $router->register($this->recordingClient('openai', [
            ['error' => ['code' => 'rate_limited', 'message' => '429', 'data' => []]],
            ['ok' => 'backup-answer'],
        ]));

        $result = $router->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['provider' => 'openai', 'model' => 'primary-model']
        );

        $this->assertSame('backup-answer', $result['ok']);

        $this->assertDatabaseHas('nvoos_routing_metrics', [
            'provider' => 'openai',
            'from_model' => 'primary-model',
            'to_model' => 'backup-model',
            'succeeded' => true,
        ]);

        $summary = app(RoutingMetricStore::class)->summary(30);
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['succeeded']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame('primary-model', $summary['pairs'][0]['from']);

        $this->actingAs($this->operatorMember())
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('Routing (30d)')
            ->assertSee('Model fallbacks')
            ->assertSee('primary-model')
            ->assertSee('backup-model');
    }

    public function test_a_failed_fallback_is_recorded_as_failed(): void
    {
        $this->row('primary-model', 'openai', ['fallback_model_id' => 'backup-model']);
        $this->row('backup-model', 'openai');

        $router = app(ProviderRouter::class);
        $router->register($this->recordingClient('openai', [
            ['error' => ['code' => 'rate_limited', 'message' => '429', 'data' => []]],
            ['error' => ['code' => 'rate_limited', 'message' => '429', 'data' => []]],
        ]));

        $router->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['provider' => 'openai', 'model' => 'primary-model']
        );

        $this->assertDatabaseHas('nvoos_routing_metrics', [
            'from_model' => 'primary-model',
            'succeeded' => false,
        ]);
        $this->assertSame(1, app(RoutingMetricStore::class)->summary(30)['failed']);
    }

    public function test_the_page_reports_none_recorded_and_the_tokenizer_mode(): void
    {
        $this->actingAs($this->operatorMember())
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('Routing (30d)')
            ->assertSee('none recorded')
            // The optional tiktoken package is not installed in this tree, so
            // the pre-flight check is on the heuristic and says so.
            ->assertSee('heuristic (chars/4)');
    }

    public function test_the_budget_uses_the_bound_tokenizer(): void
    {
        $budget = app(TokenBudgetManager::class);

        $this->assertInstanceOf(TiktokenService::class, $budget->getTokenizer());

        // The fake-free assertion that matters: empty text is zero either way,
        // and a known string is counted, not guessed to zero.
        $this->assertSame(0, $budget->estimateTokens(''));
        $this->assertGreaterThan(0, $budget->estimateTokens('a short sentence'));
    }

    public function test_a_provider_hop_is_recorded_with_its_source(): void
    {
        $router = app(ProviderRouter::class);

        $router->register($this->recordingClient('openai', [
            ['error' => ['code' => 'api_error', 'message' => 'down', 'data' => []]],
        ]));
        $router->register($this->recordingClient('gemini', [
            ['ok' => 'gemini-answer'],
        ]));

        $router->setFallbackChain(['gemini']);

        $result = $router->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['provider' => 'openai', 'model' => 'gpt-5']
        );

        $this->assertSame('gemini-answer', $result['ok']);

        // §72: a plain provider hop is annotated and reported.
        $this->assertSame('provider_fallback', $result['_nvoos_routing']['via']);
        $this->assertSame('gemini', $result['_nvoos_routing']['provider']);
        $this->assertSame('openai', $result['_nvoos_routing']['requested_provider']);

        $this->assertDatabaseHas('nvoos_routing_metrics', [
            'kind' => 'provider_hop',
            'from_provider' => 'openai',
            'provider' => 'gemini',
            'succeeded' => true,
        ]);

        $summary = app(RoutingMetricStore::class)->summary(30);
        $this->assertSame(1, $summary['provider_hops']);
        $this->assertSame(0, $summary['model_fallbacks']);
        $this->assertSame('openai', $summary['hops'][0]['from']);
        $this->assertSame('gemini', $summary['hops'][0]['to']);
    }

    /**
     * Insert a catalogue row with sensible defaults.
     *
     * @param array<string, mixed> $attributes Overrides.
     */
    private function row(string $modelId, string $provider, array $attributes = []): ModelConfig
    {
        return ModelConfig::query()->create(array_merge([
            'model_id' => $modelId,
            'name' => $modelId,
            'provider' => $provider,
            'status' => 'active',
        ], $attributes));
    }

    private function operatorMember(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }

    /**
     * A provider client that records the model it was asked for.
     *
     * @param array<int, mixed> $responses Queued responses.
     */
    private function recordingClient(string $slug, array $responses): AbstractProviderClient
    {
        return new class ($slug, $responses, app(SettingsStoreInterface::class), app(HttpClientInterface::class), app(ErrorFactoryInterface::class)) extends AbstractProviderClient {
            /**
             * Models requested, in call order.
             *
             * @var list<string|null>
             */
            public array $models = [];

            /**
             * @param array<int, mixed> $responses Queued responses.
             */
            public function __construct(
                string $slug,
                private array $responses,
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                ErrorFactoryInterface $errors,
            ) {
                parent::__construct($settings, $http, $errors);
                $this->providerSlug = $slug;
            }

            public function chat(array $messages, array $options = []): mixed
            {
                $this->models[] = isset($options['model']) ? (string) $options['model'] : null;

                return array_shift($this->responses) ?? ['ok' => true];
            }

            public function stream(array $messages, array $options = [], ?callable $onChunk = null): mixed
            {
                return $this->chat($messages, $options);
            }

            public function listModels(): array
            {
                return [];
            }

            public function getDefaultBaseUrl(): string
            {
                return '';
            }
        };
    }
}
