<?php
/**
 * Tests for Batch 1d tools — cache purge operations.
 *
 * Covers: PurgeCacheTool, PurgeCloudflareCacheTool, PurgeVarnishCacheTool.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Tool\PurgeCacheTool;
use Nvoos\Core\Tool\PurgeCloudflareCacheTool;
use Nvoos\Core\Tool\PurgeVarnishCacheTool;
use PHPUnit\Framework\TestCase;

final class BatchOneDToolsTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $http;
	private ErrorFactoryInterface $errorFactory;

	protected function setUp(): void {
		$this->settings     = $this->createMock( SettingsStoreInterface::class );
		$this->http         = $this->createMock( HttpClientInterface::class );
		$this->errorFactory  = $this->createMock( ErrorFactoryInterface::class );
	}

	private function errorResponse( string $code, string $message ): array {
		return array( 'success' => false, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	private function httpOk( string $body = '{"success":true}' ): HttpResponse {
		return new HttpResponse( 200, $body );
	}

	// ═══════════════════════════════════════════════════════════════════
	// PurgeCacheTool (master orchestrator)
	// ═══════════════════════════════════════════════════════════════════

	public function testPurgeCacheSlug(): void {
		$tool = new PurgeCacheTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'purge_cache', $tool->getSlug() );
	}

	public function testPurgeCacheNothingConfigured(): void {
		$this->settings->method( 'get' )->willReturn( '' );

		$this->errorFactory->method( 'create' )
			->willReturn( $this->errorResponse( 'no_cache_layers', 'No cache layers are currently configured.' ) );

		$tool   = new PurgeCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'no_cache_layers', $result['error']['code'] );
	}

	public function testPurgeCacheNotLoggedIn(): void {
		$this->errorFactory->method( 'forbidden' )
			->willReturn( $this->errorResponse( 'forbidden', 'You must be logged in.' ) );

		$tool   = new PurgeCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array(), array() );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// PurgeCloudflareCacheTool
	// ═══════════════════════════════════════════════════════════════════

	public function testPurgeCloudflareSlug(): void {
		$tool = new PurgeCloudflareCacheTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'purge_cloudflare_cache', $tool->getSlug() );
	}

	public function testPurgeCloudflareMissingToken(): void {
		$this->settings->method( 'get' )->willReturn( '' );

		$this->errorFactory->method( 'create' )
			->willReturn( $this->errorResponse( 'missing_token', 'No Cloudflare API token.' ) );

		$tool   = new PurgeCloudflareCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testPurgeCloudflareSuccess(): void {
		$this->settings->method( 'get' )->willReturnMap( array(
			array( 'cloudflare_api_token', '', 'test-token' ),
			array( 'cloudflare_zone_id', '', 'test-zone' ),
		) );

		$this->http->method( 'send' )->willReturn( $this->httpOk( '{"success":true,"result":{"id":"purge-id"}}' ) );

		$tool   = new PurgeCloudflareCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'urls' => array( 'https://example.com/page' ) ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'accepted', $result['message'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// PurgeVarnishCacheTool
	// ═══════════════════════════════════════════════════════════════════

	public function testPurgeVarnishSlug(): void {
		$tool = new PurgeVarnishCacheTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'purge_varnish_cache', $tool->getSlug() );
	}

	public function testPurgeVarnishDisabled(): void {
		$this->settings->method( 'get' )->willReturn( false );

		$this->errorFactory->method( 'create' )
			->willReturn( $this->errorResponse( 'varnish_disabled', 'Varnish purge is not enabled.' ) );

		$tool   = new PurgeVarnishCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => 1 ),
		);

		$this->assertFalse( $result['success'] );
	}

	public function testPurgeVarnishBanSuccess(): void {
		$this->settings->method( 'get' )->willReturnMap( array(
			array( 'enable_varnish_purge', false, true ),
			array( 'varnish_host', '127.0.0.1', '127.0.0.1' ),
			array( 'site_host', 'localhost', 'example.com' ),
		) );

		$this->http->method( 'send' )->willReturn( $this->httpOk() );

		$tool   = new PurgeVarnishCacheTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'purge_everything' => true ),
			array( 'user_id' => 1 ),
		);

		$this->assertTrue( $result['success'] );
	}

	public function testAllCachePurgeSchemasReturnValidJsonSchema(): void {
		$tools = array(
			new PurgeCacheTool( $this->errorFactory, $this->settings, $this->http ),
			new PurgeCloudflareCacheTool( $this->errorFactory, $this->settings, $this->http ),
			new PurgeVarnishCacheTool( $this->errorFactory, $this->settings, $this->http ),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->getParametersSchema();
			$this->assertIsArray( $schema );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
		}
	}
}
