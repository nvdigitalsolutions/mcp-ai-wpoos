<?php
/**
 * Tests for AnthropicClient — validates the non-OpenAI-compatible provider pattern.
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
use Nvoos\Core\Infrastructure\Provider\AnthropicClient;
use PHPUnit\Framework\TestCase;

final class AnthropicClientTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $httpClient;
	private ErrorFactoryInterface $errorFactory;
	private AnthropicClient $client;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->httpClient   = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		$this->settings->method( 'getApiKey' )
			->with( 'anthropic' )
			->willReturn( 'sk-ant-test-key' );

		$this->settings->method( 'getApiBaseUrl' )
			->with( 'anthropic' )
			->willReturn( '' );

		$this->settings->method( 'getDefaultModel' )
			->willReturn( 'claude-sonnet-4-6' );

		$this->client = new AnthropicClient(
			$this->settings,
			$this->httpClient,
			$this->errorFactory,
		);
	}

	public function testGetProviderSlug(): void {
		$this->assertSame( 'anthropic', $this->client->getProviderSlug() );
	}

	public function testChatReturnsErrorWhenApiKeyMissing(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'anthropic' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'missing_api_key',
				'message' => 'No Anthropic API key has been configured.',
			),
		);

		$this->errorFactory->method( 'create' )
			->willReturn( $expectedError );

		$client = new AnthropicClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat( array(), array() );

		$this->assertSame( $expectedError, $result );
	}

	public function testChatSendsCorrectRequestWithAnthropicHeaders(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'         => 'msg_abc123',
			'model'      => 'claude-sonnet-4-6',
			'type'       => 'message',
			'content'    => array(
				array(
					'type' => 'text',
					'text' => 'Hello! How can I assist you today?',
				),
			),
			'stop_reason' => 'end_turn',
			'usage'       => array(
				'input_tokens'  => 10,
				'output_tokens' => 5,
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'api.anthropic.com' ),
				$this->callback( function ( array $headers ): bool {
					return isset( $headers['x-api-key'], $headers['anthropic-version'] );
				} ),
				$this->anything(),
			)
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'model' => 'claude-sonnet-4-6' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'claude-sonnet-4-6', $result['model'] );
		$this->assertSame( 'chat.completion', $result['object'] );
		$this->assertSame(
			'Hello! How can I assist you today?',
			$result['choices'][0]['message']['content'],
		);
	}

	public function testChatExtractsSystemMessage(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'msg_sys',
			'model'   => 'claude-sonnet-4-6',
			'type'    => 'message',
			'content' => array(
				array( 'type' => 'text', 'text' => 'Understood.' ),
			),
			'stop_reason' => 'end_turn',
			'usage'       => array( 'input_tokens' => 30, 'output_tokens' => 5 ),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->chat(
			array(
				array( 'role' => 'system', 'content' => 'You are a helpful assistant.' ),
				array( 'role' => 'user', 'content' => 'Hello' ),
			),
			array( 'model' => 'claude-sonnet-4-6' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Understood.', $result['choices'][0]['message']['content'] );
	}

	public function testChatHandlesHttpError(): void {
		$response = new HttpResponse( 500, json_encode( array(
			'error' => array( 'message' => 'Internal server error.' ),
		) ) ?: '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'http_500',
				'message' => 'Internal server error.',
			),
		);

		$this->httpClient->method( 'send' )->willReturn( $response );
		$this->errorFactory->method( 'create' )->willReturn( $expectedError );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'claude-sonnet-4-6' ),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testListModelsReturnsHardcodedList(): void {
		$result = $this->client->listModels();

		$this->assertIsArray( $result );
		$this->assertContains( 'claude-opus-4-6', $result );
		$this->assertContains( 'claude-sonnet-4-6', $result );
		$this->assertContains( 'claude-haiku-4-6', $result );
		$this->assertContains( 'claude-3-5-sonnet-latest', $result );
	}

	public function testChatHandlesRateLimit(): void {
		$response = new HttpResponse( 429, json_encode( array(
			'error' => array( 'message' => 'Too many requests.' ),
		) ) ?: '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'rate_limited',
				'message' => 'Too many requests.',
			),
		);

		$this->httpClient->method( 'send' )->willReturn( $response );
		$this->errorFactory->method( 'rateLimited' )->willReturn( $expectedError );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'claude-sonnet-4-6' ),
		);

		$this->assertSame( $expectedError, $result );
	}
}
