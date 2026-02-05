<?php
/**
 * Tests for WebChat Assistant Assignment feature.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test WebChat Assistant Assignment functionality.
 */
class Test_WebChat_Assistant_Assignment extends WP_UnitTestCase {

	/**
	 * Test assistant assignment metabox is registered.
	 */
	public function test_assistant_metabox_class_exists() {
		// Load the metabox class if not already loaded.
		$metabox_file = WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-assistant.php';
		if ( file_exists( $metabox_file ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-base.php';
			require_once $metabox_file;
		}

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_WebChat_Metabox_Assistant' ),
			'WebChat Assistant metabox class should exist'
		);
	}

	/**
	 * Test saving assistant assignment to webchat room.
	 */
	public function test_save_assistant_assignment() {
		// Create an assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create a webchat room.
		$room_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_webchat',
				'post_title'  => 'Test Room',
				'post_status' => 'publish',
			)
		);

		// Assign the assistant.
		update_post_meta( $room_id, '_mcp_ai_webchat_assigned_assistant', $assistant_id );

		// Verify assignment.
		$assigned = get_post_meta( $room_id, '_mcp_ai_webchat_assigned_assistant', true );
		$this->assertEquals( $assistant_id, absint( $assigned ), 'Assistant should be assigned to room' );
	}

	/**
	 * Test retrieving assigned assistant.
	 */
	public function test_get_assigned_assistant() {
		// Skip if WebChat CPT class doesn't exist.
		if ( ! class_exists( 'WP_MCP_AI_WebChat_CPT' ) ) {
			$this->markTestSkipped( 'WebChat CPT class not available' );
			return;
		}

		// Create an assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create a webchat room.
		$room_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_webchat',
				'post_title'  => 'Test Room',
				'post_status' => 'publish',
			)
		);

		// Assign the assistant.
		update_post_meta( $room_id, '_mcp_ai_webchat_assigned_assistant', $assistant_id );

		// Use the helper method if it exists.
		if ( method_exists( 'WP_MCP_AI_WebChat_CPT', 'get_assigned_assistant' ) ) {
			$assigned = WP_MCP_AI_WebChat_CPT::get_assigned_assistant( $room_id );
			$this->assertEquals( $assistant_id, $assigned, 'get_assigned_assistant should return correct assistant ID' );
		}
	}

	/**
	 * Test no assistant assigned returns 0.
	 */
	public function test_no_assistant_assigned() {
		// Skip if WebChat CPT class doesn't exist.
		if ( ! class_exists( 'WP_MCP_AI_WebChat_CPT' ) ) {
			$this->markTestSkipped( 'WebChat CPT class not available' );
			return;
		}

		// Create a webchat room without an assistant.
		$room_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_webchat',
				'post_title'  => 'Test Room Without Assistant',
				'post_status' => 'publish',
			)
		);

		// Use the helper method if it exists.
		if ( method_exists( 'WP_MCP_AI_WebChat_CPT', 'get_assigned_assistant' ) ) {
			$assigned = WP_MCP_AI_WebChat_CPT::get_assigned_assistant( $room_id );
			$this->assertEquals( 0, $assigned, 'No assistant assigned should return 0' );
		}
	}

	/**
	 * Test metabox can instantiate without errors.
	 */
	public function test_metabox_instantiation() {
		// Load the metabox class if not already loaded.
		$base_file    = WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-base.php';
		$metabox_file = WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-assistant.php';

		if ( file_exists( $base_file ) && file_exists( $metabox_file ) ) {
			require_once $base_file;
			require_once $metabox_file;

			$metabox = new WP_MCP_AI_WebChat_Metabox_Assistant();

			$this->assertInstanceOf(
				'WP_MCP_AI_WebChat_Metabox_Assistant',
				$metabox,
				'Metabox should instantiate successfully'
			);

			$this->assertEquals( 'wp_mcp_ai_webchat_assistant', $metabox->get_id() );
			$this->assertEquals( 'AI Assistant', $metabox->get_title() );
			$this->assertEquals( 'side', $metabox->get_context() );
		} else {
			$this->markTestSkipped( 'WebChat metabox files not available' );
		}
	}
}
