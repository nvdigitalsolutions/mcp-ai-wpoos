<?php
/**
 * Tests for CascadeRouter — cheap-tier routing, validation, escalation.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Provider;

use Nvoos\Core\Application\Provider\CascadeRouter;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ComplexityClassifierInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ResponseValidatorInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use PHPUnit\Framework\TestCase;

final class CascadeRouterTest extends TestCase {

	/**
	 * Primary (frontier) provider client fake.
	 *
	 * @var AbstractProviderClient
	 */
	private AbstractProviderClient $primary;

	/**
	 * Cheap tier-1 provider client fake.
	 *
	 * @var AbstractProviderClient
	 */
	private AbstractProviderClient $cheap;

	private ProviderRouter $router;

	protected function setUp(): void {
		parent::setUp();

		$settings = $this->createSettings();
		$errors   = $this->createErrors();

		$this->primary = $this->createClient( 'openai', $settings, $errors );
		$this->cheap   = $this->createClient( 'cheap', $settings, $errors );

		$this->router = new ProviderRouter( $settings, $errors );
		$this->router->register( $this->primary );
		$this->router->register( $this->cheap );
	}

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
			 * Recorded chat calls: each entry is { slug, options }.
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

				return ! empty( $this->responses ) ? \array_shift( $this->responses ) : array( 'content' => $this->providerSlug . '-result', 'options' => $options );
			}

			public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed {
				$this->calls[] = array( 'slug' => $this->providerSlug, 'options' => $options );

				return array( 'content' => $this->providerSlug . '-stream' );
			}

			public function listModels(): mixed {
				return array( $this->providerSlug . '-model' );
			}

			protected function getDefaultBaseUrl(): string {
				return 'https://example.invalid/';
			}
		};
	}

	private function messages(): array {
		return array(
			array( 'role' => 'user', 'content' => 'Say hello.' ),
		);
	}

	private function classifier( string $tier = 'simple', float $confidence = 0.9 ): ComplexityClassifierInterface {
		return new class( $tier, $confidence ) implements ComplexityClassifierInterface {
			public function __construct(
				private readonly string $tier,
				private readonly float $confidence,
			) {}

			public function classify( array $messages, array $options = array() ): array {
				return array( 'tier' => $this->tier, 'confidence' => $this->confidence, 'reason' => 'fixture' );
			}
		};
	}

	private function validator( bool $acceptable = true, float $confidence = 0.9 ): ResponseValidatorInterface {
		return new class( $acceptable, $confidence ) implements ResponseValidatorInterface {
			public function __construct(
				private readonly bool $acceptable,
				private readonly float $confidence,
			) {}

			public function validate( mixed $response, array $messages, array $options = array() ): array {
				return array( 'acceptable' => $this->acceptable, 'confidence' => $this->confidence, 'reason' => 'fixture' );
			}
		};
	}

	private function cascade( ?ComplexityClassifierInterface $classifier, ?ResponseValidatorInterface $validator ): CascadeRouter {
		return new CascadeRouter( $this->router, $classifier, $validator );
	}

	public function test_passthrough_when_unconfigured(): void {
		$cascade = $this->cascade( null, null );

		$result = $cascade->chat( $this->messages(), array(), array( 'provider' => 'openai' ) );

		$this->assertSame( array( 'content' => 'openai-result', 'options' => array() ), $result );
		$this->assertCount( 1, $this->primary->calls );
		$stats = $cascade->getStats();
		$this->assertSame( 1, $stats['passthrough'] );
		$this->assertSame( 0, $stats['accepted'] );
	}

	public function test_passthrough_when_no_tier1_target(): void {
		$cascade = $this->cascade( $this->classifier(), $this->validator() );

		$result = $cascade->chat( $this->messages(), array(), array( 'provider' => 'openai' ) );

		$this->assertSame( array( 'content' => 'openai-result', 'options' => array() ), $result );
		$this->assertSame( 1, $cascade->getStats()['passthrough'] );
	}

	public function test_complex_classification_uses_primary_directly(): void {
		$cascade = $this->cascade( $this->classifier( 'complex' ), $this->validator() );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( array( 'content' => 'openai-result', 'options' => array() ), $result );
		$this->assertCount( 1, $this->primary->calls );
		$this->assertSame( 0, $cascade->getStats()['routed_simple'] );
	}

	public function test_simple_acceptable_returns_tier1_result(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator( true, 0.95 ) );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		// Tier-1 call carries the overridden model and is returned as-is.
		$this->assertSame( 'openai-mini', $result['options']['model'] );
		$this->assertCount( 1, $this->primary->calls );
		$stats = $cascade->getStats();
		$this->assertSame( 1, $stats['routed_simple'] );
		$this->assertSame( 1, $stats['accepted'] );
		$this->assertSame( 0, $stats['escalated'] );
	}

	public function test_unacceptable_response_escalates_to_primary(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator( false, 0.9 ) );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		// First call is tier-1 (model override), second is the primary escalation.
		$this->assertSame( 'openai-mini', $this->primary->calls[0]['options']['model'] );
		$this->assertSame( 'openai-result', $result['content'] );
		$this->assertCount( 2, $this->primary->calls );
		$stats = $cascade->getStats();
		$this->assertSame( 1, $stats['escalated'] );
		$this->assertSame( 0, $stats['accepted'] );
	}

	public function test_low_confidence_escalates_to_primary(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator( true, 0.5 ) );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( 'openai-result', $result['content'] );
		$this->assertCount( 2, $this->primary->calls );
		$this->assertSame( 1, $cascade->getStats()['escalated'] );
	}

	public function test_threshold_from_assistant_config(): void {
		// Threshold 0.4 from assistant config: a 0.5-confidence verdict passes.
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator( true, 0.5 ) );
		$config  = array(
			'provider'                     => 'openai',
			'cascade_tier_1_model'         => 'openai-mini',
			'cascade_confidence_threshold' => 0.4,
		);

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( 'openai-mini', $result['options']['model'] );
		$this->assertSame( 1, $cascade->getStats()['accepted'] );
	}

	public function test_tier1_error_fails_open_to_primary(): void {
		$this->primary->responses = array( array( 'error' => 'timeout' ), array( 'content' => 'openai-result', 'options' => array() ) );

		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator() );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( 'openai-result', $result['content'] );
		$stats = $cascade->getStats();
		$this->assertSame( 1, $stats['tier1_errors'] );
		$this->assertSame( 1, $stats['escalated'] );
	}

	public function test_tier1_provider_routing_uses_cheap_client(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator( true, 0.95 ) );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_provider' => 'cheap' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( 'cheap-result', $result['content'] );
		$this->assertCount( 1, $this->cheap->calls );
		$this->assertCount( 0, $this->primary->calls );
		$this->assertSame( 1, $cascade->getStats()['accepted'] );
	}

	public function test_unknown_tier1_provider_passes_through_to_primary(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator() );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_provider' => 'does-not-exist' );

		$result = $cascade->chat( $this->messages(), array(), $config );

		$this->assertSame( 'openai-result', $result['content'] );
		$this->assertSame( 1, $cascade->getStats()['passthrough'] );
	}

	public function test_stream_delegates_to_primary_provider(): void {
		$cascade = $this->cascade( $this->classifier( 'simple' ), $this->validator() );
		$config  = array( 'provider' => 'openai', 'cascade_tier_1_model' => 'openai-mini' );

		$result = $cascade->stream( $this->messages(), array(), $config );

		$this->assertSame( 'openai-stream', $result['content'] );
		$this->assertSame( 1, $cascade->getStats()['requests'] );
		$this->assertSame( 0, $cascade->getStats()['accepted'] );
	}
}
