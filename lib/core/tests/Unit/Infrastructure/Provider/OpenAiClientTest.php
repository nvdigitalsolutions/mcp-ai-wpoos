<?php
/**
 * Tests for OpenAiClient — validates the OpenAI-compatible provider pattern.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\OpenAiClient;
use PHPUnit\Framework\TestCase;

final class OpenAiClientTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $httpClient;
	private ErrorFactoryInterface $errorFactory;
	private OpenAiClient $client;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->httpClient   = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		$this->settings->method( 'getApiKey' )
			->with( 'openai' )
			->willReturn( 'sk-test-key' );

		$this->settings->method( 'getApiBaseUrl' )
			->with( 'openai' )
			->willReturn( '' );

		$this->client = new OpenAiClient(
			$this->settings,
			$this->httpClient,
			$this->errorFactory,
		);
	}

	public function testGetProviderSlug(): void {
		$this->assertSame( 'openai', $this->client->getProviderSlug() );
	}

	public function testChatReturnsErrorWhenApiKeyMissing(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'openai' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'missing_api_key',
				'message' => 'No OpenAI API key has been configured.',
			),
		);

		$this->errorFactory->method( 'create' )
			->willReturn( $expectedError );

		$client = new OpenAiClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat( array(), array( 'model' => 'gpt-4o' ) );

		$this->assertSame( $expectedError, $result );
	}

	public function testChatSendsCorrectRequestToOpenAi(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-openai-999',
			'object'  => 'chat.completion',
			'model'   => 'gpt-4o',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'I am an AI assistant.',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 20,
				'completion_tokens' => 10,
				'total_tokens'      => 30,
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with( 'POST', $this->stringContains( 'api.openai.com' ), $this->anything(), $this->anything() )
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'model' => 'gpt-4o' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gpt-4o', $result['model'] );
		$this->assertSame( 'chat.completion', $result['object'] );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertSame( 'assistant', $result['choices'][0]['message']['role'] );
	}

	public function testChatHandlesBadRequestError(): void {
		$response = new HttpResponse( 400, json_encode( array(
			'error' => array( 'message' => 'Invalid model.' ),
		) ) ?: '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'http_400',
				'message' => 'Invalid model.',
			),
		);

		$this->httpClient->method( 'send' )->willReturn( $response );
		$this->errorFactory->method( 'create' )->willReturn( $expectedError );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'invalid-model' ),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testChatHandlesRateLimitError(): void {
		$response = new HttpResponse( 429, json_encode( array(
			'error' => array( 'message' => 'Rate limit exceeded.' ),
		) ) ?: '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'rate_limited',
				'message' => 'Rate limit exceeded.',
			),
		);

		$this->httpClient->method( 'send' )->willReturn( $response );
		$this->errorFactory->method( 'rateLimited' )->willReturn( $expectedError );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gpt-4o' ),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testChatUsesCustomBaseUrlWhenConfigured(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'openai' )->willReturn( 'sk-custom' );
		$settings->method( 'getApiBaseUrl' )
			->with( 'openai' )
			->willReturn( 'https://custom-proxy.example.com/v1' );

		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-1',
			'object'  => 'chat.completion',
			'model'   => 'gpt-4o',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'OK' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with( 'POST', $this->stringContains( 'custom-proxy.example.com' ), $this->anything(), $this->anything() )
			->willReturn( $response );

		$client = new OpenAiClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Test' ) ),
			array( 'model' => 'gpt-4o' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gpt-4o', $result['model'] );
	}

	public function testListModelsReturnsArray(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'object' => 'list',
			'data'   => array(
				array( 'id' => 'gpt-4o' ),
				array( 'id' => 'gpt-4o-mini' ),
				array( 'id' => 'gpt-3.5-turbo' ),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->listModels();

		$this->assertIsArray( $result );
		$this->assertContains( 'gpt-4o', $result );
		$this->assertContains( 'gpt-4o-mini', $result );
	}

	public function testListModelsReturnsEmptyArrayOnNonJsonResponse(): void {
		// listModels() returns [] when the response body is not valid JSON
		// with a 'data' key (e.g., HTTP 500 errors). Only exceptions from
		// HttpClientInterface::send() produce error objects.
		$response = new HttpResponse( 500, 'Internal Server Error' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->listModels();

		$this->assertSame( array(), $result );
	}

	/**
	 * Capture every request body sent through the mocked HTTP client
	 * into the given array (by reference).
	 *
	 * @param array<int, string> $bodies    Collector array, filled in send order.
	 * @param callable           $responder Callback invoked per send() call.
	 * @return void
	 */
	private function captureBodies( array &$bodies, callable $responder ): void {
		$this->httpClient->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers, ?string $body ) use ( &$bodies, $responder ): HttpResponse {
				$bodies[] = (string) $body;

				return $responder( count( $bodies ), (string) $body );
			}
		);
	}

	/**
	 * The gpt-5 family rejects max_tokens — requests must use
	 * max_completion_tokens (keeping temperature, which gpt-5 supports).
	 */
	public function testChatUsesMaxCompletionTokensForGpt5Models(): void {
		$success = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-1',
			'object'  => 'chat.completion',
			'model'   => 'gpt-5.5',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'OK' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$bodies = array();
		$this->captureBodies( $bodies, static fn() => $success );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gpt-5.5', 'max_tokens' => 4096, 'temperature' => 0.7 ),
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $bodies );

		$payload = json_decode( $bodies[0], true );
		$this->assertArrayNotHasKey( 'max_tokens', $payload );
		$this->assertSame( 4096, $payload['max_completion_tokens'] );
		$this->assertSame( 0.7, $payload['temperature'] );
	}

	/**
	 * o-series reasoning models reject both max_tokens and temperature.
	 */
	public function testChatDropsTemperatureForReasoningModels(): void {
		$success = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-1',
			'object'  => 'chat.completion',
			'model'   => 'o3-mini',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'OK' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$bodies = array();
		$this->captureBodies( $bodies, static fn() => $success );

		$this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'o3-mini', 'max_tokens' => 4096, 'temperature' => 0.7 ),
		);

		$payload = json_decode( $bodies[0], true );
		$this->assertArrayNotHasKey( 'max_tokens', $payload );
		$this->assertSame( 4096, $payload['max_completion_tokens'] );
		$this->assertArrayNotHasKey( 'temperature', $payload );
	}

	/**
	 * Standard models keep the legacy max_tokens + temperature params.
	 */
	public function testChatKeepsMaxTokensForStandardModels(): void {
		$success = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-1',
			'object'  => 'chat.completion',
			'model'   => 'gpt-4o',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'OK' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$bodies = array();
		$this->captureBodies( $bodies, static fn() => $success );

		$this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gpt-4o', 'max_tokens' => 4096, 'temperature' => 0.7 ),
		);

		$payload = json_decode( $bodies[0], true );
		$this->assertSame( 4096, $payload['max_tokens'] );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $payload );
		$this->assertSame( 0.7, $payload['temperature'] );
	}

	/**
	 * When a model rejects max_tokens with the OpenAI "Unsupported
	 * parameter" 400, the client retries once with max_completion_tokens.
	 */
	public function testChatCorrectsUnsupportedParameterAndRetries(): void {
		$error = new HttpResponse( 400, json_encode( array(
			'error' => array(
				'message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.",
				'type'    => 'invalid_request_error',
				'param'   => 'max_tokens',
				'code'    => 'unsupported_parameter',
			),
		) ) ?: '' );

		$success = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-2',
			'object'  => 'chat.completion',
			'model'   => 'gpt-4o',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'Recovered' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$bodies = array();
		$this->captureBodies(
			$bodies,
			static function ( int $call ) use ( $error, $success ): HttpResponse {
				return 1 === $call ? $error : $success;
			}
		);

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gpt-4o', 'max_tokens' => 4096 ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Recovered', $result['choices'][0]['message']['content'] );
		$this->assertCount( 2, $bodies );

		$first  = json_decode( $bodies[0], true );
		$second = json_decode( $bodies[1], true );
		$this->assertSame( 4096, $first['max_tokens'] );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $first );
		$this->assertArrayNotHasKey( 'max_tokens', $second );
		$this->assertSame( 4096, $second['max_completion_tokens'] );
	}

	/**
	 * The streaming path applies the same gpt-5 constraints.
	 */
	public function testStreamUsesMaxCompletionTokensForGpt5Models(): void {
		$chunk  = json_encode( array(
			'choices' => array(
				array( 'delta' => array( 'content' => 'Hello' ) ),
			),
		) ) ?: '';
		$sse    = 'data: ' . $chunk . "\n\n" . "data: [DONE]\n\n";
		$success = new HttpResponse( 200, $sse );
		$bodies  = array();
		$this->captureBodies( $bodies, static fn() => $success );

		$result = $this->client->stream(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gpt-5.5', 'max_tokens' => 4096, 'temperature' => 0.7 ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Hello', $result['choices'][0]['message']['content'] );

		$payload = json_decode( $bodies[0], true );
		$this->assertArrayNotHasKey( 'max_tokens', $payload );
		$this->assertSame( 4096, $payload['max_completion_tokens'] );
		$this->assertSame( 0.7, $payload['temperature'] );
	}
}
