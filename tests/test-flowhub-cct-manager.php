<?php
/**
 * FlowHub CCT Manager Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

/**
 * Test class for WP_MCP_AI_FlowHub_CCT_Manager.
 */
class Test_FlowHub_CCT_Manager extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
		}
		update_option( 'wp_mcp_ai_flowhub_toolkit_settings', array( 'cct_slug' => 'flowhub_inventory_test' ) );
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		delete_option( 'wp_mcp_ai_flowhub_last_sync' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ //
	// Configuration
	// ------------------------------------------------------------------ //

	public function test_default_cct_slug() {
		delete_option( 'wp_mcp_ai_flowhub_toolkit_settings' );
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$this->assertEquals( 'flowhub_inventory', $manager->get_cct_slug() );
	}

	public function test_custom_cct_slug_from_settings() {
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$this->assertEquals( 'flowhub_inventory_test', $manager->get_cct_slug() );
	}

	public function test_set_cct_slug_override() {
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$manager->set_cct_slug( 'custom_slug' );
		$this->assertEquals( 'custom_slug', $manager->get_cct_slug() );
	}

	// ------------------------------------------------------------------ //
	// Column Definitions
	// ------------------------------------------------------------------ //

	public function test_column_definitions_structure() {
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$columns = $manager->get_column_definitions();
		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'sku', $columns );
		$this->assertArrayHasKey( 'product_name', $columns );
		$this->assertArrayHasKey( 'quantity', $columns );
		$this->assertArrayHasKey( 'price', $columns );
	}

	// ------------------------------------------------------------------ //
	// JetEngine Dependency
	// ------------------------------------------------------------------ //

	public function test_is_cct_available_without_jetengine() {
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$result  = $manager->is_cct_available();
		$this->assertWPError( $result );
	}

	// ------------------------------------------------------------------ //
	// Freshness
	// ------------------------------------------------------------------ //

	public function test_is_fresh_returns_false_without_sync() {
		delete_option( 'wp_mcp_ai_flowhub_last_sync' );
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$this->assertFalse( $manager->is_fresh() );
	}

	public function test_is_fresh_returns_true_with_recent_sync() {
		update_option( 'wp_mcp_ai_flowhub_last_sync', current_time( 'mysql' ) );
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$this->assertTrue( $manager->is_fresh( 900 ) );
	}

	public function test_is_fresh_returns_false_with_stale_sync() {
		update_option( 'wp_mcp_ai_flowhub_last_sync', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$this->assertFalse( $manager->is_fresh( 900 ) );
	}

	// ------------------------------------------------------------------ //
	// Field Mapping
	// ------------------------------------------------------------------ //

	public function test_default_field_mapping() {
		$manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$mapping = $manager->get_default_field_mapping();
		$this->assertIsArray( $mapping );
		$this->assertArrayHasKey( 'product_id', $mapping );
		$this->assertArrayHasKey( 'sku', $mapping );
		$this->assertArrayHasKey( 'price', $mapping );
	}
}
