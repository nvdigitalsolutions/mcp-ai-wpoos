<?php
/**
 * Tests for GetPostTypeSchemaTool — framework-agnostic migration.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;
use Nvoos\Core\Tool\GetPostTypeSchemaTool;
use PHPUnit\Framework\TestCase;

final class GetPostTypeSchemaToolTest extends TestCase {

	private SchemaStoreInterface $schemaStore;
	private ErrorFactoryInterface $errorFactory;
	private GetPostTypeSchemaTool $tool;

	protected function setUp(): void {
		$this->schemaStore  = $this->createMock( SchemaStoreInterface::class );
		$this->errorFactory = $this->createMock( ErrorFactoryInterface::class );
		$this->tool         = new GetPostTypeSchemaTool( $this->errorFactory, $this->schemaStore );
	}

	public function testGetSlug(): void {
		$this->assertSame( 'get_post_type_schema', $this->tool->getSlug() );
	}

	public function testGetName(): void {
		$this->assertSame( 'Get Post Type Schema', $this->tool->getName() );
	}

	public function testGetRequiredCapability(): void {
		$this->assertSame( 'edit_posts', $this->tool->getRequiredCapability() );
	}

	public function testGetParametersSchema(): void {
		$schema = $this->tool->getParametersSchema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertContains( 'post_type', $schema['required'] );
	}

	public function testExecuteReturnsSchemaWhenFound(): void {
		$postType = new PostTypeSchema(
			slug: 'post',
			label: 'Posts',
			isPublic: true,
			supports: array( 'title', 'editor', 'thumbnail' ),
			statuses: array( 'publish' => 'Published', 'draft' => 'Draft' ),
		);

		$taxonomy = new TaxonomySchema(
			slug: 'category',
			label: 'Categories',
			isHierarchical: true,
		);

		$this->schemaStore->method( 'getPostType' )
			->with( 'post' )
			->willReturn( $postType );

		$this->schemaStore->method( 'listTaxonomies' )
			->with( 'post' )
			->willReturn( array( $taxonomy ) );

		$result = $this->tool->execute(
			array( 'post_type' => 'post' ),
			array(),
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Posts', $result['message'] );

		$this->assertArrayHasKey( 'data', $result );
		$this->assertSame( 'post', $result['data']['slug'] );
		$this->assertSame( 'Posts', $result['data']['label'] );
		$this->assertTrue( $result['data']['is_public'] );

		// Taxonomies should be included.
		$this->assertArrayHasKey( 'taxonomies', $result['data'] );
		$this->assertArrayHasKey( 'category', $result['data']['taxonomies'] );
		$this->assertSame( 'Categories', $result['data']['taxonomies']['category']['label'] );
	}

	public function testExecuteReturnsErrorWhenPostTypeMissing(): void {
		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'validation_failed',
				'message' => 'post_type is required.',
			),
		);

		$this->errorFactory->method( 'validationFailed' )
			->willReturn( $expectedError );

		$result = $this->tool->execute( array(), array() );

		$this->assertSame( $expectedError, $result );
	}

	public function testExecuteReturnsErrorWhenPostTypeNotFound(): void {
		$this->schemaStore->method( 'getPostType' )
			->with( 'nonexistent' )
			->willReturn( null );

		$expectedError = array(
			'success' => false,
			'error'   => array(
				'code'    => 'not_found',
				'message' => 'The post type "nonexistent" is not registered.',
			),
		);

		$this->errorFactory->method( 'notFound' )
			->willReturn( $expectedError );

		$result = $this->tool->execute(
			array( 'post_type' => 'nonexistent' ),
			array(),
		);

		$this->assertSame( $expectedError, $result );
	}

	public function testExecuteIncludesMetaSchemaWhenAvailable(): void {
		$postType = new PostTypeSchema(
			slug: 'product',
			label: 'Products',
			metaFields: array(
				'_price' => array( 'label' => 'Price', 'type' => 'number' ),
			),
		);

		$this->schemaStore->method( 'getPostType' )
			->with( 'product' )
			->willReturn( $postType );

		$this->schemaStore->method( 'listTaxonomies' )
			->willReturn( array() );

		$result = $this->tool->execute(
			array( 'post_type' => 'product' ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'meta_schema', $result['data'] );
		$this->assertSame( 'Price', $result['data']['meta_schema']['_price']['label'] );
	}

	public function testExecuteOmitsMetaSchemaWhenDisabled(): void {
		$postType = new PostTypeSchema(
			slug: 'product',
			label: 'Products',
			metaFields: array( '_price' => array( 'label' => 'Price' ) ),
		);

		$this->schemaStore->method( 'getPostType' )->willReturn( $postType );
		$this->schemaStore->method( 'listTaxonomies' )->willReturn( array() );

		$result = $this->tool->execute(
			array( 'post_type' => 'product', 'include_meta_schema' => false ),
			array(),
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayNotHasKey( 'meta_schema', $result['data'] );
	}
}
