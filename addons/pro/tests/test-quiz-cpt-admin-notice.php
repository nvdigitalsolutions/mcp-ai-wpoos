<?php
/**
 * Tests for Quiz CPT admin notices.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Quiz CPT admin notice functionality.
 */
class Test_Quiz_CPT_Admin_Notice extends WP_UnitTestCase {
	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Quiz CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Quiz_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-quiz-cpt.php';
		}

		// Set up global $current_screen for admin context.
		set_current_screen( 'edit-mcp_ai_quiz' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up any settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test that admin notice is not shown when quiz system is enabled.
	 */
	public function test_no_notice_when_enabled() {
		// Enable quiz system.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_quiz_system' => true,
			)
		);

		// Simulate accessing quiz page.
		$_GET['post_type'] = 'mcp_ai_quiz';

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should not show notice when enabled.
		$this->assertEmpty( $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that admin notice is shown when quiz system is disabled.
	 */
	public function test_notice_shown_when_disabled() {
		// Disable quiz system (default state).
		update_option( 'wp_mcp_ai_settings', array() );

		// Simulate accessing quiz page.
		$_GET['post_type'] = 'mcp_ai_quiz';

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should show notice with specific text.
		$this->assertStringContainsString( 'Quiz System Disabled', $output );
		$this->assertStringContainsString( 'Enable Quiz System', $output );
		$this->assertStringContainsString( 'Settings', $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that admin notice is shown for submission post type when disabled.
	 */
	public function test_notice_shown_for_submission_when_disabled() {
		// Disable quiz system.
		update_option( 'wp_mcp_ai_settings', array() );

		// Simulate accessing submission page.
		$_GET['post_type'] = 'mcp_ai_submission';

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should show notice.
		$this->assertStringContainsString( 'Quiz System Disabled', $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that admin notice is not shown on non-quiz pages.
	 */
	public function test_no_notice_on_non_quiz_pages() {
		// Disable quiz system.
		update_option( 'wp_mcp_ai_settings', array() );

		// Simulate accessing non-quiz page.
		$_GET['post_type'] = 'post';

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should not show notice on non-quiz pages.
		$this->assertEmpty( $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that admin notice is not shown when no post_type parameter.
	 */
	public function test_no_notice_without_post_type() {
		// Disable quiz system.
		update_option( 'wp_mcp_ai_settings', array() );

		// No post_type parameter.
		if ( isset( $_GET['post_type'] ) ) {
			unset( $_GET['post_type'] );
		}

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should not show notice without post_type.
		$this->assertEmpty( $output );
	}

	/**
	 * Test that Base Version notice is shown when in Base Version mode.
	 *
	 * Note: This test is skipped if wp_mcp_ai_is_base_version function doesn't exist.
	 */
	public function test_base_version_notice() {
		// Skip if function doesn't exist.
		if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_is_base_version function not available' );
		}

		// Mock Base Version mode - this would typically be done via wp-config.php.
		// We'll skip this test as we can't easily mock a constant-based function.
		$this->markTestSkipped( 'Cannot easily mock Base Version mode in unit tests' );
	}

	/**
	 * Test that notice contains proper HTML structure and escaping.
	 */
	public function test_notice_html_structure() {
		// Disable quiz system.
		update_option( 'wp_mcp_ai_settings', array() );

		// Simulate accessing quiz page.
		$_GET['post_type'] = 'mcp_ai_quiz';

		// Capture output.
		ob_start();
		WP_MCP_AI_Quiz_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Check HTML structure.
		$this->assertStringContainsString( '<div class="notice notice-warning">', $output );
		$this->assertStringContainsString( '</div>', $output );
		$this->assertStringContainsString( '<p>', $output );
		$this->assertStringContainsString( '</p>', $output );

		// Check for proper link with escaped URL.
		$this->assertStringContainsString( '<a href="', $output );
		$this->assertStringContainsString( 'admin.php?page=wp_mcp_ai_settings', $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}
}
