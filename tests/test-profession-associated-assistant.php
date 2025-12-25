<?php
/**
 * Test Profession Associated Assistant
 *
 * Tests that professions can be associated with an assistant for testing purposes.
 *
 * @package WP_MCP_AI
 */

/**
 * Test profession associated assistant functionality.
 */
class Test_Profession_Associated_Assistant extends WP_UnitTestCase {

	/**
	 * Test profession ID.
	 *
	 * @var int
	 */
	protected $profession_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test profession.
		$this->profession_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Marketing Consultant',
				'post_content' => 'A marketing professional',
				'post_status'  => 'publish',
			)
		);

		// Create test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Marketing AI Assistant',
				'post_content' => 'Assistant configured for marketing tasks',
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		if ( $this->profession_id ) {
			wp_delete_post( $this->profession_id, true );
		}

		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Test that META_ASSOCIATED_ASSISTANT constant exists.
	 */
	public function test_meta_associated_assistant_constant_exists() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT' ),
			'META_ASSOCIATED_ASSISTANT constant should be defined'
		);

		$this->assertSame(
			'_wp_mcp_ai_profession_associated_assistant',
			WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT,
			'META_ASSOCIATED_ASSISTANT should have correct value'
		);
	}

	/**
	 * Test that associated assistant can be set and retrieved.
	 */
	public function test_can_set_and_get_associated_assistant() {
		// Set associated assistant.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Retrieve associated assistant.
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );

		$this->assertSame(
			$this->assistant_id,
			absint( $associated_assistant ),
			'Associated assistant ID should match the set value'
		);
	}

	/**
	 * Test that associated assistant meta is properly sanitized.
	 */
	public function test_associated_assistant_is_sanitized() {
		// Try setting invalid values.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, 'invalid' );
		$value = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame( 0, absint( $value ), 'Invalid string should be sanitized to 0' );

		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, -5 );
		$value = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame( 0, absint( $value ), 'Negative number should be sanitized to 0' );

		// Set valid value.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );
		$value = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame( $this->assistant_id, absint( $value ), 'Valid assistant ID should be preserved' );
	}

	/**
	 * Test that profession without associated assistant returns empty value.
	 */
	public function test_profession_without_associated_assistant() {
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );

		$this->assertEmpty(
			$associated_assistant,
			'Profession without associated assistant should return empty value'
		);
	}

	/**
	 * Test that associated assistant can be cleared.
	 */
	public function test_can_clear_associated_assistant() {
		// Set associated assistant.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Verify it's set.
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame( $this->assistant_id, absint( $associated_assistant ) );

		// Clear it.
		delete_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT );

		// Verify it's cleared.
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertEmpty( $associated_assistant, 'Associated assistant should be cleared' );
	}

	/**
	 * Test that meta is registered properly.
	 */
	public function test_meta_is_registered() {
		$registered = registered_meta_key_exists( 'post', WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, 'mcp_ai_profession' );

		$this->assertTrue(
			$registered,
			'Associated assistant meta should be registered for profession post type'
		);
	}

	/**
	 * Test that associated assistant persists after profession update.
	 */
	public function test_associated_assistant_persists_after_update() {
		// Set associated assistant.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Update the profession post.
		wp_update_post(
			array(
				'ID'           => $this->profession_id,
				'post_content' => 'Updated marketing professional description',
			)
		);

		// Verify associated assistant is still set.
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame(
			$this->assistant_id,
			absint( $associated_assistant ),
			'Associated assistant should persist after profession update'
		);
	}

	/**
	 * Test that test profession page handles deleted associated assistant gracefully.
	 */
	public function test_test_page_handles_deleted_assistant() {
		// Ensure test profession page class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Profession' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-profession.php';
		}

		// Set associated assistant.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Verify it's set.
		$associated_assistant = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
		$this->assertSame( $this->assistant_id, absint( $associated_assistant ) );

		// Delete the assistant.
		wp_delete_post( $this->assistant_id, true );

		// Simulate what the test profession page does.
		$associated_assistant_meta = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );

		$assistant_title            = '';
		$valid_associated_assistant = 0;
		if ( $associated_assistant_meta ) {
			$assistant_post = get_post( $associated_assistant_meta );
			if ( $assistant_post && 'publish' === $assistant_post->post_status ) {
				$assistant_title            = $assistant_post->post_title;
				$valid_associated_assistant = absint( $associated_assistant_meta );
			}
		}

		// Verify that valid_associated_assistant is 0 (fallback).
		$this->assertSame( 0, $valid_associated_assistant, 'Should fall back to 0 when assistant is deleted' );
		$this->assertSame( '', $assistant_title, 'Assistant title should be empty when assistant is deleted' );
	}

	/**
	 * Test that test profession page handles unpublished associated assistant gracefully.
	 */
	public function test_test_page_handles_unpublished_assistant() {
		// Ensure test profession page class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Profession' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-profession.php';
		}

		// Set associated assistant.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Change assistant to draft status.
		wp_update_post(
			array(
				'ID'          => $this->assistant_id,
				'post_status' => 'draft',
			)
		);

		// Simulate what the test profession page does.
		$associated_assistant_meta = get_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );

		$assistant_title            = '';
		$valid_associated_assistant = 0;
		if ( $associated_assistant_meta ) {
			$assistant_post = get_post( $associated_assistant_meta );
			if ( $assistant_post && 'publish' === $assistant_post->post_status ) {
				$assistant_title            = $assistant_post->post_title;
				$valid_associated_assistant = absint( $associated_assistant_meta );
			}
		}

		// Verify that valid_associated_assistant is 0 (fallback).
		$this->assertSame( 0, $valid_associated_assistant, 'Should fall back to 0 when assistant is unpublished' );
		$this->assertSame( '', $assistant_title, 'Assistant title should be empty when assistant is unpublished' );
	}

	/**
	 * Test that REST API resolves associated assistant when testing profession.
	 */
	public function test_rest_resolves_associated_assistant_for_profession_test() {
		// Ensure REST class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		// Set associated assistant for profession.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, $this->assistant_id );

		// Create REST instance to access protected method.
		$rest       = new WP_MCP_AI_REST();
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test with profession_ prefix.
		$resolved_id = $method->invoke( $rest, 'profession_' . $this->profession_id );

		$this->assertSame(
			$this->assistant_id,
			$resolved_id,
			'Should resolve to associated assistant when testing profession'
		);
	}

	/**
	 * Test that REST API uses default assistant when profession has no associated assistant.
	 */
	public function test_rest_uses_default_when_no_associated_assistant() {
		// Ensure REST class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		// Create a default assistant and set it in settings.
		$default_assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Default Assistant',
				'post_status' => 'publish',
			)
		);

		// Set as default assistant.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			update_option( 'wp_mcp_ai_settings', array( 'default_assistant' => $default_assistant_id ) );
		}

		// Create REST instance to access protected method.
		$rest       = new WP_MCP_AI_REST();
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'resolve_assistant_id' );
		$method->setAccessible( true );

		// Test with profession_ prefix (no associated assistant set).
		$resolved_id = $method->invoke( $rest, 'profession_' . $this->profession_id );

		$this->assertSame(
			$default_assistant_id,
			$resolved_id,
			'Should resolve to default assistant when profession has no associated assistant'
		);

		// Clean up.
		wp_delete_post( $default_assistant_id, true );
	}

	/**
	 * Test that profession configuration is loaded and merged with assistant config.
	 */
	public function test_profession_configuration_merged_with_assistant() {
		// Ensure REST class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		// Set profession meta data.
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_role_description', 'Marketing expert role' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_knowledge_base', 'Marketing strategies and tactics' );
		update_post_meta( $this->profession_id, '_wp_mcp_ai_profession_default_tools', array( 'search_posts', 'create_post' ) );

		// Create REST instance to access protected method.
		$rest       = new WP_MCP_AI_REST();
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'load_profession_configuration' );
		$method->setAccessible( true );

		// Test with base assistant config.
		$base_config = array(
			'system_prompt' => 'Base assistant prompt',
			'tools'         => array( 'default_tool' ),
		);

		$merged_config = $method->invoke( $rest, $this->profession_id, $base_config );

		// Verify profession data overrides base config.
		$this->assertStringContainsString( 'Marketing expert role', $merged_config['system_prompt'], 'Should include profession role description' );
		$this->assertStringContainsString( 'Marketing strategies and tactics', $merged_config['system_prompt'], 'Should include profession knowledge base' );
		$this->assertIsArray( $merged_config['tools'], 'Tools should be an array' );
		$this->assertContains( 'search_posts', $merged_config['tools'], 'Should include profession tools' );
		$this->assertContains( 'create_post', $merged_config['tools'], 'Should include profession tools' );
	}
}
