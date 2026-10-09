<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Graph;

use NvoosContentGraph\Graph\Detector;
use WP_UnitTestCase;

/**
 * Unit tests for effective post-type indexing (getIndexedPostTypes /
 * detectPosts).
 *
 * Regression coverage for the bug where CPTs checked on the Sources tab
 * (stored in extra_post_types) were never queried, and unchecked ones
 * (excluded_post_types) were never dropped from the query.
 *
 * @since 1.0.10
 */
class PostTypeIndexingTest extends WP_UnitTestCase {

	/**
	 * Register a fake public CPT and reset the settings option.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		register_post_type(
			'jet_book',
			array(
				'public' => true,
				'label'  => 'Jet Books',
			)
		);

		delete_option( 'nvoos_content_graph_settings' );
	}

	/**
	 * Unregister the fake CPT and drop the override filter.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unregister_post_type( 'jet_book' );
		remove_all_filters( 'nvoos_content_graph_indexed_post_types' );
		parent::tearDown();
	}

	/**
	 * Defaults: post and page only, since no Sources tab choices exist yet.
	 *
	 * @return void
	 */
	public function test_get_indexed_post_types_defaults_to_post_and_page(): void {
		$this->assertSame( array( 'post', 'page' ), Detector::getIndexedPostTypes() );
	}

	/**
	 * A CPT checked on the Sources tab (extra_post_types) is merged in.
	 *
	 * @return void
	 */
	public function test_get_indexed_post_types_merges_opt_in_cpt(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'extra_post_types' => array( 'jet_book' ) )
		);

		$this->assertSame( array( 'post', 'page', 'jet_book' ), Detector::getIndexedPostTypes() );
	}

	/**
	 * A built-in unchecked on the Sources tab (excluded_post_types) is dropped.
	 *
	 * @return void
	 */
	public function test_get_indexed_post_types_drops_excluded_type(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_post_types' => array( 'page' ) )
		);

		$this->assertSame( array( 'post' ), Detector::getIndexedPostTypes() );
	}

	/**
	 * The nvoos_content_graph_indexed_post_types filter overrides the final list.
	 *
	 * @return void
	 */
	public function test_get_indexed_post_types_honors_filter(): void {
		add_filter(
			'nvoos_content_graph_indexed_post_types',
			static function ( array $types ): array {
				$types[] = 'jet_book';
				return $types;
			}
		);

		$this->assertSame( array( 'post', 'page', 'jet_book' ), Detector::getIndexedPostTypes() );
	}

	/**
	 * detectPosts() returns published posts of an opted-in CPT.
	 *
	 * @return void
	 */
	public function test_detect_posts_includes_opted_in_cpt(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'extra_post_types' => array( 'jet_book' ) )
		);

		$book_id = self::factory()->post->create(
			array(
				'post_type'   => 'jet_book',
				'post_status' => 'publish',
			)
		);

		$ids = wp_list_pluck( Detector::detectPosts(), 'ID' );

		$this->assertContains( $book_id, $ids );
	}

	/**
	 * detectPosts() skips published posts of an excluded post type.
	 *
	 * @return void
	 */
	public function test_detect_posts_skips_excluded_type(): void {
		update_option(
			'nvoos_content_graph_settings',
			array( 'excluded_post_types' => array( 'page' ) )
		);

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$ids = wp_list_pluck( Detector::detectPosts(), 'ID' );

		$this->assertNotContains( $page_id, $ids );
	}
}
