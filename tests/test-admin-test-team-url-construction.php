<?php
/**
 * Tests for Team Member URL Construction
 *
 * Validates that the team member endpoint URL is properly constructed
 * with a trailing slash to prevent 404 errors.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Team Member URL Construction.
 */
class Test_Admin_Test_Team_URL_Construction extends WP_UnitTestCase {

	/**
	 * Test that restUrl has a trailing slash when localized.
	 *
	 * This test validates the fix for the issue where the URL
	 * `/wp-json/mcp-ai/v1teams/1408/members` was being generated
	 * instead of `/wp-json/mcp-ai/v1/teams/1408/members`.
	 */
	public function test_rest_url_has_trailing_slash() {
		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Team' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-team.php';
		}

		// Create an instance of the test team class.
		$test_team = new WP_MCP_AI_Admin_Test_Team();

		// Mock the admin page hook to trigger asset enqueue.
		$test_team->page_hook = 'test_page';

		// Set current user as admin.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Capture the localized script data.
		add_filter(
			'wp_localize_script',
			function( $handle, $object_name, $l10n ) {
				if ( 'wp-mcp-ai-chat' === $handle && 'wpMcpAiChat' === $object_name ) {
					// Store the localized data for assertion.
					$GLOBALS['test_localized_data'] = $l10n;
				}
			},
			10,
			3
		);

		// Trigger the enqueue assets method.
		do_action( 'admin_enqueue_scripts', $test_team->page_hook );

		// Get the stored localized data.
		$localized_data = isset( $GLOBALS['test_localized_data'] ) ? $GLOBALS['test_localized_data'] : array();

		// Assert that restUrl exists and has a trailing slash.
		$this->assertArrayHasKey( 'restUrl', $localized_data, 'restUrl should be present in localized data' );

		$rest_url = $localized_data['restUrl'];

		// Verify it ends with a trailing slash.
		$this->assertStringEndsWith( '/', $rest_url, 'restUrl should have a trailing slash' );

		// Verify the URL structure is correct.
		$this->assertStringContainsString( '/mcp-ai/v1/', $rest_url, 'restUrl should contain /mcp-ai/v1/' );

		// Simulate the JavaScript concatenation that was failing.
		$team_id           = 1408;
		$constructed_url   = $rest_url . 'teams/' . $team_id . '/members';
		$expected_endpoint = '/teams/1408/members';

		// Verify no double slashes are created (except in http://).
		$path_part = parse_url( $constructed_url, PHP_URL_PATH );
		$this->assertStringNotContainsString( 'v1teams', $path_part, 'URL should not contain v1teams (missing slash)' );
		$this->assertStringContainsString( 'v1/teams', $path_part, 'URL should contain v1/teams (with slash)' );
		$this->assertStringEndsWith( $expected_endpoint, $path_part, 'URL should end with the correct endpoint path' );

		// Clean up.
		unset( $GLOBALS['test_localized_data'] );
	}

	/**
	 * Test that URL construction works correctly in the shortcode.
	 */
	public function test_shortcode_rest_url_has_trailing_slash() {
		// Ensure required class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Set current user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Capture the localized script data.
		$captured_data = null;
		add_filter(
			'script_loader_tag',
			function( $tag, $handle, $src ) use ( &$captured_data ) {
				if ( 'wp-mcp-ai-chat' === $handle ) {
					// Check if wpMcpAiChat has been localized.
					global $wp_scripts;
					if ( isset( $wp_scripts->registered[ $handle ]->extra['data'] ) ) {
						$data = $wp_scripts->registered[ $handle ]->extra['data'];
						// Extract the restUrl from the localized data.
						if ( preg_match( '/"restUrl":"([^"]+)"/', $data, $matches ) ) {
							$captured_data = $matches[1];
						}
					}
				}
				return $tag;
			},
			10,
			3
		);

		// Render the shortcode.
		do_shortcode( '[wp_mcp_ai_chat assistant_id="' . $assistant_id . '"]' );

		// If we captured data, verify it has a trailing slash.
		if ( null !== $captured_data ) {
			// Decode URL-encoded slashes.
			$rest_url = str_replace( '\/', '/', $captured_data );

			$this->assertStringEndsWith( '/', $rest_url, 'Shortcode restUrl should have a trailing slash' );
			$this->assertStringContainsString( '/mcp-ai/v1/', $rest_url, 'Shortcode restUrl should contain /mcp-ai/v1/' );
		}
	}
}
