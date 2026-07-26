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
		$this->http->method( 'send' )->willReturn( $this->httpOk() );

		$tool   = new CreateVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array( 'name' => 'My KB' ), array( 'user_id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'vs_123', $result['data']['id'] );
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
		$this->http->method( 'send' )->willReturn( $this->httpOk() );

		$tool   = new GetVectorStoreTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array( 'vector_store_id' => 'vs_abc' ), array() );

		$this->assertTrue( $result['success'] );
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
		$this->http->method( 'send' )->willReturn( new HttpResponse( 200, '{"data":[{"id":"vs_1"},{"id":"vs_2"}],"has_more":false}' ) );

		$tool   = new ListVectorStoresTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['total'] );
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
		$this->http->method( 'send' )->willReturn( new HttpResponse( 200, '{"id":"file-1","status":"completed"}' ) );

		$tool   = new ManageVectorStoreFilesTool( $this->errorFactory, $this->settings, $this->http );
		$result = $tool->execute(
			array( 'action' => 'add', 'vector_store_id' => 'vs_x', 'file_ids' => array( 'file-1' ) ),
			array(),
		);

		$this->assertTrue( $result['success'] );
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
