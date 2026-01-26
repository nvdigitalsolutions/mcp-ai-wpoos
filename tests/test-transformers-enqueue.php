<?php
/**
 * Tests for Transformers.js Enqueue Manager
 *
 * @package WP_MCP_AI
 */

/**
 * Test Transformers.js enqueue functionality
 */
class Test_Transformers_Enqueue extends WP_UnitTestCase {

	/**
	 * Test that enqueue manager class exists
	 */
	public function test_enqueue_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Transformers_Enqueue' ) );
	}

	/**
	 * Test that get_available_features returns array
	 */
	public function test_get_available_features() {
		$features = WP_MCP_AI_Transformers_Enqueue::get_available_features();
		
		$this->assertIsArray( $features );
		$this->assertNotEmpty( $features );
		
		// Check for expected features
		$this->assertArrayHasKey( 'summarization', $features );
		$this->assertArrayHasKey( 'sentiment', $features );
		$this->assertArrayHasKey( 'ner', $features );
		$this->assertArrayHasKey( 'embedding', $features );
		$this->assertArrayHasKey( 'translation', $features );
		$this->assertArrayHasKey( 'questionAnswering', $features );
		$this->assertArrayHasKey( 'zeroShot', $features );
	}

	/**
	 * Test that each feature has required properties
	 */
	public function test_feature_structure() {
		$features = WP_MCP_AI_Transformers_Enqueue::get_available_features();
		
		foreach ( $features as $key => $feature ) {
			$this->assertArrayHasKey( 'name', $feature, "Feature $key missing name" );
			$this->assertArrayHasKey( 'description', $feature, "Feature $key missing description" );
			$this->assertArrayHasKey( 'model_size', $feature, "Feature $key missing model_size" );
			
			$this->assertNotEmpty( $feature['name'] );
			$this->assertNotEmpty( $feature['description'] );
			$this->assertNotEmpty( $feature['model_size'] );
		}
	}

	/**
	 * Test scripts are registered
	 */
	public function test_scripts_registered() {
		// Trigger script registration
		do_action( 'wp_enqueue_scripts' );
		
		global $wp_scripts;
		
		// Check if scripts are registered
		$this->assertTrue( isset( $wp_scripts->registered['wp-mcp-ai-transformers-tasks'] ) );
		$this->assertTrue( isset( $wp_scripts->registered['wp-mcp-ai-client-vector-store'] ) );
		$this->assertTrue( isset( $wp_scripts->registered['wp-mcp-ai-transformers-tools'] ) );
	}

	/**
	 * Test script dependencies
	 */
	public function test_script_dependencies() {
		do_action( 'wp_enqueue_scripts' );
		
		global $wp_scripts;
		
		// Vector store should depend on tasks client
		$vector_store = $wp_scripts->registered['wp-mcp-ai-client-vector-store'];
		$this->assertContains( 'wp-mcp-ai-transformers-tasks', $vector_store->deps );
		
		// Tools integration should depend on both
		$tools = $wp_scripts->registered['wp-mcp-ai-transformers-tools'];
		$this->assertContains( 'wp-mcp-ai-transformers-tasks', $tools->deps );
		$this->assertContains( 'wp-mcp-ai-client-vector-store', $tools->deps );
	}

	/**
	 * Test transformers enabled option
	 */
	public function test_transformers_option() {
		// Test default value (false)
		$enabled = get_option( 'wp_mcp_ai_enable_transformers', false );
		$this->assertFalse( $enabled );
		
		// Test setting to true
		update_option( 'wp_mcp_ai_enable_transformers', true );
		$enabled = get_option( 'wp_mcp_ai_enable_transformers' );
		$this->assertTrue( $enabled );
		
		// Clean up
		delete_option( 'wp_mcp_ai_enable_transformers' );
	}

	/**
	 * Test semantic search option
	 */
	public function test_semantic_search_option() {
		// Test setting
		update_option( 'wp_mcp_ai_enable_semantic_search', true );
		$enabled = get_option( 'wp_mcp_ai_enable_semantic_search' );
		$this->assertTrue( $enabled );
		
		// Clean up
		delete_option( 'wp_mcp_ai_enable_semantic_search' );
	}

	/**
	 * Test translation option
	 */
	public function test_translation_option() {
		// Test setting
		update_option( 'wp_mcp_ai_enable_translation', true );
		$enabled = get_option( 'wp_mcp_ai_enable_translation' );
		$this->assertTrue( $enabled );
		
		// Clean up
		delete_option( 'wp_mcp_ai_enable_translation' );
	}
}
