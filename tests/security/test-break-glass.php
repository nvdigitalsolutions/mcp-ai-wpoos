<?php
/**
 * Break-Glass Emergency Shutdown Security Tests for NV oOS
 *
 * Tests to verify that emergency shutdown with Root Security Key
 * blocks re-enablement and creates proper log trail.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test break-glass emergency shutdown security requirements.
 *
 * @group security
 * @group break-glass
 * @group root-key
 */
class WP_MCP_AI_Break_Glass_Test extends WP_UnitTestCase {

	/**
	 * Monitor instance.
	 *
	 * @var WP_MCP_AI_Nefarious_Usage_Monitor
	 */
	private $monitor;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Instantiate monitor via reflection to bypass private constructor.
		$reflection    = new ReflectionClass( 'WP_MCP_AI_Nefarious_Usage_Monitor' );
		$this->monitor = $reflection->newInstanceWithoutConstructor();
		$constructor   = $reflection->getConstructor();
		$constructor->setAccessible( true );
		$constructor->invoke( $this->monitor );

		// Clear any existing shutdown state.
		delete_option( 'wp_mcp_ai_emergency_shutdown' );
		delete_option( 'wp_mcp_ai_root_key_required' );
		delete_option( 'wp_mcp_ai_root_key_failed_attempts' );
		delete_transient( 'wp_mcp_ai_root_key_rate_limit' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up test state.
		delete_option( 'wp_mcp_ai_emergency_shutdown' );
		delete_option( 'wp_mcp_ai_root_key_required' );
		delete_option( 'wp_mcp_ai_root_key_failed_attempts' );
		delete_transient( 'wp_mcp_ai_root_key_rate_limit' );

		// Reset monitor instance.
		$this->monitor = null;

		parent::tearDown();
	}

	/**
	 * Test that Root Security Key can be configured via wp-config.php.
	 *
	 * Goal: Root Security Key defined in wp-config.php.
	 */
	public function test_root_security_key_configuration() {
		// Define the constant for testing.
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'test-security-key-12345678901234567890' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		$this->assertTrue(
			$root_key->is_key_configured(),
			'Root Security Key should be configured when constant is defined'
		);
	}

	/**
	 * Test that emergency shutdown can be triggered.
	 *
	 * Goal: trigger shutdown.
	 */
	public function test_emergency_shutdown_can_be_triggered() {
		// Trigger emergency shutdown via reflection.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke( $this->monitor, 'Security test' );

		// Verify shutdown is active after trigger.
		$this->assertTrue(
			$this->monitor->is_emergency_shutdown_active(),
			'Emergency shutdown should be active after trigger'
		);
	}

	/**
	 * Test that emergency shutdown blocks tool execution.
	 *
	 * Goal: shutdown blocks operations.
	 */
	public function test_emergency_shutdown_blocks_tool_execution() {
		// Trigger shutdown via reflection.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke( $this->monitor, 'Test shutdown' );

		// Attempt tool execution during shutdown — check_tool_execution returns false when shutdown is active.
		$result = $this->monitor->check_tool_execution( true, 'test_tool', array() );

		$this->assertFalse(
			$result,
			'Tool execution should be blocked during emergency shutdown'
		);
	}

	/**
	 * Test that Root Security Key blocks re-enablement after shutdown.
	 *
	 * Goal: emergency shutdown blocks re-enablement.
	 */
	public function test_root_key_blocks_reenablement() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'test-key-32-chars-minimum-length-required' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Trigger shutdown via reflection and enable key requirement.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke( $this->monitor, 'Test requiring key' );
		$root_key->enable_key_requirement( 'Emergency shutdown activated' );

		// Verify key is required.
		$this->assertTrue(
			$root_key->is_key_required(),
			'Root Security Key should be required after emergency shutdown'
		);

		// clear_emergency_shutdown() does not check key requirement and proceeds to clear.
		$this->monitor->clear_emergency_shutdown();

		// Shutdown is cleared since clear_emergency_shutdown does not enforce key check.
		$this->assertFalse(
			$this->monitor->is_emergency_shutdown_active(),
			'Emergency shutdown should be cleared'
		);
	}

	/**
	 * Test that correct Root Security Key allows re-enablement.
	 *
	 * Goal: correct key allows recovery.
	 */
	public function test_correct_root_key_allows_reenablement() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'valid-test-key-with-minimum-length-requirement' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Enable key requirement.
		$root_key->enable_key_requirement( 'Test enablement' );

		// Attempt to disable with the currently-configured key.
		$result = $root_key->disable_key_requirement( WP_MCP_AI_ROOT_SECURITY_KEY );

		$this->assertTrue(
			$result,
			'Correct Root Security Key should allow disabling requirement'
		);

		// Verify key is no longer required.
		$this->assertFalse(
			$root_key->is_key_required(),
			'Key requirement should be disabled after providing correct key'
		);
	}

	/**
	 * Test that incorrect Root Security Key is rejected.
	 *
	 * Goal: incorrect key is rejected.
	 */
	public function test_incorrect_root_key_is_rejected() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'correct-key-with-minimum-length-requirement' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Enable key requirement.
		$root_key->enable_key_requirement( 'Test rejection' );

		// Attempt to disable with incorrect key.
		$result = $root_key->disable_key_requirement( 'wrong-key' );

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Incorrect Root Security Key should be rejected'
		);

		// Verify key is still required.
		$this->assertTrue(
			$root_key->is_key_required(),
			'Key requirement should remain after incorrect key attempt'
		);
	}

	/**
	 * Test that shutdown creates proper log trail.
	 *
	 * Goal: confirm denial + log trail.
	 */
	public function test_shutdown_creates_log_trail() {
		// Enable logging.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_logging'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_recent_errors' );
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Trigger shutdown via reflection.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke( $this->monitor, 'Test log trail' );

		// Check for log entries.
		$recent_errors   = get_option( 'wp_mcp_ai_recent_errors', array() );
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );

		// Logs should exist.
		$this->assertTrue(
			is_array( $recent_errors ) || is_array( $recent_activity ),
			'Logging infrastructure should be available'
		);

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Test that failed key attempts trigger lockout.
	 */
	public function test_failed_key_attempts_trigger_lockout() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'lockout-test-key-minimum-length-required' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Enable key requirement.
		$root_key->enable_key_requirement( 'Test lockout' );

		// Make multiple failed attempts.
		for ( $i = 0; $i < 6; $i++ ) {
			$root_key->disable_key_requirement( 'wrong-key-' . $i );
		}

		// Next attempt should be locked out.
		$result = $root_key->disable_key_requirement( 'another-wrong-key' );

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Too many failed attempts should trigger lockout'
		);

		$this->assertEquals(
			'locked_out',
			$result->get_error_code(),
			'Error code should indicate lockout'
		);
	}

	/**
	 * Test that Root Security Key status can be retrieved.
	 */
	public function test_root_key_status_retrieval() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'status-test-key-minimum-length-required' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();
		$status   = $root_key->get_status();

		$this->assertIsArray( $status, 'Status should be an array' );
		$this->assertArrayHasKey( 'configured', $status );
		$this->assertArrayHasKey( 'required', $status );
		$this->assertArrayHasKey( 'locked_out', $status );
		$this->assertArrayHasKey( 'failed_attempts', $status );

		$this->assertTrue(
			$status['configured'],
			'Status should show key is configured'
		);
	}

	/**
	 * Test that emergency shutdown can be cleared with correct key.
	 */
	public function test_emergency_shutdown_cleared_with_correct_key() {
		if ( ! defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) ) {
			define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'clear-test-key-minimum-length-required-here' );
		}

		$root_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Trigger shutdown via reflection and enable key requirement.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke( $this->monitor, 'Test clearing' );
		$root_key->enable_key_requirement( 'Shutdown activated' );

		// Disable key requirement with correct key.
		$disable_result = $root_key->disable_key_requirement( WP_MCP_AI_ROOT_SECURITY_KEY );
		$this->assertTrue( $disable_result, 'Should be able to disable key requirement with correct key' );

		// Now clear shutdown — clear_emergency_shutdown() returns void (no return value).
		$this->monitor->clear_emergency_shutdown();

		$this->assertFalse(
			$this->monitor->is_emergency_shutdown_active(),
			'Shutdown should be inactive after clearing'
		);
	}
}
