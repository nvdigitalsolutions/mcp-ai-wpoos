<?php
/**
 * Tests for Batch 2c tools — OpenAI Vector Store operations.
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
use Nvoos\Core\Tool\CreateVectorStoreTool;
use Nvoos\Core\Tool\GetVectorStoreTool;
use Nvoos\Core\Tool\ListVectorStoresTool;
use Nvoos\Core\Tool\ManageVectorStoreFilesTool;
use PHPUnit\Framework\TestCase;

final class BatchTwoCToolsTest extends TestCase {

	private SettingsStoreInterface $settings;
	private HttpClientInterface $http;
	private ErrorFactoryInterface $errorFactory;

	protected function setUp(): void {
		$this->settings    = $this->createMock( SettingsStoreInterface::class );
		$this->http        = $this->createMock( HttpClientInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );
	}

	private function httpOk( string $body = '{"id":"vs_123","name":"Test","status":"completed"}' ): HttpResponse {
		return new HttpResponse( 200, $body );
	}

	// ═══════════════════════════════════════════════════════════════════
	// CreateVectorStoreTool
	// ═══════════════════════════════════════════════════════════════════

	public function testCreateVectorStoreSlug(): void {
		$tool = new CreateVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'create_vector_store', $tool->getSlug() );
	}

	public function testCreateVectorStoreSuccess(): void {
		$this->settings->method( 'getApiKey' )->with( 'openai' )->willReturn( 'sk-test' );

		$capturedHeaders = array();
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$capturedHeaders ): HttpResponse {
				$capturedHeaders[] = $headers;
				return new HttpResponse( 200, '{"id":"vs_123","name":"Test","status":"completed"}' );
			},
		);

		$tool   = new CreateVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array( 'name' => 'My KB' ), array( 'user_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'vs_123', $result['data']['id'] );
		$this->assertHeadersWithoutBeta( $capturedHeaders );
	}

	public function testCreateVectorStoreMissingName(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( array( 'success' => false, 'error' => array( 'code' => 'validation_failed', 'message' => 'Required.' ) ) );

		$tool   = new CreateVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array(), array() );

		$this->assertFalse( $result['success'] );
	}

	// ═══════════════════════════════════════════════════════════════════
	// GetVectorStoreTool
	// ═══════════════════════════════════════════════════════════════════

	public function testGetVectorStoreSlug(): void {
		$tool = new GetVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'get_vector_store', $tool->getSlug() );
	}

	public function testGetVectorStoreSuccess(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );

		$capturedHeaders = array();
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$capturedHeaders ): HttpResponse {
				$capturedHeaders[] = $headers;
				return new HttpResponse( 200, '{"id":"vs_abc","name":"Test","status":"completed"}' );
			},
		);

		$tool   = new GetVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array( 'vector_store_id' => 'vs_abc' ), array() );

		$this->assertTrue( $result['success'] );
		$this->assertHeadersWithoutBeta( $capturedHeaders );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ListVectorStoresTool
	// ═══════════════════════════════════════════════════════════════════

	public function testListVectorStoresSlug(): void {
		$tool = new ListVectorStoresTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'list_vector_stores', $tool->getSlug() );
	}

	public function testListVectorStoresSuccess(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );

		$capturedHeaders = array();
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$capturedHeaders ): HttpResponse {
				$capturedHeaders[] = $headers;
				return new HttpResponse( 200, '{"data":[{"id":"vs_1"},{"id":"vs_2"}],"has_more":false}' );
			},
		);

		$tool   = new ListVectorStoresTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['total'] );
		$this->assertHeadersWithoutBeta( $capturedHeaders );
	}

	// ═══════════════════════════════════════════════════════════════════
	// ManageVectorStoreFilesTool
	// ═══════════════════════════════════════════════════════════════════

	public function testManageVectorStoreFilesSlug(): void {
		$tool = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$this->assertSame( 'manage_vector_store_files', $tool->getSlug() );
	}

	public function testManageVectorStoreFilesAddSuccess(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );

		$capturedHeaders = array();
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$capturedHeaders ): HttpResponse {
				$capturedHeaders[] = $headers;

				if ( false !== \strpos( $url, '/file_batches/vsfb_1/files' ) ) {
					return new HttpResponse( 200, '{"data":[{"id":"file-1","status":"completed"}],"has_more":false}' );
				}
				if ( false !== \strpos( $url, '/file_batches/vsfb_1' ) ) {
					return new HttpResponse( 200, '{"id":"vsfb_1","status":"completed","vector_store_id":"vs_x"}' );
				}
				if ( false !== \strpos( $url, '/file_batches' ) ) {
					return new HttpResponse( 200, '{"id":"vsfb_1","object":"vector_store.files_batch","status":"in_progress","vector_store_id":"vs_x"}' );
				}

				return new HttpResponse( 200, '{"id":"file-1","status":"completed"}' );
			},
		);

		$tool   = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'action' => 'add', 'vector_store_id' => 'vs_x', 'file_ids' => array( 'file-1' ) ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['total'] );
		$this->assertSame( 'vsfb_1', $result['data']['batch_id'] );
		$this->assertSame( 'completed', $result['data']['batch_status'] );
		$this->assertHeadersWithoutBeta( $capturedHeaders );
	}

	public function testManageVectorStoreFilesAddReportsQueuedWhenPollTimesOut(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
				if ( false !== \strpos( $url, '/file_batches/vsfb_1' ) ) {
					return new HttpResponse( 200, '{"id":"vsfb_1","status":"in_progress","vector_store_id":"vs_x"}' );
				}

				return new HttpResponse( 200, '{"id":"vsfb_1","object":"vector_store.files_batch","status":"in_progress","vector_store_id":"vs_x"}' );
			},
		);

		$tool   = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array(
				'action'           => 'add',
				'vector_store_id'  => 'vs_x',
				'file_ids'         => array( 'file-1' ),
				'poll_max_seconds' => 1,
			),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'still processing', $result['message'] );
		$this->assertSame( 'in_progress', $result['data']['batch_status'] );
	}

	public function testManageVectorStoreFilesAddFallsBackOnBatch404(): void {
		$this->settings->method( 'getApiKey' )->willReturn( 'sk-test' );

		$capturedHeaders = array();
		$this->http->method( 'send' )->willReturnCallback(
			static function ( string $method, string $url, array $headers = array(), ?string $body = null ) use ( &$capturedHeaders ): HttpResponse {
				$capturedHeaders[] = $headers;

				if ( false !== \strpos( $url, '/file_batches' ) ) {
					return new HttpResponse( 404, '{"error":{"message":"Not found"}}' );
				}

				return new HttpResponse( 200, '{"id":"file-1","status":"completed"}' );
			},
		);

		$tool   = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'action' => 'add', 'vector_store_id' => 'vs_x', 'file_ids' => array( 'file-1' ) ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['data']['total'] );
		$this->assertSame( 'completed', $result['data']['added'][0]['status'] );
		$this->assertHeadersWithoutBeta( $capturedHeaders );
	}

	/**
	 * Assert that no captured request carried the Assistants beta header.
	 *
	 * @param array<int,array<string,string>> $capturedHeaders
	 */
	private function assertHeadersWithoutBeta( array $capturedHeaders ): void {
		$this->assertNotEmpty( $capturedHeaders, 'Expected at least one HTTP request.' );

		foreach ( $capturedHeaders as $headers ) {
			$this->assertArrayNotHasKey( 'OpenAI-Beta', $headers, 'Assistants beta header must not be sent.' );
		}
	}

	public function testManageVectorStoreFilesInvalidAction(): void {
		$this->errorFactory->method( 'validationFailed' )
			->willReturn( array( 'success' => false, 'error' => array( 'code' => 'validation_failed', 'message' => 'Invalid action.' ) ) );

		$tool   = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array( 'action' => 'invalid' ), array() );

		$this->assertFalse( $result['success'] );
	}

	public function testAllVectorStoreSchemasReturnValidJsonSchema(): void {
		$tools = array(
			new CreateVectorStoreTool( $this->errorFactory, $this->settings, $this->http ),
			new GetVectorStoreTool( $this->errorFactory, $this->settings, $this->http ),
			new ListVectorStoresTool( $this->errorFactory, $this->settings, $this->http ),
			new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http ),
		);

		foreach ( $tools as $tool ) {
			$schema = $tool->getParametersSchema();
			$this->assertIsArray( $schema );
			$this->assertSame( 'object', $schema['type'] );
			$this->assertArrayHasKey( 'properties', $schema );
		}
	}
}
