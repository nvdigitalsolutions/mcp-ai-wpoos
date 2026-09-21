<?php
/**
 * Tests that the Security Center Usage Monitor configuration persists
 * through the dashboard save flow.
 *
 * The usage_monitor sub-tab fields are bridged to the monitor's own
 * option via the 'wp_mcp_ai_admin_settings_sanitize' filter. This test
 * pins the regression where the dashboard's admin-post save handler
 * never applied that filter, silently dropping the submitted config.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Usage_Monitor_Settings_Save_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an admin user and log them in.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// The bridge filter lives in the admin-only loader (is_admin() gate),
		// which never runs under PHPUnit — register it explicitly so the
		// save-flow bridge is exercised.
		if ( ! class_exists( 'WP_MCP_AI_Security_Monitor_Admin' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-security-monitor-admin.php';
		}
		WP_MCP_AI_Security_Monitor_Admin::init();

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION );

		// Seed the monitor singleton with a known state so assertions do not
		// depend on leftover in-memory values from other test classes. Use the
		// real default pattern set — other suites rely on the singleton keeping
		// its default suspicious_patterns (shared-singleton isolation).
		WP_MCP_AI_Nefarious_Usage_Monitor::get_instance()->update_settings(
			array(
				'enabled'                 => false,
				'auto_shutdown_enabled'   => false,
				'max_requests_per_minute' => 10,
				'max_tools_per_hour'      => 10,
				'violation_threshold'     => 1,
				'suspicious_patterns'     => WP_MCP_AI_Nefarious_Usage_Monitor::get_instance()->get_default_suspicious_patterns(),
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Restore the singleton's in-memory settings (patterns included) so
		// later suites that rely on defaults are not polluted, then drop the
		// options.
		$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();
		$monitor->update_settings(
			array(
				'enabled'                 => true,
				'auto_shutdown_enabled'   => true,
				'max_requests_per_minute' => WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_REQUESTS_PER_MINUTE,
				'max_tools_per_hour'      => WP_MCP_AI_Nefarious_Usage_Monitor::DEFAULT_MAX_TOOLS_PER_HOUR,
				'violation_threshold'     => 5,
				'suspicious_patterns'     => $monitor->get_default_suspicious_patterns(),
			)
		);

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::VIOLATIONS_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SHUTDOWN_OPTION );
		parent::tearDown();
	}

	/**
	 * Simulate the dashboard admin-post save and capture redirects.
	 *
	 * @param array $posted_settings Value for $_POST['wp_mcp_ai_settings'].
	 */
	private function submit_save( $posted_settings ) {
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'             => 'wp_mcp_ai_save_settings',
			'active_tab'         => 'security',
			'subtab_security'    => 'usage_monitor',
			'wp_mcp_ai_settings' => $posted_settings,
		);

		// check_admin_referer reads $_REQUEST, which does not merge POST data
		// under the test bootstrap — mirror the nonce there explicitly.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Test fixture for the save handler's nonce check.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		// Set SERVER variables needed for nonce verification.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Capture redirect to prevent actual redirect.
		add_filter( 'wp_redirect', '__return_false' );

		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			// Expected to throw exception due to exit/die, ignore it.
			unset( $e );
		}
	}

	/**
	 * Saving the usage_monitor sub-tab must persist the monitor's own option.
	 */
	public function test_usage_monitor_save_persists_monitor_settings() {
		$this->submit_save(
			array(
				'wp_mcp_ai_security_monitor_enabled'       => '1',
				'wp_mcp_ai_security_monitor_auto_shutdown' => '1',
				'wp_mcp_ai_security_monitor_max_requests_per_minute' => '120',
				'wp_mcp_ai_security_monitor_max_tools_per_hour' => '750',
				'wp_mcp_ai_security_monitor_violation_threshold' => '7',
				'wp_mcp_ai_security_monitor_patterns'      => "verify.*account.*immediately\nurgent.*action.*required",
			)
		);

		$monitor_settings = get_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION, array() );

		$this->assertTrue( ! empty( $monitor_settings['enabled'] ), 'Monitor should be enabled after save' );
		$this->assertTrue( ! empty( $monitor_settings['auto_shutdown_enabled'] ), 'Auto-shutdown should be enabled after save' );
		$this->assertSame( 120, $monitor_settings['max_requests_per_minute'] );
		$this->assertSame( 750, $monitor_settings['max_tools_per_hour'] );
		$this->assertSame( 7, $monitor_settings['violation_threshold'] );
		$this->assertSame(
			array( 'verify.*account.*immediately', 'urgent.*action.*required' ),
			$monitor_settings['suspicious_patterns']
		);

		// The bridged fields must NOT leak into the main settings option.
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertArrayNotHasKey( 'wp_mcp_ai_security_monitor_enabled', $settings );
	}

	/**
	 * Unchecking the monitor (dashboard JS posts value 0) must disable it.
	 */
	public function test_usage_monitor_save_can_disable_monitor() {
		// Start from an enabled monitor.
		WP_MCP_AI_Nefarious_Usage_Monitor::get_instance()->update_settings( array( 'enabled' => true ) );

		$this->submit_save(
			array(
				// The dashboard JS appends a hidden field with value 0 for
				// unchecked checkboxes before submitting the form.
				'wp_mcp_ai_security_monitor_enabled' => '0',
			)
		);

		$monitor_settings = get_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION, array() );
		$this->assertTrue( empty( $monitor_settings['enabled'] ), 'Monitor should be disabled after unchecking' );
	}

	/**
	 * Unrelated saves must not touch the monitor settings.
	 */
	public function test_unrelated_save_does_not_flip_monitor() {
		// Start from an enabled monitor.
		WP_MCP_AI_Nefarious_Usage_Monitor::get_instance()->update_settings( array( 'enabled' => true ) );

		// Save a different tab entirely.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		$_POST = array(
			'_wpnonce'           => wp_create_nonce( 'wp_mcp_ai_save_settings' ),
			'action'             => 'wp_mcp_ai_save_settings',
			'active_tab'         => 'general',
			'subtab_general'     => 'logs',
			'wp_mcp_ai_settings' => array(
				'enable_logging' => '1',
			),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Test fixture for the save handler's nonce check.
		$_REQUEST['_wpnonce']      = $_POST['_wpnonce'];
		$_SERVER['REQUEST_METHOD'] = 'POST';
		add_filter( 'wp_redirect', '__return_false' );

		try {
			$dashboard->handle_save_settings();
		} catch ( Exception $e ) {
			unset( $e );
		}

		$monitor_settings = get_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION, array() );
		$this->assertTrue( ! empty( $monitor_settings['enabled'] ), 'Unrelated saves must not disable the monitor' );
	}
}
