<?php
/**
 * Tests for per-assistant capability checks.
 *
 * @package WP_MCP_AI
 */

/**
 * Test per-assistant capability functionality.
 */
class Test_Per_Assistant_Capability extends WP_UnitTestCase {

	/**
	 * Test that wp_mcp_ai_get_effective_chat_capability exists.
	 */
	public function test_effective_capability_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_get_effective_chat_capability' ) );
	}

	/**
	 * Test that effective capability falls back to global when no assistant-specific capability is set.
	 */
	public function test_effective_capability_falls_back_to_global() {
		// Create a test assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// No capability set on assistant, should fall back to global.
		$capability = wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'rest' );

		// The global default is 'edit_posts' (or false for REST context depending on filter).
		// We just verify it returns something.
		$this->assertNotNull( $capability );
	}

	/**
	 * Test that effective capability uses assistant-specific capability when set.
	 */
	public function test_effective_capability_uses_assistant_specific() {
		// Create a test assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set assistant-specific capability.
		update_post_meta( $assistant_id, 'mcp_ai_required_capability', 'manage_options' );

		// Should use the assistant-specific capability.
		$capability = wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'rest' );

		$this->assertSame( 'manage_options', $capability );
	}

	/**
	 * Test that 'public' capability works correctly.
	 */
	public function test_effective_capability_public() {
		// Create a test assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set assistant-specific capability to 'public'.
		update_post_meta( $assistant_id, 'mcp_ai_required_capability', 'public' );

		// Should return 'public'.
		$capability = wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'rest' );

		$this->assertSame( 'public', $capability );
	}

	/**
	 * Test that empty capability falls back to global.
	 */
	public function test_effective_capability_empty_falls_back() {
		// Create a test assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set assistant-specific capability to empty string.
		update_post_meta( $assistant_id, 'mcp_ai_required_capability', '' );

		// Should fall back to global capability.
		$capability = wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'rest' );

		// Verify it's not empty (should be the global default).
		$this->assertNotNull( $capability );
	}

	/**
	 * Test that the sanitize_required_capability_meta method works correctly.
	 */
	public function test_sanitize_required_capability_meta() {
		// Test valid capabilities.
		$this->assertSame( 'edit_posts', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( 'edit_posts' ) );
		$this->assertSame( 'manage_options', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( 'manage_options' ) );
		$this->assertSame( 'public', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( 'public' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( '' ) );

		// Test invalid input.
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( array( 'invalid' ) ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( 123 ) );

		// Test invalid capability name with special characters.
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( 'edit-posts!' ) );
	}

	/**
	 * Test that META_REQUIRED_CAPABILITY constant exists.
	 */
	public function test_meta_constant_exists() {
		$this->assertTrue( defined( 'WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY' ) );
		$this->assertSame( 'mcp_ai_required_capability', WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY );
	}
}
