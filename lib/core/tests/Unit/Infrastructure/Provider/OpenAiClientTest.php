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
}
