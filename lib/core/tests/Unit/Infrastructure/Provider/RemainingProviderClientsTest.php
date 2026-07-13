<?php
/**
 * Tests for remaining OpenAI-compatible provider clients.
 *
 * Covers: Baseten, Cloudflare, DigitalOcean, HuggingFace, Kimi,
 *         LM Studio, NVIDIA NIM, OpenRouter.
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
use Nvoos\Core\Infrastructure\Provider\BasetenClient;
use Nvoos\Core\Infrastructure\Provider\CloudflareClient;
use Nvoos\Core\Infrastructure\Provider\DigitalOceanClient;
use Nvoos\Core\Infrastructure\Provider\HuggingFaceClient;
use Nvoos\Core\Infrastructure\Provider\KimiClient;
use Nvoos\Core\Infrastructure\Provider\LmStudioClient;
use Nvoos\Core\Infrastructure\Provider\NvidiaNimClient;
use Nvoos\Core\Infrastructure\Provider\OpenRouterClient;
use PHPUnit\Framework\TestCase;

final class RemainingProviderClientsTest extends TestCase {

	private function makeSettings(): SettingsStoreInterface {
		$s = $this->createMock( SettingsStoreInterface::class );
		$s->method( 'getApiKey' )->willReturn( 'test-api-key' );
		$s->method( 'getApiBaseUrl' )->willReturn( '' );
		$s->method( 'getDefaultModel' )->willReturn( 'test-model' );
		return $s;
	}

	private function makeHttp(): HttpClientInterface {
		return $this->createMock( HttpClientInterface::class );
	}

	private function makeErrors(): ErrorFactoryInterface {
		return $this->createMock( ErrorFactoryInterface::class );
	}

	private function makeOkResponse( string $model ): HttpResponse {
		return new HttpResponse( 200, json_encode( array(
			'id'      => 'chatcmpl-test',
			'object'  => 'chat.completion',
			'model'   => $model,
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'Test response.',
					),
					'finish_reason' => 'stop',
				),
			),
		) ) ?: '' );
	}

	private function assertChatSendsTo(
		object $client,
		HttpClientInterface $http,
		string $urlSubstring,
	): void {
		$response = $this->makeOkResponse( 'test-model' );

		$http->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( $urlSubstring ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $response );

		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'test-model' ),
		);

		$this->assertIsArray( $result );
	}

	// ─── Baseten ──────────────────────────────────────────────────────

	public function testBasetenSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new BasetenClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'baseten', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'api.baseten.co' );
	}

	// ─── Cloudflare ───────────────────────────────────────────────────

	public function testCloudflareSlug(): void {
		$client = new CloudflareClient( $this->makeSettings(), $this->makeHttp(), $this->makeErrors() );
		$this->assertSame( 'cloudflare', $client->getProviderSlug() );
	}

	public function testCloudflareUsesAccountIdInUrl(): void {
		$http     = $this->makeHttp();
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->willReturn( 'test-api-key' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );
		$settings->method( 'getDefaultModel' )->willReturn( 'test-model' );
		$settings->method( 'get' )
			->with( 'cloudflare_account_id', '' )
			->willReturn( 'abc123def456' );

		$client = new CloudflareClient( $settings, $http, $this->makeErrors() );

		$http->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'accounts/abc123def456' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $this->makeOkResponse( 'cf' ) );

		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'cf' ),
		);

		$this->assertIsArray( $result );
	}

	public function testCloudflareFallbackUrlWithoutAccountId(): void {
		$http     = $this->makeHttp();
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->willReturn( 'test-api-key' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );
		$settings->method( 'getDefaultModel' )->willReturn( 'test-model' );
		$settings->method( 'get' )
			->with( 'cloudflare_account_id', '' )
			->willReturn( '' );

		$client = new CloudflareClient( $settings, $http, $this->makeErrors() );

		$http->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( ':account_id' ),
				$this->anything(),
				$this->anything(),
			)
			->willReturn( $this->makeOkResponse( 'cf' ) );

		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'cf' ),
		);

		$this->assertIsArray( $result );
	}

	// ─── DigitalOcean ─────────────────────────────────────────────────

	public function testDigitalOceanSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new DigitalOceanClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'digitalocean', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'inference.do-ai.run' );
	}

	// ─── HuggingFace ──────────────────────────────────────────────────

	public function testHuggingFaceSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new HuggingFaceClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'huggingface', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'api-inference.huggingface.co' );
	}

	// ─── Kimi (Moonshot) ──────────────────────────────────────────────

	public function testKimiSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new KimiClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'kimi', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'api.moonshot.ai' );
	}

	// ─── LM Studio (local, no auth required) ──────────────────────────

	public function testLmStudioSlug(): void {
		$client = new LmStudioClient( $this->makeSettings(), $this->makeHttp(), $this->makeErrors() );
		$this->assertSame( 'lm_studio', $client->getProviderSlug() );
	}

	public function testLmStudioChatWithoutApiKey(): void {
		$http     = $this->makeHttp();
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'lm_studio' )->willReturn( '' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );
		$settings->method( 'getDefaultModel' )->willReturn( 'test-model' );

		$client = new LmStudioClient( $settings, $http, $this->makeErrors() );

		$http->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->stringContains( 'localhost:1234' ),
				$this->callback( function ( array $headers ): bool {
					return ! isset( $headers['Authorization'] );
				} ),
				$this->anything(),
			)
			->willReturn( $this->makeOkResponse( 'local' ) );

		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'local' ),
		);

		$this->assertIsArray( $result );
	}

	public function testLmStudioChatWithOptionalApiKey(): void {
		$http     = $this->makeHttp();
		$settings = $this->createMock( SettingsStoreInterface::class );
		$settings->method( 'getApiKey' )->with( 'lm_studio' )->willReturn( 'optional-key' );
		$settings->method( 'getApiBaseUrl' )->willReturn( '' );
		$settings->method( 'getDefaultModel' )->willReturn( 'test-model' );

		$client = new LmStudioClient( $settings, $http, $this->makeErrors() );

		$http->expects( $this->once() )
			->method( 'send' )
			->with(
				'POST',
				$this->anything(),
				$this->callback( function ( array $headers ): bool {
					return isset( $headers['Authorization'] )
						&& 'Bearer optional-key' === $headers['Authorization'];
				} ),
				$this->anything(),
			)
			->willReturn( $this->makeOkResponse( 'local' ) );

		$result = $client->chat(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'model' => 'local' ),
		);

		$this->assertIsArray( $result );
	}

	// ─── NVIDIA NIM ───────────────────────────────────────────────────

	public function testNvidiaNimSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new NvidiaNimClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'nvidia_nim', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'integrate.api.nvidia.com' );
	}

	// ─── OpenRouter ───────────────────────────────────────────────────

	public function testOpenRouterSlugAndBaseUrl(): void {
		$http   = $this->makeHttp();
		$client = new OpenRouterClient( $this->makeSettings(), $http, $this->makeErrors() );
		$this->assertSame( 'openrouter', $client->getProviderSlug() );
		$this->assertChatSendsTo( $client, $http, 'openrouter.ai' );
	}
}
