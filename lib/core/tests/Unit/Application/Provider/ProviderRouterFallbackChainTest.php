<?php
/**
 * Tests for ProviderRouter ordered fallback chains (Proposal 091, Track D).
 *
 * @package Nvoos\Core\Tests
 * @since   1.2.13
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Provider;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use PHPUnit\Framework\TestCase;

final class ProviderRouterFallbackChainTest extends TestCase {

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

	protected function setUp(): void {
		parent::setUp();

		$settings = $this->createSettings();
		$errors   = $this->createErrors();
		$router   = new ProviderRouter( $settings, $errors );

		foreach ( array( 'openai', 'gemini', 'anthropic' ) as $slug ) {
			$client = $this->createClient( $slug, $settings, $errors );
			$router->register( $client );
			$this->clients[ $slug ] = $client;
		}

		$this->router = $router;
	}

	/**
	 * Chain order is respected: primary fails, the first healthy entry wins.
	 *
	 * @return void
	 */
	public function test_chain_order_respected_on_failure(): void {
		$this->clients['openai']->responses   = array( $this->errorResult() );
		$this->clients['gemini']->responses   = array( array( 'ok' => 'gemini-answer' ) );
		$this->clients['anthropic']->responses = array( array( 'ok' => 'anthropic-answer' ) );

		$this->router->setFallbackChain( array( 'gemini', 'anthropic' ) );

		$result = $this->router->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( array( 'ok' => 'gemini-answer' ), $result );
		$this->assertCount( 1, $this->clients['gemini']->calls );
		$this->assertCount( 0, $this->clients['anthropic']->calls );
	}

	/**
	 * Cooled-down providers are skipped in favor of the next entry.
	 *
	 * @return void
	 */
	public function test_chain_skips_cooled_down_provider(): void {
		$this->clients['openai']->responses    = array( $this->errorResult() );
		$this->clients['gemini']->responses    = array( array( 'ok' => 'gemini-answer' ) );
		$this->clients['anthropic']->responses = array( array( 'ok' => 'anthropic-answer' ) );

		$this->router->setFallbackChain( array( 'gemini', 'anthropic' ) );
		$this->router->coolDown( 'gemini', 300 );

		$result = $this->router->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( array( 'ok' => 'anthropic-answer' ), $result );
		$this->assertCount( 0, $this->clients['gemini']->calls );
	}

	/**
	 * Failed candidates (and the failed primary) get cooled down; Retry-After
	 * values from the error payload are honored.
	 *
	 * @return void
	 */
	public function test_failed_candidates_are_cooled_down_with_retry_after(): void {
		$this->clients['openai']->responses    = array( $this->errorResult() );
		$this->clients['gemini']->responses    = array( $this->errorResult( 300 ) );
		$this->clients['anthropic']->responses = array( array( 'ok' => 'anthropic-answer' ) );

		$this->router->setFallbackChain( array( 'gemini', 'anthropic' ) );

		$result = $this->router->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( array( 'ok' => 'anthropic-answer' ), $result );
		$this->assertTrue( $this->router->isCooledDown( 'openai' ) );
		$this->assertTrue( $this->router->isCooledDown( 'gemini' ) );
		$this->assertFalse( $this->router->isCooledDown( 'anthropic' ) );
	}

	/**
	 * Without a chain, the legacy health-based failover path is untouched.
	 *
	 * @return void
	 */
	public function test_no_chain_falls_back_to_legacy_behavior(): void {
		$this->clients['openai']->responses = array( $this->errorResult() );

		$result = $this->router->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( $this->errorResult(), $result );
		$this->assertCount( 0, $this->clients['gemini']->calls );
	}

	/**
	 * An assistant-level chain overrides the router-level chain.
	 *
	 * @return void
	 */
	public function test_assistant_chain_wins_over_router_chain(): void {
		$this->clients['openai']->responses    = array( $this->errorResult() );
		$this->clients['gemini']->responses    = array( array( 'ok' => 'gemini-answer' ) );
		$this->clients['anthropic']->responses = array( array( 'ok' => 'anthropic-answer' ) );

		$this->router->setFallbackChain( array( 'gemini', 'anthropic' ) );

		$result = $this->router->chat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'fallback_chain' => array( 'anthropic' ) )
		);

		$this->assertSame( array( 'ok' => 'anthropic-answer' ), $result );
		$this->assertCount( 0, $this->clients['gemini']->calls );
	}

	/**
	 * setFallbackChain normalizes aliases and de-duplicates entries.
	 *
	 * @return void
	 */
	public function test_set_fallback_chain_normalizes_and_dedupes(): void {
		$this->router->setFallbackChain( array( 'google', 'google', 'claude' ) );

		$this->assertSame( array( 'gemini', 'anthropic' ), $this->router->getFallbackChain() );
	}

	// ─── Fakes ─────────────────────────────────────────────────────────

	private function createSettings(): SettingsStoreInterface {
		return new class() implements SettingsStoreInterface {
			public function get( string $key, mixed $default = null ): mixed {
				return $default;
			}

			public function all(): array {
				return array();
			}

			public function set( string $key, mixed $value ): void {}

			public function delete( string $key ): void {}

			public function getDefaultProvider(): string {
				return 'openai';
			}

			public function getDefaultModel(): string {
				return 'openai-default';
			}

			public function getApiKey( string $provider ): ?string {
				return 'test-key';
			}

			public function getApiBaseUrl( string $provider ): ?string {
				return null;
			}

			public function getRequestTimeout(): int {
				return 30;
			}

			public function isEnabled( string $feature ): bool {
				return true;
			}
		};
	}

	private function createErrors(): ErrorFactoryInterface {
		return new class() implements ErrorFactoryInterface {
			public function create( string $code, string $message, array $data = array() ): mixed {
				return array( 'error' => array( 'code' => $code, 'message' => $message, 'data' => $data ) );
			}

			public function isError( mixed $value ): bool {
				return \is_array( $value ) && isset( $value['error'] );
			}

			public function normalize( mixed $error ): array {
				return \is_array( $error ) && isset( $error['error'] ) ? $error['error'] : array( 'code' => 'unknown', 'message' => '', 'data' => array() );
			}

			public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
				return $this->create( 'not_found', $message, $data );
			}

			public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
				return $this->create( 'forbidden', $message, $data );
			}

			public function validationFailed( string $message, array $errors = array() ): mixed {
				return $this->create( 'validation_failed', $message, array( 'errors' => $errors ) );
			}

			public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
				return $this->create( 'rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ) );
			}
		};
	}

	/**
	 * Create a recording provider-client fake.
	 *
	 * @param string                 $slug     Provider slug.
	 * @param SettingsStoreInterface $settings Settings contract.
	 * @param ErrorFactoryInterface  $errors   Error factory contract.
	 * @return AbstractProviderClient
	 */
	private function createClient( string $slug, SettingsStoreInterface $settings, ErrorFactoryInterface $errors ): AbstractProviderClient {
		return new class( $slug, $settings, $errors ) extends AbstractProviderClient {

			/**
			 * Recorded chat calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = array();

			/**
			 * Queue of responses; each chat() call shifts one.
			 *
			 * @var array<int, mixed>
			 */
			public array $responses = array();

			public function __construct( string $slug, SettingsStoreInterface $settings, ErrorFactoryInterface $errors ) {
				parent::__construct( $settings, $this->fakeHttp(), $errors );
				$this->providerSlug = $slug;
			}

			private function fakeHttp(): HttpClientInterface {
				return new class() implements HttpClientInterface {
					public function send( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
						return new HttpResponse( 200, '{}' );
					}
				};
			}

			public function chat( array $messages, array $options = array() ): mixed {
				$this->calls[] = array( 'slug' => $this->providerSlug, 'options' => $options );
				return \array_shift( $this->responses ) ?? array( 'ok' => true );
			}

			public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
				$this->calls[] = array( 'slug' => $this->providerSlug, 'options' => $options, 'stream' => true );
				return \array_shift( $this->responses ) ?? array( 'ok' => true );
			}

			public function listModels(): array {
				return array();
			}

			public function getDefaultBaseUrl(): string {
				return '';
			}
		};
	}

	/**
	 * Build a provider error result (optionally carrying a Retry-After).
	 *
	 * @param int|null $retryAfterSeconds Retry-After seconds.
	 * @return array<string, mixed>
	 */
	private function errorResult( ?int $retryAfterSeconds = null ): array {
		$data = null === $retryAfterSeconds ? array() : array( 'retry_after' => $retryAfterSeconds );
		return array( 'error' => array( 'code' => 'api_error', 'message' => 'boom', 'data' => $data ) );
	}
}
