<?php
/**
 * Tests for PM Assistant Metabox Validation Fix.
 *
 * Verifies that modal HTML is always rendered regardless of validation state.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_PM_Assistant_Validation_Fix
 */
class Test_PM_Assistant_Validation_Fix extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Activate pro addon if available.
		if ( file_exists( WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
		}
	}

	/**
	 * Test that modal HTML is rendered even when no assistants exist.
	 */
	public function test_modal_rendered_without_assistants() {
		// Skip if pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_AI_Assistant_Metabox' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Create a project post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Enable project management.
		update_option( 'wp_mcp_ai_settings', array( 'enable_project_management' => true ) );

		// Delete all assistants to trigger validation.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $assistants as $assistant_id ) {
			wp_delete_post( $assistant_id, true );
		}

		// Capture metabox output.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
		$post    = get_post( $post_id );

		ob_start();
		$metabox->render( $post );
		$output = ob_get_clean();

		// Assert modal HTML is present.
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-modal"', $output, 'Modal HTML should be rendered' );
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-chat-container"', $output, 'Chat container should be rendered' );

		// Assert warning message is present.
		$this->assertStringContainsString( 'No AI assistants available', $output, 'Warning message should be shown' );
		$this->assertStringContainsString( 'Create an assistant first', $output, 'Action link should be shown' );
	}

	/**
	 * Test that modal HTML is rendered when project management is disabled.
	 */
	public function test_modal_rendered_when_pm_disabled() {
		// Skip if pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_AI_Assistant_Metabox' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Create a project post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Disable project management.
		update_option( 'wp_mcp_ai_settings', array( 'enable_project_management' => false ) );

		// Capture metabox output.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
		$post    = get_post( $post_id );

		ob_start();
		$metabox->render( $post );
		$output = ob_get_clean();

		// Assert modal HTML is present.
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-modal"', $output, 'Modal HTML should be rendered' );
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-chat-container"', $output, 'Chat container should be rendered' );

		// Assert warning message is present.
		$this->assertStringContainsString( 'Project Management features are not enabled', $output, 'Warning message should be shown' );
		$this->assertStringContainsString( 'Enable them in Settings', $output, 'Action link should be shown' );
	}

	/**
	 * Test that modal HTML is rendered with valid configuration.
	 */
	public function test_modal_rendered_with_valid_config() {
		// Skip if pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_AI_Assistant_Metabox' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Create a project post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Enable project management.
		update_option( 'wp_mcp_ai_settings', array( 'enable_project_management' => true ) );

		// Create an assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Capture metabox output.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
		$post    = get_post( $post_id );

		ob_start();
		$metabox->render( $post );
		$output = ob_get_clean();

		// Assert modal HTML is present.
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-modal"', $output, 'Modal HTML should be rendered' );
		$this->assertStringContainsString( 'id="wp-mcp-ai-pm-assistant-chat-container"', $output, 'Chat container should be rendered' );

		// Assert normal UI is present.
		$this->assertStringContainsString( 'Select Assistant:', $output, 'Assistant selector should be shown' );
		$this->assertStringContainsString( 'Open AI Assistant', $output, 'Open button should be shown' );
		$this->assertStringContainsString( 'Test Assistant', $output, 'Assistant should be in dropdown' );

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that modal uses correct classes for CSS styling.
	 */
	public function test_modal_has_correct_classes() {
		// Skip if pro addon not available.
		if ( ! class_exists( 'WP_MCP_AI_Project_Management_AI_Assistant_Metabox' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Create a project post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Enable project management (minimal config).
		update_option( 'wp_mcp_ai_settings', array( 'enable_project_management' => true ) );

		// Capture metabox output.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
		$post    = get_post( $post_id );

		ob_start();
		$metabox->render( $post );
		$output = ob_get_clean();

		// Assert modal has correct classes.
		$this->assertStringContainsString( 'class="wp-mcp-ai-cpt-modal"', $output, 'Modal should have cpt-modal class' );
		$this->assertStringContainsString( 'class="wp-mcp-ai-cpt-modal__backdrop"', $output, 'Backdrop should exist' );
		$this->assertStringContainsString( 'class="wp-mcp-ai-cpt-modal__panel"', $output, 'Panel should exist' );
		$this->assertStringContainsString( 'class="wp-mcp-ai-cpt-modal__header"', $output, 'Header should exist' );
		$this->assertStringContainsString( 'class="wp-mcp-ai-cpt-modal__body"', $output, 'Body should exist' );
	}
}
