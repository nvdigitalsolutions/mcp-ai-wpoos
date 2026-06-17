<?php
/**
 * Tests for DeepSeekClient — demonstrates the HTTP mock test pattern.
 *
 * Provider clients receive SettingsStoreInterface, HttpClientInterface,
 * and ErrorFactoryInterface via constructor injection. Tests mock the HTTP
 * client to control API responses without network dependency.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Infrastructure\Provider\DeepSeekClient;
use PHPUnit\Framework\TestCase;

final class DeepSeekClientTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $httpClient;
	private ErrorFactoryInterface $errorFactory;
	private DeepSeekClient $client;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->httpClient   = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		$this->settings->method( 'getApiKey' )
			->with( 'deepseek' )
			->willReturn( 'sk-test-key' );

		$this->settings->method( 'getApiBaseUrl' )
			->with( 'deepseek' )
			->willReturn( '' );

		$this->client = new DeepSeekClient(
			$this->settings,
			$this->httpClient,
			$this->errorFactory,
		);
	}

	public function testGetProviderSlug(): void {
		$this->assertSame( 'deepseek', $this->client->getProviderSlug() );
	}

	public function testChatReturnsErrorWhenApiKeyMissing(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'deepseek' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'missing_api_key',
				'message' => 'No API key configured for deepseek.',
			),
		);

		$this->errorFactory->method( 'create' )
			->willReturn( $expectedError );

		$client = new DeepSeekClient( $settings, $this->httpClient, $this->errorFactory );

		$result = $client->chat( array(), array( 'model' => 'deepseek-chat' ) );

		$this->assertSame( $expectedError, $result );
	}

	public function testChatSendsCorrectRequest(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-123',
			'object'  => 'chat.completion',
			'model'   => 'deepseek-chat',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Hello! How can I help?',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
				'total_tokens'      => 15,
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with( 'POST', $this->stringContains( 'api.deepseek.com' ), $this->anything(), $this->anything() )
			->willReturn( $response );

		$result = $this->client->chat(
			array(
				array( 'role' => 'user', 'content' => 'Hello' ),
			),
			array( 'model' => 'deepseek-chat' ),
		);

		// OpenAiCompatibleClient returns the raw decoded JSON body.
		$this->assertIsArray( $result );
		$this->assertSame( 'deepseek-chat', $result['model'] );
		$this->assertSame( 'chat.completion', $result['object'] );
		$this->assertArrayHasKey( 'choices', $result );
		$this->assertSame( 'assistant', $result['choices'][0]['message']['role'] );
		$this->assertSame(
			'Hello! How can I help?',
			$result['choices'][0]['message']['content'],
		);
		$this->assertArrayHasKey( 'usage', $result );
		$this->assertSame( 15, $result['usage']['total_tokens'] );
	}
}
