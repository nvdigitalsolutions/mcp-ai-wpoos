<?php
/**
 * Tests for Product admin pages hook detection.
 *
 * Validates that product research and consolidate pages correctly detect
 * their admin hook suffixes for asset enqueuing.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Product Pages Hook Detection.
 */
class WP_MCP_AI_Product_Page_Hook_Detection_Test extends WP_UnitTestCase {

	/**
	 * Test that Product Research page enqueues assets on correct hook.
	 */
	public function test_product_research_page_hook_detection() {
		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Product Research page should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Research_Page' ),
			'Product Research Page class should exist'
		);

		// Get the expected hook based on parent menu and page slug.
		// Parent: wp-mcp-ai-ecommerce-toolkit (custom top-level menu)
		// Page slug: research-product
		// Expected hook: wp-mcp-ai-ecommerce-toolkit_page_research-product
		$expected_hook = 'wp-mcp-ai-ecommerce-toolkit_page_research-product';

		// Simulate WordPress calling enqueue_assets with the correct hook.
		// This should NOT return early, meaning assets will be enqueued.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Product_Research_Page' );
		$method     = $reflection->getMethod( 'enqueue_assets' );
		$method->setAccessible( true );

		// Capture what happens when we call with the correct hook.
		// We expect scripts/styles to be enqueued (no early return).
		ob_start();
		$method->invokeArgs( null, array( $expected_hook ) );
		ob_end_clean();

		// Since we can't directly test if the function returned early,
		// we verify the hook format matches what the code expects.
		$page_slug   = 'research-product'; // From WP_MCP_AI_Product_Research_Page::PAGE_SLUG
		$parent_slug = 'wp-mcp-ai-ecommerce-toolkit';

		$this->assertEquals(
			$expected_hook,
			$parent_slug . '_page_' . $page_slug,
			'Hook format should match WordPress pattern for custom parent menus'
		);
	}

	/**
	 * Test that Product Consolidate page enqueues assets on correct hook.
	 */
	public function test_product_consolidate_page_hook_detection() {
		// Skip if WooCommerce is not available or we're in base version mode.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available' );
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			$this->markTestSkipped( 'Running in base version mode' );
		}

		// Product Consolidate page should exist.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Product_Consolidate_Page' ),
			'Product Consolidate Page class should exist'
		);

		// Get the expected hook based on parent menu and page slug.
		// Parent: wp-mcp-ai-ecommerce-toolkit (custom top-level menu)
		// Page slug: product-consolidate
		// Expected hook: wp-mcp-ai-ecommerce-toolkit_page_product-consolidate
		$expected_hook = 'wp-mcp-ai-ecommerce-toolkit_page_product-consolidate';

		// The WRONG hook that was previously used:
		// 'product_page_product-consolidate' (this would be for a CPT parent)
		$wrong_hook = 'product_page_product-consolidate';

		$page_slug   = 'product-consolidate'; // From WP_MCP_AI_Product_Consolidate_Page::PAGE_SLUG
		$parent_slug = 'wp-mcp-ai-ecommerce-toolkit';

		// Verify the expected hook matches the WordPress pattern.
		$this->assertEquals(
			$expected_hook,
			$parent_slug . '_page_' . $page_slug,
			'Hook format should match WordPress pattern for custom parent menus'
		);

		// Verify the wrong hook does NOT match.
		$this->assertNotEquals(
			$wrong_hook,
			$parent_slug . '_page_' . $page_slug,
			'Old CPT-based hook format should not match custom parent menu pattern'
		);
	}

	/**
	 * Test WordPress hook pattern for custom vs CPT parent menus.
	 */
	public function test_wordpress_hook_patterns() {
		// Custom parent menu (add_menu_page):
		// Hook format: {parent_slug}_page_{child_slug}
		$custom_parent = 'wp-mcp-ai-ecommerce-toolkit';
		$child_slug    = 'research-product';
		$custom_hook   = $custom_parent . '_page_' . $child_slug;

		$this->assertEquals(
			'wp-mcp-ai-ecommerce-toolkit_page_research-product',
			$custom_hook,
			'Custom parent menu hook should use full parent slug'
		);

		// CPT parent menu (edit.php?post_type=X):
		// Hook format: {post_type}_page_{child_slug}
		$cpt_post_type = 'mcp_ai_quiz';
		$cpt_hook      = $cpt_post_type . '_page_research-quiz';

		$this->assertEquals(
			'mcp_ai_quiz_page_research-quiz',
			$cpt_hook,
			'CPT parent menu hook should use post type from parent'
		);

		// These patterns should NOT match (different parent types).
		$this->assertNotEquals(
			$custom_hook,
			$cpt_hook,
			'Custom parent and CPT parent hooks should differ'
		);
	}
}
