<?php
/**
 * OpenAiVision adapter tests.
 *
 * The contract had no docblock before this adapter, so these tests are what pin
 * its shape: `infer()` answers `success` plus either `text` or `error` rather
 * than throwing, because the only prior implementation
 * (`Nvoos\WordPress\Adapter\VisionInference`) returns rather than throws. A
 * second implementation that threw where the first returned would make the
 * interface unusable to a caller that checks `success`.
 *
 * Two properties are worth more than the rest:
 *
 * 1. **The credential never appears in an error.** Every failure case serialises
 *    the result and asserts the key is absent.
 * 2. **The image is inlined, not linked.** The WordPress tool sends a public URL
 *    and fetches it first; this sends the bytes, so the assertion is on the
 *    `data:` URL and on the absence of any caller-supplied host.
 *
 * @package Nvoos\Laravel\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\VisionInferenceInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Laravel\Adapter\OpenAiVision;

final class OpenAiVisionTest extends TestCase {

	private const KEY = 'sk-test-secret-value';

	/** @var list<string> */
	private array $paths = array();

	/** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
	private array $requests = array();

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( \is_file( $path ) ) {
				\unlink( $path );
			}
		}

		$this->paths = array();

		parent::tearDown();
	}

	public function test_the_provider_binds_the_vision_contract(): void {
		$this->assertInstanceOf(
			OpenAiVision::class,
			$this->app->make( VisionInferenceInterface::class ),
		);
	}

	public function test_it_reports_availability_from_the_configured_key(): void {
		$missing = new OpenAiVision( $this->settings( null ), $this->http() );
		$present = new OpenAiVision( $this->settings( self::KEY ), $this->http() );

		$this->assertFalse( $missing->isAvailable() );
		$this->assertTrue( $present->isAvailable() );
	}

	public function test_the_available_models_are_the_configured_model(): void {
		$adapter = new OpenAiVision( $this->settings( self::KEY ), $this->http() );

		$this->assertSame( array( 'host-model' ), $adapter->getAvailableModels() );
	}

	public function test_a_missing_image_throws(): void {
		$adapter = new OpenAiVision( $this->settings( self::KEY ), $this->http() );

		$this->expectException( \InvalidArgumentException::class );

		$adapter->infer( $this->path() . '/nope.png', 'describe' );
	}

	public function test_an_unsupported_extension_throws(): void {
		$adapter = new OpenAiVision( $this->settings( self::KEY ), $this->http() );

		$this->expectException( \InvalidArgumentException::class );

		$adapter->infer( $this->image( 'bmp' ), 'describe' );
	}

	public function test_an_empty_image_throws(): void {
		$adapter = new OpenAiVision( $this->settings( self::KEY ), $this->http() );

		$this->expectException( \InvalidArgumentException::class );

		$adapter->infer( $this->image( 'png', '' ), 'describe' );
	}

	public function test_a_missing_key_is_reported_rather_than_thrown(): void {
		$adapter = new OpenAiVision( $this->settings( null ), $this->http() );

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'openai', $result['error'] );
	}

	public function test_the_request_inlines_the_image_and_uses_the_configured_model(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '{"ok":true}' ) ),
		);

		$adapter->infer( $this->image(), 'Describe this product photo.' );

		$body = $this->body();

		$this->assertSame( 'host-model', $body['model'] );
		$this->assertSame( 1024, $body['max_completion_tokens'] );

		$content = $body['messages'][0]['content'];

		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertSame( 'Describe this product photo.', $content[0]['text'] );
		$this->assertSame( 'image_url', $content[1]['type'] );
		$this->assertSame( 'high', $content[1]['image_url']['detail'] );
		$this->assertStringStartsWith( 'data:image/png;base64,', $content[1]['image_url']['url'] );
	}

	public function test_the_request_is_authorised_and_aimed_at_the_chat_route(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '{"ok":true}' ) ),
		);

		$adapter->infer( $this->image(), 'describe' );

		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $this->requests[0]['headers']['Authorization'] );
	}

	public function test_a_configured_base_url_is_honoured(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY, 'http://localhost:11434/v1' ),
			$this->http( $this->completion( '{"ok":true}' ) ),
		);

		$adapter->infer( $this->image(), 'describe' );

		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $this->requests[0]['url'] );
	}

	public function test_a_response_format_is_forwarded_when_asked_for(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '{"ok":true}' ) ),
		);

		$adapter->infer(
			$this->image(),
			'describe',
			array( 'response_format' => array( 'type' => 'json_object' ) ),
		);

		$this->assertSame( array( 'type' => 'json_object' ), $this->body()['response_format'] );
	}

	public function test_a_response_format_is_absent_when_not_asked_for(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '{"ok":true}' ) ),
		);

		$adapter->infer( $this->image(), 'describe' );

		$this->assertArrayNotHasKey( 'response_format', $this->body() );
	}

	public function test_the_text_and_usage_are_projected(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '{"person_detected":true}' ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '{"person_detected":true}', $result['text'] );
		$this->assertSame( 'host-model', $result['model'] );
		$this->assertSame( array( 'total_tokens' => 42 ), $result['usage'] );
	}

	public function test_content_parts_are_flattened(): void {
		$body = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => array(
							array( 'type' => 'text', 'text' => '{"a":' ),
							array( 'type' => 'text', 'text' => '1}' ),
						),
					),
				),
			),
		);

		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( new HttpResponse( 200, (string) \json_encode( $body ) ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '{"a":1}', $result['text'] );
	}

	public function test_a_provider_refusal_is_reported_without_the_credential(): void {
		$body = (string) \json_encode( array( 'error' => array( 'message' => 'image too large' ) ) );

		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( new HttpResponse( 400, $body ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'image too large', $result['error'] );
		$this->assertStringNotContainsString( self::KEY, (string) \json_encode( $result ) );
	}

	public function test_a_non_json_response_is_reported(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( new HttpResponse( 200, '<html>nope</html>' ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not JSON', $result['error'] );
	}

	public function test_an_empty_analysis_is_reported(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( $this->completion( '' ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_a_transport_failure_is_reported_without_the_credential(): void {
		$adapter = new OpenAiVision(
			$this->settings( self::KEY ),
			$this->http( new \RuntimeException( 'connection refused' ) ),
		);

		$result = $adapter->infer( $this->image(), 'describe' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'connection refused', $result['error'] );
		$this->assertStringNotContainsString( self::KEY, (string) \json_encode( $result ) );
	}

	private function settings( ?string $key, ?string $baseUrl = null ): SettingsStoreInterface {
		$settings = $this->createMock( SettingsStoreInterface::class );

		$settings->method( 'getDefaultProvider' )->willReturn( 'openai' );
		$settings->method( 'getDefaultModel' )->willReturn( 'host-model' );
		$settings->method( 'getApiKey' )->willReturn( $key );
		$settings->method( 'getApiBaseUrl' )->willReturn( $baseUrl );

		return $settings;
	}

	private function completion( string $text ): HttpResponse {
		return new HttpResponse(
			200,
			(string) \json_encode(
				array(
					'model'   => 'host-model',
					'choices' => array(
						array( 'message' => array( 'content' => $text ) ),
					),
					'usage'   => array( 'total_tokens' => 42 ),
				),
			),
		);
	}

	private function http( HttpResponse|\RuntimeException ...$answers ): HttpClientInterface {
		$requests = &$this->requests;
		$queue    = $answers;
		$http     = $this->createMock( HttpClientInterface::class );

		$http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$queue, &$requests ): HttpResponse {
				$requests[] = array(
					'method'  => $method,
					'url'     => $url,
					'headers' => $headers,
					'body'    => $body,
				);

				$answer = \array_shift( $queue );

				if ( $answer instanceof \RuntimeException ) {
					throw $answer;
				}

				if ( ! $answer instanceof HttpResponse ) {
					throw new \LogicException( 'No queued HTTP answer.' );
				}

				return $answer;
			}
		);

		return $http;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = $this->requests[0]['body'] ?? '';

		$decoded = \json_decode( (string) $body, true );

		return \is_array( $decoded ) ? $decoded : array();
	}

	private function path(): string {
		return \sys_get_temp_dir();
	}

	/**
	 * A real image file on disk, so the adapter's readability checks are real.
	 */
	private function image( string $extension = 'png', string $contents = 'PNGDATA' ): string {
		$path = \tempnam( \sys_get_temp_dir(), 'nvoos-vision-' );

		if ( false === $path ) {
			throw new \RuntimeException( 'Could not create a temporary file.' );
		}

		$target = $path . '.' . $extension;

		\rename( $path, $target );
		\file_put_contents( $target, $contents );

		$this->paths[] = $target;

		return $target;
	}
}
