<?php
/**
 * Tests for OllamaClient — validates the local AI provider pattern.
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
use Nvoos\Core\Infrastructure\Provider\OllamaClient;
use PHPUnit\Framework\TestCase;

final class OllamaClientTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $httpClient;
	private ErrorFactoryInterface $errorFactory;
	private OllamaClient $client;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->httpClient   = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		// Ollama runs locally — no API key required by default.
		$this->settings->method( 'getApiKey' )
			->with( 'ollama' )
			->willReturn( '' );

		$this->settings->method( 'getApiBaseUrl' )
			->with( 'ollama' )
			->willReturn( '' );

		$this->settings->method( 'getDefaultModel' )
			->willReturn( 'llama3' );

		$this->client = new OllamaClient(
			$this->settings,
			$this->httpClient,
			$this->errorFactory,
		);
	}

	public function testGetProviderSlug(): void {
		$this->assertSame( 'ollama', $this->client->getProviderSlug() );
	}

	public function testChatSucceedsWithoutApiKey(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'ollama-chat-1',
			'object'  => 'chat.completion',
			'model'   => 'llama3',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello from local Llama!',
					),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'localhost:11434' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'model' => 'llama3' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'llama3', $result['model'] );
		$this->assertSame( 'assistant', $result['choices'][0]['message']['role'] );
		$this->assertSame(
			'Hello from local Llama!',
			$result['choices'][0]['message']['content'],
		);
	}

	public function testChatWorksWithOptionalApiKey(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )
			->with( 'ollama' )
			->willReturn( 'optional-token' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );

		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'ollama-2',
			'object'  => 'chat.completion',
			'model'   => 'llama3',
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
			->with(
				'POST',
				$this->anything(),
				$this->callback( function ( array $headers ): bool {
					// When an API key is configured, Authorization header should be present.
					return isset( $headers['Authorization'] )
						&& 'Bearer optional-token' === $headers['Authorization'];
				} ),
				$this->anything(),
			)
			->willReturn( $response );

		$client = new OllamaClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Test' ) ),
			array( 'model' => 'llama3' ),
		);

		$this->assertIsArray( $result );
	}

	public function testChatOmitsAuthHeaderWhenNoKey(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'ollama-3',
			'object'  => 'chat.completion',
			'model'   => 'llama3',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'Hi' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->anything(),
				$this->callback( function ( array $headers ): bool {
					// No API key — Authorization header should NOT be present.
					return ! isset( $headers['Authorization'] )
						&& 'application/json' === $headers['Content-Type'];
				} ),
				$this->anything(),
			)
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'llama3' ),
		);

		$this->assertIsArray( $result );
	}

	public function testListModelsReturnsLocalModels(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'object' => 'list',
			'data'   => array(
				array( 'id' => 'llama3' ),
				array( 'id' => 'mistral' ),
				array( 'id' => 'codellama' ),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->listModels();

		$this->assertIsArray( $result );
		$this->assertContains( 'llama3', $result );
		$this->assertContains( 'mistral', $result );
	}

	public function testUsesDefaultLocalhostBaseUrl(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'local-1',
			'object'  => 'chat.completion',
			'model'   => 'llama3',
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
			->with(
				'POST',
				$this->stringContains( 'localhost:11434' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $response );

		$this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'llama3' ),
		);
	}

	public function testUsesCustomBaseUrlWhenConfigured(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'ollama' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )
			->with( 'ollama' )
			->willReturn( 'http://gpu-server:11434/v1' );

		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'remote-1',
			'object'  => 'chat.completion',
			'model'   => 'llama3',
			'choices' => array(
				array(
					'index'   => 0,
					'message' => array( 'role' => 'assistant', 'content' => 'Remote OK' ),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'gpu-server:11434' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $response );

		$client = new OllamaClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Test' ) ),
			array( 'model' => 'llama3' ),
		);

		$this->assertIsArray( $result );
	}
}
