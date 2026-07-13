<?php
/**
 * Tests for PostTypeSchema value object.
 *
 * @package Nvoos\Core\Tests
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Entity;

use Nvoos\Core\Domain\Entity\PostTypeSchema;
use PHPUnit\Framework\TestCase;

final class PostTypeSchemaTest extends TestCase {

	private PostTypeSchema $schema;

	protected function setUp(): void {
		$this->schema = new PostTypeSchema(
			slug: 'post',
			label: 'Posts',
			description: 'Blog posts.',
			isPublic: true,
			isHierarchical: false,
			hasArchive: true,
			showInRest: true,
			restBase: 'posts',
			labels: array(
				'singular_name' => 'Post',
				'add_new_item'  => 'Add New Post',
				'edit_item'     => 'Edit Post',
			),
			capabilities: array(
				'edit_post'    => 'edit_post',
				'delete_post'  => 'delete_post',
			),
			supports: array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			statuses: array( 'publish' => 'Published', 'draft' => 'Draft' ),
		);
	}

	public function testPropertiesAreAccessible(): void {
		$this->assertSame( 'post', $this->schema->slug );
		$this->assertSame( 'Posts', $this->schema->label );
		$this->assertSame( 'Blog posts.', $this->schema->description );
		$this->assertTrue( $this->schema->isPublic );
		$this->assertFalse( $this->schema->isHierarchical );
		$this->assertTrue( $this->schema->hasArchive );
		$this->assertTrue( $this->schema->showInRest );
		$this->assertSame( 'posts', $this->schema->restBase );
	}

	public function testSupportsReturnsTrueForRegisteredFeature(): void {
		$this->assertTrue( $this->schema->supports( 'title' ) );
		$this->assertTrue( $this->schema->supports( 'editor' ) );
		$this->assertTrue( $this->schema->supports( 'thumbnail' ) );
	}

	public function testSupportsReturnsFalseForUnregisteredFeature(): void {
		$this->assertFalse( $this->schema->supports( 'comments' ) );
		$this->assertFalse( $this->schema->supports( 'post-formats' ) );
	}

	public function testDefaultValues(): void {
		$minimal = new PostTypeSchema(
			slug: 'custom_type',
			label: 'Custom',
		);

		$this->assertSame( '', $minimal->description );
		$this->assertTrue( $minimal->isPublic );
		$this->assertFalse( $minimal->isHierarchical );
		$this->assertFalse( $minimal->hasArchive );
		$this->assertFalse( $minimal->showInRest );
		$this->assertNull( $minimal->restBase );
		$this->assertSame( array(), $minimal->labels );
		$this->assertSame( array(), $minimal->capabilities );
		$this->assertSame( array(), $minimal->supports );
		$this->assertSame( array(), $minimal->statuses );
		$this->assertSame( array(), $minimal->metaFields );
	}

	public function testMetaFieldsIncluded(): void {
		$schema = new PostTypeSchema(
			slug: 'product',
			label: 'Products',
			metaFields: array(
				'_price' => array( 'label' => 'Price', 'type' => 'number' ),
				'_sku'   => array( 'label' => 'SKU', 'type' => 'string' ),
			),
		);

		$this->assertCount( 2, $schema->metaFields );
		$this->assertSame( 'Price', $schema->metaFields['_price']['label'] );
	}

	public function testJsonSerialize(): void {
		$json = $this->schema->jsonSerialize();

		$this->assertIsArray( $json );
		$this->assertSame( 'post', $json['slug'] );
		$this->assertSame( 'Posts', $json['label'] );
		$this->assertSame( 'posts', $json['rest_base'] );
		$this->assertSame( array( 'title', 'editor', 'thumbnail', 'excerpt' ), $json['supports'] );
		$this->assertSame(
			array( 'publish' => 'Published', 'draft' => 'Draft' ),
			$json['statuses'],
		);
		$this->assertArrayHasKey( 'labels', $json );
		$this->assertArrayHasKey( 'capabilities', $json );
	}

	public function testHierarchicalPostType(): void {
		$page = new PostTypeSchema(
			slug: 'page',
			label: 'Pages',
			isHierarchical: true,
		);

		$this->assertTrue( $page->isHierarchical );
	}
}
