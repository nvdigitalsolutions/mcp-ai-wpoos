<?php

/**
 * Tests for model-level fallback routing (Proposal 149 §69).
 *
 * The router asks the catalogue for a servable sibling model before it gives
 * up on a provider, so a single rate-limited or withdrawn model does not cost
 * a provider hop. These tests pin the ordering and every "do nothing" path,
 * because the dangerous failure mode here is a router that retries when it
 * should not — a loop, a duplicate call, or a silently changed request.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Provider;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ModelRoutingCatalogueInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use PHPUnit\Framework\TestCase;

final class ProviderRouterModelFallbackTest extends TestCase
{
    /**
     * Router under test.
     *
     * @var ProviderRouter
     */
    private ProviderRouter $router;

    /**
     * Provider client fakes, keyed by slug.
     *
     * @var array<string, AbstractProviderClient>
     */
    private array $clients = array();

    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createSettings();
        $errors   = $this->createErrors();
        $router   = new ProviderRouter($settings, $errors);

        foreach (array( 'openai', 'gemini' ) as $slug) {
            $client = $this->createClient($slug, $settings, $errors);
            $router->register($client);
            $this->clients[ $slug ] = $client;
        }

        $this->router = $router;
    }

    public function test_a_failing_model_retries_the_catalogue_fallback_on_the_same_provider(): void
    {
        $this->clients['openai']->responses = array(
            $this->errorResult(),
            array( 'ok' => 'fallback-answer' ),
        );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5-mini' )));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' )
        );

        $this->assertSame(array( 'ok' => 'fallback-answer' ), array_diff_key($result, array( '_nvoos_routing' => true )));
        $this->assertSame(
            array( 'provider' => 'openai', 'model' => 'gpt-5-mini' ),
            $result['_nvoos_routing']
        );
        $this->assertCount(2, $this->clients['openai']->calls);
        $this->assertSame('gpt-5-mini', $this->clients['openai']->calls[1]['options']['model']);
        $this->assertCount(0, $this->clients['gemini']->calls);
    }

    public function test_a_failed_model_fallback_continues_to_the_provider_chain(): void
    {
        $this->clients['openai']->responses = array(
            $this->errorResult(),
            $this->errorResult(),
        );
        $this->clients['gemini']->responses = array( array( 'ok' => 'gemini-answer' ) );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5-mini' )));
        $this->router->setFallbackChain(array( 'gemini' ));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' )
        );

        $this->assertSame('gemini-answer', $result['ok']);
        $this->assertArrayNotHasKey('_nvoos_routing', $result, 'A hop that keeps the model has nothing to report.');
        $this->assertCount(2, $this->clients['openai']->calls);
        $this->assertCount(1, $this->clients['gemini']->calls);
    }

    public function test_without_an_explicit_model_no_model_retry_is_attempted(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );
        $this->clients['gemini']->responses = array( array( 'ok' => 'gemini-answer' ) );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5-mini' )));
        $this->router->setFallbackChain(array( 'gemini' ));

        $result = $this->router->chat(array( array( 'role' => 'user', 'content' => 'hi' ) ));

        $this->assertSame('gemini-answer', $result['ok']);
        $this->assertCount(1, $this->clients['openai']->calls);
    }

    public function test_the_assistant_config_model_is_used_when_options_omit_it(): void
    {
        $this->clients['openai']->responses = array(
            $this->errorResult(),
            array( 'ok' => 'fallback-answer' ),
        );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5-mini' )));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array(),
            array( 'model' => 'gpt-5', 'provider' => 'openai' )
        );

        $this->assertSame(array( 'ok' => 'fallback-answer' ), array_diff_key($result, array( '_nvoos_routing' => true )));
        $this->assertSame('gpt-5-mini', $this->clients['openai']->calls[1]['options']['model']);
    }

    public function test_a_catalogue_that_names_the_same_model_does_not_loop(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5' )));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' )
        );

        $this->assertSame($this->errorResult(), $result);
        $this->assertCount(1, $this->clients['openai']->calls);
    }

    public function test_without_a_catalogue_the_router_is_unchanged(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' )
        );

        $this->assertSame($this->errorResult(), $result);
        $this->assertCount(1, $this->clients['openai']->calls);
        $this->assertNull($this->router->getModelCatalogue());
    }

    public function test_streaming_uses_the_model_fallback(): void
    {
        $this->clients['openai']->responses = array(
            $this->errorResult(),
            array( 'ok' => 'fallback-stream' ),
        );

        $this->router->setModelCatalogue($this->catalogue(array( 'gpt-5' => 'gpt-5-mini' )));

        $result = $this->router->stream(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' ),
            array(),
            static function (): void {
            }
        );

        $this->assertSame(array( 'ok' => 'fallback-stream' ), array_diff_key($result, array( '_nvoos_routing' => true )));
        $this->assertCount(2, $this->clients['openai']->calls);
        $this->assertTrue($this->clients['openai']->calls[1]['stream']);
        $this->assertSame('gpt-5-mini', $this->clients['openai']->calls[1]['options']['model']);
    }

    public function test_a_chain_hop_drops_a_model_the_target_provider_does_not_serve(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );
        $this->clients['gemini']->responses = array( array( 'ok' => 'gemini-answer' ) );

        $this->router->setModelCatalogue($this->catalogue(
            array(),
            array(
                'openai' => array( 'gpt-5' ),
                'gemini' => array( 'gemini-3.8-flash' ),
            )
        ));
        $this->router->setFallbackChain(array( 'gemini' ));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gpt-5' )
        );

        $this->assertSame('gemini-answer', $result['ok']);
        $this->assertArrayNotHasKey(
            'model',
            $this->clients['gemini']->calls[0]['options'],
            'A model the target does not publish must not be forwarded to it.'
        );
        $this->assertArrayNotHasKey('model', $result['_nvoos_routing']);
    }

    public function test_a_chain_hop_keeps_a_model_the_catalogue_has_no_opinion_about(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );
        $this->clients['gemini']->responses = array( array( 'ok' => 'gemini-answer' ) );

        // The catalogue knows nothing about `house-deployment` on either side.
        $this->router->setModelCatalogue($this->catalogue(
            array(),
            array( 'gemini' => array( 'gemini-3.8-flash' ) )
        ));
        $this->router->setFallbackChain(array( 'gemini' ));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'house-deployment' )
        );

        $this->assertSame('gemini-answer', $result['ok']);
        $this->assertSame('house-deployment', $this->clients['gemini']->calls[0]['options']['model']);
    }

    public function test_a_chain_hop_keeps_a_model_the_target_does_serve(): void
    {
        $this->clients['openai']->responses = array( $this->errorResult() );
        $this->clients['gemini']->responses = array( array( 'ok' => 'gemini-answer' ) );

        $this->router->setModelCatalogue($this->catalogue(
            array(),
            array(
                'openai' => array( 'gemini-3.8-flash' ),
                'gemini' => array( 'gemini-3.8-flash' ),
            )
        ));
        $this->router->setFallbackChain(array( 'gemini' ));

        $result = $this->router->chat(
            array( array( 'role' => 'user', 'content' => 'hi' ) ),
            array( 'model' => 'gemini-3.8-flash' )
        );

        $this->assertSame('gemini-answer', $result['ok']);
        $this->assertSame('gemini-3.8-flash', $this->clients['gemini']->calls[0]['options']['model']);
    }

    /**
     * Build a catalogue fake from a model => fallback map.
     *
     * `serves()` answers from an optional pair map: provider => [model ids].
     * A model absent from every list is "unknown" (null), which is what the
     * router must treat as "keep the request as it is".
     *
     * @param array<string, string>       $map   Fallback map.
     * @param array<string, list<string>> $served Served ids per provider.
     */
    private function catalogue(array $map, array $served = array()): ModelRoutingCatalogueInterface
    {
        return new class ($map, $served) implements ModelRoutingCatalogueInterface {
            /**
             * @param array<string, string>       $map    Fallback map.
             * @param array<string, list<string>> $served Served ids per provider.
             */
            public function __construct(
                private readonly array $map,
                private readonly array $served,
            ) {
            }

            public function fallbackFor(string $provider, string $modelId): ?string
            {
                return $this->map[ $modelId ] ?? null;
            }

            public function serves(string $provider, string $modelId): ?bool
            {
                foreach ($this->served as $ids) {
                    if (\in_array($modelId, $ids, true)) {
                        return \in_array($modelId, $this->served[ $provider ] ?? array(), true);
                    }
                }

                return null;
            }
        };
    }

    private function createSettings(): SettingsStoreInterface
    {
        return new class () implements SettingsStoreInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return array();
            }

            public function set(string $key, mixed $value): void
            {
            }

            public function delete(string $key): void
            {
            }

            public function getDefaultProvider(): string
            {
                return 'openai';
            }

            public function getDefaultModel(): string
            {
                return 'gpt-5';
            }

            public function getApiKey(string $provider): ?string
            {
                return 'test-key';
            }

            public function getApiBaseUrl(string $provider): ?string
            {
                return null;
            }

            public function getRequestTimeout(): int
            {
                return 30;
            }

            public function isEnabled(string $feature): bool
            {
                return true;
            }
        };
    }

    private function createErrors(): ErrorFactoryInterface
    {
        return new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array( 'error' => array( 'code' => $code, 'message' => $message, 'data' => $data ) );
            }

            public function isError(mixed $value): bool
            {
                return \is_array($value) && isset($value['error']);
            }

            public function normalize(mixed $error): array
            {
                return \is_array($error) && isset($error['error']) ? $error['error'] : array( 'code' => 'unknown', 'message' => '', 'data' => array() );
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, array( 'errors' => $errors ));
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ));
            }
        };
    }

    private function createClient(string $slug, SettingsStoreInterface $settings, ErrorFactoryInterface $errors): AbstractProviderClient
    {
        return new class ($slug, $settings, $errors) extends AbstractProviderClient {
            /**
             * Recorded chat/stream calls.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $calls = array();

            /**
             * Queue of responses; each call shifts one.
             *
             * @var array<int, mixed>
             */
            public array $responses = array();

            public function __construct(string $slug, SettingsStoreInterface $settings, ErrorFactoryInterface $errors)
            {
                parent::__construct($settings, $this->fakeHttp(), $errors);
                $this->providerSlug = $slug;
            }

            private function fakeHttp(): HttpClientInterface
            {
                return new class () implements HttpClientInterface {
                    public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
                    {
                        return new HttpResponse(200, '{}');
                    }
                };
            }

            public function chat(array $messages, array $options = array()): mixed
            {
                $this->calls[] = array( 'slug' => $this->providerSlug, 'options' => $options );
                return \array_shift($this->responses) ?? array( 'ok' => true );
            }

            public function stream(array $messages, array $options = array(), ?callable $onChunk = null): mixed
            {
                $this->calls[] = array( 'slug' => $this->providerSlug, 'options' => $options, 'stream' => true );
                return \array_shift($this->responses) ?? array( 'ok' => true );
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

    /**
     * @return array<string, mixed>
     */
    private function errorResult(): array
    {
        return array( 'error' => array( 'code' => 'api_error', 'message' => 'boom', 'data' => array() ) );
    }
}
