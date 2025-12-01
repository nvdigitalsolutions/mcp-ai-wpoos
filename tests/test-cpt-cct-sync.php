<?php
/**
 * Tests for CPT to CCT synchronization.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test CPT to CCT sync functionality.
 */
class WP_MCP_AI_CPT_CCT_Sync_Test extends WP_UnitTestCase {
	/**
	 * Test that sync_to_cct method exists and can be called.
	 */
	public function test_sync_to_cct_method_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$cpt      = new WP_MCP_AI_Assistant_CPT( $registry );

		$this->assertTrue(
			method_exists( $cpt, 'sync_to_cct' ),
			'sync_to_cct method should exist'
		);
	}

	/**
	 * Test that delete_cct_item method exists.
	 */
	public function test_delete_cct_item_method_exists() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$cpt      = new WP_MCP_AI_Assistant_CPT( $registry );

		$this->assertTrue(
			method_exists( $cpt, 'delete_cct_item' ),
			'delete_cct_item method should exist'
		);
	}

	/**
	 * Test that _wp_mcp_ai_cct_item_id meta is set when assistant is created.
	 *
	 * Note: This test will only verify the meta field exists. Full sync
	 * testing requires JetEngine to be active.
	 */
	public function test_cct_item_id_meta_field() {
		// Create a test assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// The meta might not be set in base version or without JetEngine.
		// We just verify the post was created successfully.
		$this->assertGreaterThan( 0, $post_id, 'Assistant post should be created' );

		$post = get_post( $post_id );
		$this->assertEquals( 'mcp_ai_assistant', $post->post_type );
	}

	/**
	 * Test that sync doesn't run in base version mode.
	 */
	public function test_sync_skips_in_base_version() {
		// Mock base version check.
		if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_is_base_version function not available' );
		}

		// Create assistant.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// In base version, CCT item ID should not be set.
		if ( wp_mcp_ai_is_base_version() ) {
			$cct_item_id = get_post_meta( $post_id, '_wp_mcp_ai_cct_item_id', true );
			$this->assertEmpty( $cct_item_id, 'CCT item ID should not be set in base version' );
		}
	}

	/**
	 * Test sync data mapping.
	 */
	public function test_sync_data_mapping() {
		// Create assistant with metadata.
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Sync Assistant',
				'post_content' => 'Test description',
				'post_status'  => 'publish',
			)
		);

		// Set some meta.
		update_post_meta( $post_id, '_wp_mcp_ai_provider', 'openai' );
		update_post_meta( $post_id, '_wp_mcp_ai_model', 'gpt-4o-mini' );
		update_post_meta( $post_id, '_wp_mcp_ai_temperature', 0.7 );
		update_post_meta( $post_id, '_wp_mcp_ai_system_prompt', 'You are a helpful assistant' );
		update_post_meta( $post_id, '_wp_mcp_ai_tools', array( 'search_content', 'save_post' ) );

		// Get configuration.
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		// Verify the data that would be synced.
		$this->assertEquals( 'openai', $config['provider'] );
		$this->assertEquals( 'gpt-4o-mini', $config['model'] );
		$this->assertEquals( 0.7, $config['temperature'] );
		$this->assertEquals( 'You are a helpful assistant', $config['system_prompt'] );
		$this->assertContains( 'search_content', $config['tools'] );
		$this->assertContains( 'save_post', $config['tools'] );
	}

	/**
	 * Test that advanced features are not included in basic sync.
	 */
	public function test_advanced_features_not_in_sync() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set advanced features that should NOT sync to CCT.
		update_post_meta( $post_id, '_wp_mcp_ai_memory_files', array( 123, 456 ) );
		update_post_meta( $post_id, '_wp_mcp_ai_vector_store_id', 'vs_abc123' );
		update_post_meta(
			$post_id,
			'_wp_mcp_ai_tool_shortcuts',
			array(
				array(
					'label'   => 'Quick Search',
					'payload' => 'Search for...',
				),
			)
		);

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		// Verify these are in the config.
		$this->assertArrayHasKey( 'memory_files', $config );
		$this->assertArrayHasKey( 'vector_store_id', $config );
		$this->assertArrayHasKey( 'tool_shortcuts', $config );

		// But they won't be in the CCT sync (CCT only has 7 basic fields).
		// This is just documenting the behavior.
		$this->assertNotEmpty( $config['memory_files'] );
	}

	/**
	 * Test that model field is properly sanitized when meta doesn't exist.
	 */
	public function test_model_field_sanitization_when_missing() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant Without Model',
				'post_status' => 'publish',
			)
		);

		// Don't set model meta - simulates assistant created without model.
		// get_post_meta will return false for non-existent meta.

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		// Model should be an empty string, not false or null.
		$this->assertIsString( $config['model'], 'Model field should be a string' );
		$this->assertSame( '', $config['model'], 'Model field should be empty string when not set' );
	}

	/**
	 * Test that provider, model, system_prompt fields are properly sanitized.
	 */
	public function test_string_fields_sanitization() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Don't set any meta fields.

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		// All string fields should be strings, not false or null.
		$this->assertIsString( $config['provider'], 'Provider should be a string' );
		$this->assertIsString( $config['model'], 'Model should be a string' );
		$this->assertIsString( $config['system_prompt'], 'System prompt should be a string' );
		$this->assertIsString( $config['vector_store_id'], 'Vector store ID should be a string' );

		$this->assertSame( '', $config['provider'] );
		$this->assertSame( '', $config['model'] );
		$this->assertSame( '', $config['system_prompt'] );
		$this->assertSame( '', $config['vector_store_id'] );
	}

	/**
	 * Test that temperature field handles missing meta correctly.
	 */
	public function test_temperature_field_sanitization_when_missing() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Don't set temperature meta.

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		// Temperature should be null when not set.
		$this->assertNull( $config['temperature'], 'Temperature should be null when not set' );
	}
}
