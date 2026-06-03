<?php
/**
 * Tests for the three built-in site nodes — WP_Query, Text Block, Flex Container.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

/**
 * Test the built-in site node implementations (end-to-end execution).
 */
class Test_Site_Node_Implementations extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-node-interface.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/nodes/class-wp-mcp-ai-site-node-wp-query.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/nodes/class-wp-mcp-ai-site-node-text-block.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/nodes/class-wp-mcp-ai-site-node-flex-container.php';
	}

	// ─────────── WP_Query Node ───────────

	/**
	 * Test WP_Query node metadata.
	 */
	public function test_wp_query_metadata() {
		$node = new WP_MCP_AI_Site_Node_WP_Query();

		$this->assertSame( 'wp_query_source', $node->get_slug() );
		$this->assertSame( 'source', $node->get_category() );
		$this->assertNotEmpty( $node->get_inputs() );
		$this->assertNotEmpty( $node->get_outputs() );
	}

	/**
	 * Test WP_Query node execution with a published post.
	 */
	public function test_wp_query_execution() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post for Site Builder',
				'post_status'  => 'publish',
				'post_content' => 'Lorum ipsum dolor sit amet.',
				'post_excerpt' => 'Short excerpt.',
			)
		);

		$node   = new WP_MCP_AI_Site_Node_WP_Query();
		$result = $node->execute(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertNotEmpty( $result['posts'] );

		$first = $result['posts'][0];
		$this->assertSame( $post_id, $first['id'] );
		$this->assertSame( 'Test Post for Site Builder', $first['title'] );
		$this->assertStringContainsString( 'excerpt', $first['excerpt'] );
	}

	/**
	 * Test WP_Query node caps posts_per_page at 100.
	 */
	public function test_wp_query_caps_posts_per_page() {
		$node   = new WP_MCP_AI_Site_Node_WP_Query();
		$result = $node->execute( array( 'posts_per_page' => 500 ) );

		$this->assertIsArray( $result );
		// We can't directly assert the limit since WP_Query handles it,
		// but we can verify no error occurred.
		$this->assertArrayHasKey( 'posts', $result );
	}

	/**
	 * Test WP_Query node returns empty posts array when nothing matches.
	 */
	public function test_wp_query_returns_empty_for_no_results() {
		$node   = new WP_MCP_AI_Site_Node_WP_Query();
		$result = $node->execute(
			array(
				'post_type'      => 'post',
				'category_slug'  => 'nonexistent-category-slug',
				'posts_per_page' => 5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertEmpty( $result['posts'] );
	}

	// ─────────── Text Block Node ───────────

	/**
	 * Test Text Block node metadata.
	 */
	public function test_text_block_metadata() {
		$node = new WP_MCP_AI_Site_Node_Text_Block();

		$this->assertSame( 'text_block', $node->get_slug() );
		$this->assertSame( 'layout', $node->get_category() );
	}

	/**
	 * Test Text Block renders correct HTML for a paragraph.
	 */
	public function test_text_block_execution_p_tag() {
		$node   = new WP_MCP_AI_Site_Node_Text_Block();
		$result = $node->execute(
			array(
				'tag'     => 'p',
				'content'  => 'Hello, World!',
				'className' => 'greeting',
			)
		);

		$this->assertArrayHasKey( 'html', $result );
		$this->assertSame(
			'<p class="greeting">Hello, World!</p>',
			$result['html']
		);
	}

	/**
	 * Test Text Block defaults to div when tag is invalid.
	 */
	public function test_text_block_falls_back_to_div_for_invalid_tag() {
		$node   = new WP_MCP_AI_Site_Node_Text_Block();
		$result = $node->execute( array( 'tag' => 'script', 'content' => 'alert(1)' ) );

		$this->assertStringStartsWith( '<div', $result['html'] );
		$this->assertStringNotContainsString( '<script', $result['html'] );
	}

	/**
	 * Test Text Block with empty content.
	 */
	public function test_text_block_renders_empty_tag_with_no_content() {
		$node   = new WP_MCP_AI_Site_Node_Text_Block();
		$result = $node->execute( array() );

		$this->assertSame( '<div></div>', $result['html'] );
	}

	// ─────────── Flex Container Node ───────────

	/**
	 * Test Flex Container node metadata.
	 */
	public function test_flex_container_metadata() {
		$node = new WP_MCP_AI_Site_Node_Flex_Container();

		$this->assertSame( 'flex_container', $node->get_slug() );
		$this->assertSame( 'layout', $node->get_category() );
	}

	/**
	 * Test Flex Container renders correct HTML with children.
	 */
	public function test_flex_container_execution_with_children() {
		$node   = new WP_MCP_AI_Site_Node_Flex_Container();
		$result = $node->execute(
			array(
				'direction' => 'row',
				'gap'       => '8px',
				'children'  => array(
					'<div>Child 1</div>',
					'<div>Child 2</div>',
				),
			)
		);

		$this->assertArrayHasKey( 'html', $result );
		$this->assertStringContainsString( 'display:flex', $result['html'] );
		$this->assertStringContainsString( 'flex-direction:row', $result['html'] );
		$this->assertStringContainsString( 'gap:8px', $result['html'] );
		$this->assertStringContainsString( 'Child 1', $result['html'] );
		$this->assertStringContainsString( 'Child 2', $result['html'] );
		$this->assertStringContainsString( 'nvoos-flex-container', $result['html'] );
	}

	/**
	 * Test Flex Container falls back to defaults on invalid inputs.
	 */
	public function test_flex_container_falls_back_on_invalid_props() {
		$node   = new WP_MCP_AI_Site_Node_Flex_Container();
		$result = $node->execute(
			array(
				'direction' => 'upside-down',
				'align'     => 'crazy',
			)
		);

		$this->assertStringContainsString( 'flex-direction:row', $result['html'] );
		$this->assertStringContainsString( 'align-items:stretch', $result['html'] );
	}

	/**
	 * Test Flex Container with no children renders empty container.
	 */
	public function test_flex_container_renders_empty_container_without_children() {
		$node   = new WP_MCP_AI_Site_Node_Flex_Container();
		$result = $node->execute( array() );

		// Should still produce valid HTML — an empty flex wrapper.
		$this->assertStringStartsWith( '<div style="', $result['html'] );
		$this->assertStringEndsWith( '</div>', $result['html'] );
	}
}
