<?php
/**
 * Site node implementations port tests (Wave E4, sub-cluster 5).
 *
 * Characterization suite for the ported built-in nodes
 * (`SiteNodeWpQuery`, `SiteNodeTextBlock`, `SiteNodeFlexContainer`):
 * metadata contracts, the WP_Query execution with its 100-post cap,
 * the text-block tag whitelist with the div fallback, and the flex
 * property whitelists with their fallbacks. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeWpQuery;
use NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeTextBlock;
use NvoosContentGraphAiPlatform\SiteBuilder\Nodes\SiteNodeFlexContainer;

/**
 * Built-in site node characterization.
 */
class Test_Site_Builder_Nodes extends \WP_UnitTestCase {

	// WP Query node.

	/**
	 * The WP Query node declares its documented metadata.
	 */
	public function test_wp_query_metadata() {
		$node = new SiteNodeWpQuery();

		$this->assertSame( 'wp_query_source', $node->get_slug() );
		$this->assertSame( 'source', $node->get_category() );
		$this->assertNotEmpty( $node->get_name() );
		$this->assertNotEmpty( $node->get_description() );

		$inputs = $node->get_inputs();
		$this->assertCount( 5, $inputs );

		$outputs = $node->get_outputs();
		$this->assertCount( 1, $outputs );
		$this->assertSame( 'post_list', $outputs[0]['type'] );
	}

	/**
	 * The WP Query node fetches published posts with the documented summary
	 * shape.
	 */
	public function test_wp_query_execution() {
		self::factory()->post->create( array( 'post_title' => 'Hello World Post' ) );
		self::factory()->post->create( array( 'post_title' => 'Second Post' ) );

		$node   = new SiteNodeWpQuery();
		$result = $node->execute(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertCount( 2, $result['posts'] );

		$post = $result['posts'][0];
		$this->assertArrayHasKey( 'id', $post );
		$this->assertArrayHasKey( 'title', $post );
		$this->assertArrayHasKey( 'excerpt', $post );
		$this->assertArrayHasKey( 'permalink', $post );
		$this->assertArrayHasKey( 'thumbnail_url', $post );
		$this->assertArrayHasKey( 'post_date', $post );
		$this->assertArrayHasKey( 'author_name', $post );
	}

	/**
	 * The WP Query node caps posts_per_page at 100.
	 */
	public function test_wp_query_caps_posts_per_page() {
		$seen_posts_per_page = null;

		add_action(
			'pre_get_posts',
			function ( $query ) use ( &$seen_posts_per_page ) {
				if ( 'post' === $query->get( 'post_type' ) ) {
					$seen_posts_per_page = $query->get( 'posts_per_page' );
				}
			}
		);

		$node   = new SiteNodeWpQuery();
		$result = $node->execute(
			array(
				'post_type'      => 'post',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Test-only oversized request; the node's 100-post cap is the contract under test.
				'posts_per_page' => 9999,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertSame( 100, $seen_posts_per_page, 'The 9999 request must be capped at 100.' );
	}

	/**
	 * The WP Query node returns an empty list when nothing matches.
	 */
	public function test_wp_query_returns_empty_for_no_results() {
		$node   = new SiteNodeWpQuery();
		$result = $node->execute(
			array(
				'post_type'     => 'post',
				'category_slug' => 'no-such-category',
			)
		);

		$this->assertSame( array( 'posts' => array() ), $result );
	}

	// Text Block node.

	/**
	 * The Text Block node declares its documented metadata.
	 */
	public function test_text_block_metadata() {
		$node = new SiteNodeTextBlock();

		$this->assertSame( 'text_block', $node->get_slug() );
		$this->assertSame( 'layout', $node->get_category() );
		$this->assertCount( 4, $node->get_inputs() );
		$this->assertCount( 1, $node->get_outputs() );
	}

	/**
	 * The Text Block node wraps content in the configured tag.
	 */
	public function test_text_block_execution_p_tag() {
		$node   = new SiteNodeTextBlock();
		$result = $node->execute(
			array(
				'content' => 'Body',
				'tag'     => 'p',
			)
		);

		$this->assertSame( '<p>Body</p>', $result['html'] );
	}

	/**
	 * Invalid tags fall back to div.
	 */
	public function test_text_block_falls_back_to_div_for_invalid_tag() {
		$node   = new SiteNodeTextBlock();
		$result = $node->execute(
			array(
				'content' => 'Body',
				'tag'     => 'script',
			)
		);

		$this->assertStringStartsWith( '<div', $result['html'] );
		$this->assertStringNotContainsString( '<script', $result['html'] );
	}

	/**
	 * Without content the wrapper is still rendered.
	 */
	public function test_text_block_renders_empty_tag_with_no_content() {
		$node   = new SiteNodeTextBlock();
		$result = $node->execute( array() );

		$this->assertSame( '<div></div>', $result['html'] );
	}

	// Flex Container node.

	/**
	 * The Flex Container node declares its documented metadata.
	 */
	public function test_flex_container_metadata() {
		$node = new SiteNodeFlexContainer();

		$this->assertSame( 'flex_container', $node->get_slug() );
		$this->assertSame( 'layout', $node->get_category() );
		$this->assertCount( 6, $node->get_inputs() );
		$this->assertCount( 1, $node->get_outputs() );
	}

	/**
	 * The Flex Container wraps children in a flexbox wrapper.
	 */
	public function test_flex_container_execution_with_children() {
		$node = new SiteNodeFlexContainer();

		$result = $node->execute(
			array(
				'children'  => array( '<p>One</p>', '<p>Two</p>' ),
				'direction' => 'row',
				'gap'       => '16px',
				'align'     => 'center',
				'justify'   => 'center',
				'padding'   => '0',
			)
		);

		$this->assertStringContainsString( 'class="nvoos-flex-container"', $result['html'] );
		$this->assertStringContainsString( 'display:flex', $result['html'] );
		$this->assertStringContainsString( 'flex-direction:row', $result['html'] );
		$this->assertStringContainsString( '<p>One</p>', $result['html'] );
		$this->assertStringContainsString( '<p>Two</p>', $result['html'] );
	}

	/**
	 * Invalid flex properties fall back to their defaults.
	 */
	public function test_flex_container_falls_back_on_invalid_props() {
		$node = new SiteNodeFlexContainer();

		$result = $node->execute(
			array(
				'direction' => 'diagonal',
				'align'     => 'sideways',
				'justify'   => 'everywhere',
			)
		);

		$this->assertStringContainsString( 'flex-direction:row', $result['html'] );
		$this->assertStringContainsString( 'align-items:stretch', $result['html'] );
		$this->assertStringContainsString( 'justify-content:flex-start', $result['html'] );
	}

	/**
	 * Without children the empty container is still rendered.
	 */
	public function test_flex_container_renders_empty_container_without_children() {
		$node   = new SiteNodeFlexContainer();
		$result = $node->execute( array() );

		$this->assertSame(
			'<div style="display:flex;flex-direction:row;gap:16px;align-items:stretch;justify-content:flex-start;padding:0;" class="nvoos-flex-container"></div>',
			$result['html']
		);
	}
}
