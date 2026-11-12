<?php
/**
 * SchemaStore adapter tests (090-F Phase 2 pre-req).
 *
 * The Laravel runtime has no register_post_type(); the content model is
 * declared in config and adapted into the same entities the WordPress
 * adapter returns. These tests pin that shape.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\SchemaStoreInterface;

final class SchemaStoreTest extends TestCase {

	private SchemaStoreInterface $store;

	protected function setUp(): void {
		parent::setUp();

		$this->store = $this->app->make( SchemaStoreInterface::class );
	}

	public function test_default_post_type_is_described(): void {
		$post = $this->store->getPostType( 'post' );

		$this->assertNotNull( $post );
		$this->assertSame( 'post', $post->slug );
		$this->assertSame( 'Posts', $post->label );
		$this->assertTrue( $post->isPublic );
		$this->assertFalse( $post->isHierarchical );
		$this->assertTrue( $post->hasArchive );
		$this->assertTrue( $post->showInRest );
		$this->assertSame( 'posts', $post->restBase );
		$this->assertContains( 'editor', $post->supports );
		$this->assertSame( 'Post', $post->labels['singular_name'] );
		$this->assertSame( 'edit_post', $post->capabilities['edit_post'] );
	}

	public function test_page_is_hierarchical(): void {
		$page = $this->store->getPostType( 'page' );

		$this->assertNotNull( $page );
		$this->assertTrue( $page->isHierarchical );
		$this->assertFalse( $page->hasArchive );
		$this->assertContains( 'page-attributes', $page->supports );
	}

	public function test_unknown_post_type_returns_null(): void {
		$this->assertNull( $this->store->getPostType( 'does_not_exist' ) );
		$this->assertNull( $this->store->getPostType( '' ) );
	}

	public function test_list_post_types_covers_the_registry(): void {
		$slugs = array_map(
			static fn ( $schema ): string => $schema->slug,
			$this->store->listPostTypes()
		);

		$this->assertContains( 'post', $slugs );
		$this->assertContains( 'page', $slugs );
	}

	public function test_taxonomies_attached_to_post(): void {
		$slugs = array_map(
			static fn ( $schema ): string => $schema->slug,
			$this->store->listTaxonomies( 'post' )
		);

		$this->assertSame( array( 'category', 'post_tag' ), $slugs );
	}

	public function test_taxonomies_for_page_are_empty(): void {
		$this->assertSame( array(), $this->store->listTaxonomies( 'page' ) );
	}

	public function test_unknown_taxonomy_returns_null(): void {
		$this->assertNull( $this->store->getTaxonomy( 'does_not_exist' ) );
	}

	public function test_taxonomy_shape_matches_wordpress(): void {
		$category = $this->store->getTaxonomy( 'category' );

		$this->assertNotNull( $category );
		$this->assertSame( 'Categories', $category->label );
		$this->assertTrue( $category->isHierarchical );
		$this->assertTrue( $category->isPublic );
	}

	public function test_post_statuses_exclude_internal_states(): void {
		$statuses = $this->store->getPostStatuses();

		$this->assertSame( 'Published', $statuses['publish'] );
		$this->assertSame( 'Draft', $statuses['draft'] );
		$this->assertArrayNotHasKey( 'auto-draft', $statuses );
		$this->assertArrayNotHasKey( 'inherit', $statuses );
	}

	public function test_post_type_supports_reflects_the_registry(): void {
		$this->assertTrue( $this->store->postTypeSupports( 'post', 'editor' ) );
		$this->assertFalse( $this->store->postTypeSupports( 'post', 'page-attributes' ) );
		$this->assertFalse( $this->store->postTypeSupports( 'does_not_exist', 'editor' ) );
	}

	/**
	 * Phase 2 declares its family by extending the registry, so an override
	 * must be visible without touching the adapter.
	 */
	public function test_config_override_is_respected(): void {
		$this->app['config']->set(
			'oos.content_types',
			array(
				'mcp_ai_task' => array(
					'label'    => 'Tasks',
					'public'   => false,
					'supports' => array( 'title', 'editor' ),
					'statuses' => array( 'publish', 'draft' ),
				),
			)
		);

		$store = $this->app->make( SchemaStoreInterface::class );
		$task  = $store->getPostType( 'mcp_ai_task' );

		$this->assertNotNull( $task );
		$this->assertSame( 'Tasks', $task->label );
		$this->assertFalse( $task->isPublic );
		$this->assertSame( array( 'publish', 'draft' ), $task->statuses );
		$this->assertNull( $store->getPostType( 'post' ) );
	}
}
