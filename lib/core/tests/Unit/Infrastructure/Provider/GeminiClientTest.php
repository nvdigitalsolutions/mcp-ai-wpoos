<?php
/**
 * Tests for GeminiClient — validates the non-OpenAI-compatible provider pattern.
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
use Nvoos\Core\Infrastructure\Provider\GeminiClient;
use PHPUnit\Framework\TestCase;

final class GeminiClientTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $httpClient;
	private ErrorFactoryInterface $errorFactory;
	private GeminiClient $client;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->httpClient   = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );

		$this->settings->method( 'getApiKey' )
			->with( 'gemini' )
			->willReturn( 'AIza-test-key' );

		$this->settings->method( 'getApiBaseUrl' )
			->with( 'gemini' )
			->willReturn( '' );

		$this->settings->method( 'getDefaultModel' )
			->willReturn( 'gemini-2.0-flash' );

		$this->client = new GeminiClient(
			$this->settings,
			$this->httpClient,
			$this->errorFactory,
		);
	}

	public function testGetProviderSlug(): void {
		$this->assertSame( 'gemini', $this->client->getProviderSlug() );
	}

	public function testChatReturnsErrorWhenApiKeyMissing(): void {
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'gemini' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'missing_api_key',
				'message' => 'No Gemini API key has been configured.',
			),
		);

		$this->errorFactory->method( 'create' )
			->willReturn( $expectedError );

		$client = new GeminiClient( $settings, $this->httpClient, $this->errorFactory );
		$result = $client->chat( array(), array() );

		$this->assertSame( $expectedError, $result );
	}

	public function testChatSendsCorrectRequestToGemini(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array(
							array( 'text' => 'Hello from Gemini!' ),
						),
					),
					'finishReason' => 'STOP',
				),
			),
			'usageMetadata' => array(
				'promptTokenCount'     => 10,
				'candidatesTokenCount' => 5,
				'totalTokenCount'      => 15,
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'generativelanguage.googleapis.com' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hello' ) ),
			array( 'model' => 'gemini-2.0-flash' ),
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gemini-2.0-flash', $result['model'] );
		$this->assertSame( 'chat.completion', $result['object'] );
		$this->assertSame(
			'Hello from Gemini!',
			$result['choices'][0]['message']['content'],
		);
	}

	public function testChatNormalizesGeminiFinishReason(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array( array( 'text' => 'Truncated.' ) ),
					),
					'finishReason' => 'MAX_TOKENS',
				),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Long prompt' ) ),
			array( 'model' => 'gemini-2.0-flash' ),
		);

		$this->assertSame( 'length', $result['choices'][0]['finish_reason'] );
	}

	public function testChatNormalizesGeminiSafetyFinishReason(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array( array( 'text' => '' ) ),
					),
					'finishReason' => 'SAFETY',
				),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gemini-2.0-flash' ),
		);

		$this->assertSame( 'content_filter', $result['choices'][0]['finish_reason'] );
	}

	public function testChatExtractsSystemInstruction(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array( array( 'text' => 'I am polite.' ) ),
					),
					'finishReason' => 'STOP',
				),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->chat(
			array(
				array( 'role' => 'system', 'content' => 'Be polite.' ),
				array( 'role' => 'user', 'content' => 'Hi' ),
			),
			array( 'model' => 'gemini-2.0-flash' ),
		);

		$this->assertSame( 'I am polite.', $result['choices'][0]['message']['content'] );
	}

	public function testChatHandlesHttpError(): void {
		$response = new HttpResponse( 500, json_encode( array(
			'error' => array( 'message' => 'Backend error.' ),
		) ) ?: '' );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'http_500',
				'message' => 'Backend error.',
			),
		);

		$this->httpClient->method( 'send' )->willReturn( $response );
		$this->errorFactory->method( 'create' )->willReturn( $expectedError );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'gemini-2.0-flash' ),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testListModelsReturnsGeminiModels(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'models' => array(
				array( 'name' => 'models/gemini-2.0-flash' ),
				array( 'name' => 'models/gemini-2.0-pro' ),
				array( 'name' => 'models/gemini-1.5-pro' ),
			),
		) ) ?: '' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->listModels();

		$this->assertIsArray( $result );
		$this->assertContains( 'gemini-2.0-flash', $result );
		$this->assertContains( 'gemini-2.0-pro', $result );
		$this->assertContains( 'gemini-1.5-pro', $result );
	}

	public function testListModelsReturnsEmptyArrayOnNonJsonResponse(): void {
		// GeminiClient::listModels() returns [] when the response body is not
		// valid JSON with a 'models' key (e.g., HTTP 403 errors). Only
		// exceptions from HttpClientInterface::send() produce error objects.
		$response = new HttpResponse( 403, 'Forbidden' );

		$this->httpClient->method( 'send' )->willReturn( $response );

		$result = $this->client->listModels();

		$this->assertSame( array(), $result );
	}

	public function testChatConvertsOpenAiToolsToGeminiFormat(): void {
		$response = new HttpResponse( 200, json_encode( array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array( array( 'text' => 'Using tool.' ) ),
					),
					'finishReason' => 'STOP',
				),
			),
		) ) ?: '' );

		$this->httpClient->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->anything(),
				$this->anything(),
				$this->callback( function ( string $body ): bool {
					$payload = json_decode( $body, true );
					return isset( $payload['tools'][0]['functionDeclarations'] );
				} ),
			)
			->willReturn( $response );

		$result = $this->client->chat(
			array( array( 'role' => 'user', 'content' => 'Search for docs' ) ),
			array(
				'model' => 'gemini-2.0-flash',
				'tools' => array(
					array(
						'function' => array(
							'name'        => 'web_search',
							'description' => 'Search the web',
							'parameters'  => array( 'type' => 'object' ),
						),
					),
				),
			),
		);

		$this->assertIsArray( $result );
	}
}
