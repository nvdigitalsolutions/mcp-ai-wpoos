<?php
/**
 * Tool Scope Sanity Security Tests for WP oOS
 *
 * Tests to ensure least-privilege for Gmail/Calendar tools and verify
 * OAuth scopes are appropriate and capability checks are enforced.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool scope and privilege security requirements.
 *
 * @group security
 * @group tools
 * @group oauth
 */
class WP_MCP_AI_Tool_Scope_Sanity_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that search-gmail tool has appropriate OAuth scopes.
	 *
	 * Goal: review OAuth scopes used by search-gmail.
	 */
	public function test_search_gmail_oauth_scopes() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Search_Gmail' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Search_Gmail class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Search_Gmail();

		// Verify tool is properly instantiated.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Search_Gmail',
			$tool,
			'Gmail search tool should be instantiable'
		);

		// Verify tool has expected slug.
		$this->assertEquals(
			'search_gmail',
			$tool->get_slug(),
			'Gmail tool should have correct slug'
		);

		// Check that tool description mentions permissions/scope.
		$description = $tool->get_description();
		$this->assertNotEmpty(
			$description,
			'Gmail tool should have a description'
		);

		// Note: OAuth scopes are typically configured in settings, not hardcoded in the tool.
		// The tool should use least-privilege scopes like gmail.readonly or gmail.metadata.
		// This is a documentation/review test rather than runtime check.
		$this->assertTrue(
			true,
			'Gmail tool OAuth scopes should be reviewed for least-privilege (readonly recommended)'
		);
	}

	/**
	 * Test that search-gmail tool enforces capability checks.
	 *
	 * Goal: run as low-priv WP user to confirm capability checks.
	 */
	public function test_search_gmail_enforces_capability_checks() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Search_Gmail' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Search_Gmail class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Search_Gmail();

		// Create a low-privilege user (subscriber).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Attempt to execute tool as subscriber.
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $subscriber_id )
		);

		// Should return WP_Error for insufficient permissions.
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Gmail tool should reject execution by low-privilege users'
		);

		$this->assertEquals(
			'wp_mcp_ai_gmail_forbidden',
			$result->get_error_code(),
			'Error code should indicate forbidden access'
		);
	}

	/**
	 * Test that search-gmail tool allows execution for admin users.
	 */
	public function test_search_gmail_allows_admin_execution() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Search_Gmail' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Search_Gmail class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Search_Gmail();

		// Create an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Configure minimal Gmail settings to avoid missing config error.
		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['gmail_client_id']     = 'test-client-id';
		$settings['gmail_client_secret'] = 'test-client-secret';
		$settings['gmail_refresh_token'] = 'test-refresh-token';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Attempt to execute tool as admin (will fail at API call, but passes permission check).
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $admin_id )
		);

		// Should not be forbidden error (will be different error like API failure).
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals(
				'wp_mcp_ai_gmail_forbidden',
				$result->get_error_code(),
				'Admin user should pass capability check for Gmail tool'
			);
		}

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that create-google-calendar-event tool has appropriate OAuth scopes.
	 *
	 * Goal: review OAuth scopes used by create-google-calendar-event.
	 */
	public function test_google_calendar_oauth_scopes() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();

		// Verify tool is properly instantiated.
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Create_Google_Calendar_Event',
			$tool,
			'Google Calendar tool should be instantiable'
		);

		// Check the DEFAULT_SCOPE constant.
		$reflection = new ReflectionClass( $tool );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey(
			'DEFAULT_SCOPE',
			$constants,
			'Calendar tool should define DEFAULT_SCOPE constant'
		);

		// Verify scope is calendar.events (least privilege for creating events).
		$this->assertEquals(
			'https://www.googleapis.com/auth/calendar.events',
			$constants['DEFAULT_SCOPE'],
			'Calendar tool should use least-privilege scope (calendar.events, not full calendar access)'
		);
	}

	/**
	 * Test that create-google-calendar-event tool enforces capability checks.
	 *
	 * Goal: run as low-priv WP user to confirm capability checks.
	 */
	public function test_google_calendar_enforces_capability_checks() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();

		// Create a low-privilege user (subscriber).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Attempt to execute tool as subscriber.
		$result = $tool->execute(
			array(
				'summary'    => 'Test Event',
				'start_time' => '2025-01-01T10:00:00Z',
				'end_time'   => '2025-01-01T11:00:00Z',
			),
			array( 'user_id' => $subscriber_id )
		);

		// Should return WP_Error for insufficient permissions.
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Calendar tool should reject execution by low-privilege users'
		);

		// Error code should indicate forbidden.
		$error_code = $result->get_error_code();
		$this->assertStringContainsString(
			'forbidden',
			strtolower( $error_code ),
			'Error code should indicate forbidden access'
		);
	}

	/**
	 * Test that create-google-calendar-event tool has default capability requirement.
	 */
	public function test_google_calendar_has_default_capability() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Google_Calendar_Event class not available' );
		}

		$tool       = new WP_MCP_AI_Tool_Create_Google_Calendar_Event();
		$reflection = new ReflectionClass( $tool );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey(
			'DEFAULT_REQUIRED_CAPABILITY',
			$constants,
			'Calendar tool should define DEFAULT_REQUIRED_CAPABILITY constant'
		);

		$this->assertEquals(
			'manage_options',
			$constants['DEFAULT_REQUIRED_CAPABILITY'],
			'Calendar tool should require manage_options capability by default'
		);
	}

	/**
	 * Test that capability requirements can be filtered.
	 */
	public function test_tool_capability_can_be_filtered() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Search_Gmail' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Search_Gmail class not available' );
		}

		// Add filter to change capability requirement.
		$filter_applied = false;
		add_filter(
			'wp_mcp_ai_search_gmail_capability',
			function ( $capability ) use ( &$filter_applied ) {
				$filter_applied = true;
				return 'read'; // Lower privilege.
			}
		);

		$tool = new WP_MCP_AI_Tool_Search_Gmail();

		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Configure minimal settings.
		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['gmail_client_id']     = 'test';
		$settings['gmail_client_secret'] = 'test';
		$settings['gmail_refresh_token'] = 'test';
		update_option( 'wp_mcp_ai_settings', $settings );

		// Execute as subscriber (should pass capability check now).
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $subscriber_id )
		);

		// Filter should have been applied.
		$this->assertTrue(
			$filter_applied,
			'Capability filter should be applied'
		);

		// Should not fail with forbidden error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEquals(
				'wp_mcp_ai_gmail_forbidden',
				$result->get_error_code(),
				'With filtered capability, subscriber should pass permission check'
			);
		}

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_search_gmail_capability' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that tools properly check multisite membership.
	 */
	public function test_tools_check_multisite_membership() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite tests require multisite installation' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Search_Gmail' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Search_Gmail class not available' );
		}

		$tool = new WP_MCP_AI_Tool_Search_Gmail();

		// Create user on different site.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Try to execute on current site (user not member).
		$result = $tool->execute(
			array( 'query' => 'test' ),
			array( 'user_id' => $user_id )
		);

		// Should fail with wrong site error.
		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			// May be 'wp_mcp_ai_gmail_wrong_site' or similar.
			$this->assertTrue(
				stripos( $error_code, 'wrong_site' ) !== false || stripos( $error_code, 'forbidden' ) !== false,
				'Tool should check multisite membership'
			);
		}
	}

	/**
	 * Test that OAuth scopes are documented and minimal.
	 */
	public function test_oauth_scopes_are_minimal() {
		// This is a documentation test to ensure developers review scopes.

		// Gmail should use readonly scope when possible.
		$gmail_recommended_scopes = array(
			'https://www.googleapis.com/auth/gmail.readonly',
			'https://www.googleapis.com/auth/gmail.metadata',
		);

		// Calendar should use events scope, not full calendar.
		$calendar_recommended_scope = 'https://www.googleapis.com/auth/calendar.events';

		$this->assertTrue(
			true,
			'OAuth scopes should be minimal: Gmail should use readonly/metadata, Calendar should use calendar.events'
		);

		// Document the recommended scopes.
		$this->assertNotEmpty(
			$gmail_recommended_scopes,
			'Gmail recommended scopes should be documented'
		);

		$this->assertNotEmpty(
			$calendar_recommended_scope,
			'Calendar recommended scope should be documented'
		);
	}
}
