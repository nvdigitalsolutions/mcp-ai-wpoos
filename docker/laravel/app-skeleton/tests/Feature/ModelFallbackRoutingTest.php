<?php

namespace Tests\Feature;

use App\Models\ModelConfig;
use App\Support\Models\ModelCatalogueLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Tests\TestCase;

/**
 * 149 §69 — the model catalogue wired into routing.
 *
 * Two layers are pinned separately because they fail differently:
 *
 *  - the **adapter** decides whether a row permits a retry at all (unknown,
 *    unservable, self-referential and dangling fallbacks all say no);
 *  - the **router** decides what to do with a yes, and must keep its old
 *    behaviour when the catalogue says nothing.
 *
 * The end-to-end test uses the real singleton, so a missing `setModelCatalogue()`
 * in boot fails here rather than in production.
 */
class ModelFallbackRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_router_retries_with_the_catalogue_fallback(): void
    {
        $this->row('primary-model', 'openai', ['fallback_model_id' => 'backup-model']);
        $this->row('backup-model', 'openai');

        $router = app(ProviderRouter::class);

        $this->assertInstanceOf(
            ModelCatalogueLookup::class,
            $router->getModelCatalogue(),
            'AppServiceProvider must attach the catalogue to the router singleton.'
        );

        $client = $this->recordingClient('openai', array(
            array( 'error' => array( 'code' => 'rate_limited', 'message' => '429', 'data' => array() ) ),
            array( 'ok' => 'backup-answer' ),
        ));

        $router->register($client);

        $result = $router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'provider' => 'openai', 'model' => 'primary-model' )
        );

        $this->assertSame(array( 'ok' => 'backup-answer' ), array_diff_key($result, array( '_nvoos_routing' => true )));
        $this->assertSame(array( 'provider' => 'openai', 'model' => 'backup-model' ), $result['_nvoos_routing']);
        $this->assertSame(array( 'primary-model', 'backup-model' ), $client->models);
    }

    public function test_the_adapter_refuses_unservable_dangling_and_self_fallbacks(): void
    {
        $fallbacks = new ModelCatalogueLookup();

        // Servable target: allowed.
        $this->row('primary-model', 'openai', array( 'fallback_model_id' => 'backup-model' ));
        $this->row('backup-model', 'openai');
        $this->assertSame('backup-model', $fallbacks->fallbackFor('openai', 'primary-model'));

        // Retired target: refused, because it only turns one error into another.
        $this->row('retired-model', 'openai', array( 'status' => 'retired' ));
        $this->row('to-retired', 'openai', array( 'fallback_model_id' => 'retired-model' ));
        $this->assertNull($fallbacks->fallbackFor('openai', 'to-retired'));

        // A fallback id the catalogue does not hold: refused.
        $this->row('dangling', 'openai', array( 'fallback_model_id' => 'never-heard-of-it' ));
        $this->assertNull($fallbacks->fallbackFor('openai', 'dangling'));

        // A row pointing at itself: refused, or the router would loop.
        $this->row('self-ref', 'openai', array( 'fallback_model_id' => 'self-ref' ));
        $this->assertNull($fallbacks->fallbackFor('openai', 'self-ref'));

        // Unknown pair and empty inputs: refused.
        $this->assertNull($fallbacks->fallbackFor('openai', 'no-such-model'));
        $this->assertNull($fallbacks->fallbackFor('', 'backup-model'));
        $this->assertNull($fallbacks->fallbackFor('openai', ''));
    }

    public function test_a_model_id_published_by_two_providers_resolves_within_its_own(): void
    {
        // The §68 duplicate: same id, two providers, only one servable target.
        $this->row('shared-id', 'azure', array( 'status' => 'active' ));
        $this->row('shared-id', 'openai', array( 'status' => 'retired' ));

        $this->row('azure-primary', 'azure', array( 'fallback_model_id' => 'shared-id' ));
        $this->row('openai-primary', 'openai', array( 'fallback_model_id' => 'shared-id' ));

        $fallbacks = new ModelCatalogueLookup();

        $this->assertSame('shared-id', $fallbacks->fallbackFor('azure', 'azure-primary'));

        // The OpenAI row resolves to its own provider's retired row, so the
        // adapter refuses rather than borrowing Azure's active one.
        $this->assertNull($fallbacks->fallbackFor('openai', 'openai-primary'));
    }

    public function test_the_router_slug_for_nvidia_resolves_to_the_catalogue_provider(): void
    {
        $this->row('nvidia-primary', 'nvidia', array( 'fallback_model_id' => 'nvidia-backup' ));
        $this->row('nvidia-backup', 'nvidia');

        // `NvidiaNimClient` reports `nvidia_nim`; the catalogue stores `nvidia`.
        $fallbacks = new ModelCatalogueLookup();

        $this->assertSame('nvidia-backup', $fallbacks->fallbackFor('nvidia_nim', 'nvidia-primary'));
        $this->assertSame('nvidia-backup', $fallbacks->fallbackFor('nvidia', 'nvidia-primary'));
    }

    public function test_the_adapter_answers_which_provider_serves_a_model(): void
    {
        // Known id, served here: true.
        $this->row('served-here', 'openai');
        $this->assertTrue(( new ModelCatalogueLookup() )->serves('openai', 'served-here'));

        // Known id, not served here: false, which is what drops a chain hop's
        // model override.
        $this->assertFalse(( new ModelCatalogueLookup() )->serves('gemini', 'served-here'));

        // Known id whose only row is retired: false.
        $this->row('old-model', 'openai', array( 'status' => 'retired' ));
        $this->assertFalse(( new ModelCatalogueLookup() )->serves('openai', 'old-model'));

        // Unknown id: null (no opinion), never false.
        $this->assertNull(( new ModelCatalogueLookup() )->serves('openai', 'house-deployment'));

        // The router vocabulary still resolves.
        $this->row('nim-model', 'nvidia');
        $this->assertTrue(( new ModelCatalogueLookup() )->serves('nvidia_nim', 'nim-model'));
    }

    public function test_the_adapter_supplies_context_and_output_limits(): void
    {
        $this->row('wide-model', 'openai', array(
            'context_window' => 400000,
            'max_output_tokens' => 32000,
        ));

        $lookup = new ModelCatalogueLookup();

        $this->assertSame(400000, $lookup->contextWindow('wide-model'));
        $this->assertSame(32000, $lookup->maxOutputTokens('wide-model'));

        // Case-insensitive, matching the budget manager's lookup.
        $this->assertSame(400000, $lookup->contextWindow('Wide-Model'));

        // Unknown and zero-valued rows fall through to the snapshot.
        $this->assertNull($lookup->contextWindow('no-such-model'));
        $this->row('unlimited-model', 'openai');
        $this->assertNull($lookup->contextWindow('unlimited-model'));

        // A retired row is not a limit source.
        $this->row('retired-wide', 'openai', array(
            'context_window' => 900000,
            'status' => 'retired',
        ));
        $this->assertNull($lookup->contextWindow('retired-wide'));
    }

    public function test_the_provider_clients_share_one_catalogue_backed_budget(): void
    {
        $this->row('wide-model', 'openai', array(
            'context_window' => 400000,
            'max_output_tokens' => 32000,
        ));

        $budget = app(TokenBudgetManager::class);

        $this->assertInstanceOf(
            ModelLimitCatalogueInterface::class,
            $budget->getCatalogue(),
            'The package provider must attach the platform catalogue to the budget manager.'
        );
        $this->assertSame(400000, $budget->getModelLimit('wide-model'));

        // Every registered client validates against that one manager, which is
        // what turns pre-flight context-window checks on.
        $client = app(ProviderRouter::class)->get('openai');

        $this->assertNotNull($client);
        $this->assertSame($budget, $client->getTokenBudgetManager());
    }

    /**
     * Insert a catalogue row with sensible defaults.
     *
     * @param string               $modelId Model id.
     * @param string               $provider Provider slug.
     * @param array<string, mixed> $attributes Overrides.
     */
    private function row(string $modelId, string $provider, array $attributes = array()): ModelConfig
    {
        return ModelConfig::query()->create(array_merge(array(
            'model_id' => $modelId,
            'name' => $modelId,
            'provider' => $provider,
            'status' => 'active',
        ), $attributes));
    }

    /**
     * A provider client that records the model it was asked for.
     *
     * @param string        $slug Provider slug.
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
            public array $models = array();

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

            public function chat(array $messages, array $options = array()): mixed
            {
                $this->models[] = isset($options['model']) ? (string) $options['model'] : null;

                return \array_shift($this->responses) ?? array( 'ok' => true );
            }

            public function stream(array $messages, array $options = array(), ?callable $onChunk = null): mixed
            {
                return $this->chat($messages, $options);
            }

            public function listModels(): array
            {
                return array();
            }

            public function getDefaultBaseUrl(): string
            {
                return '';
            }
        };
    }
}
