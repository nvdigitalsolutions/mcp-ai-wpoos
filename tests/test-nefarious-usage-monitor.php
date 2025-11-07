<?php
/**
 * Tests for the nefarious usage monitor.
 *
 * @package WP_MCP_AI
 */

/**
 * Test nefarious usage monitoring functionality.
 */
class WP_MCP_AI_Nefarious_Usage_Monitor_Test extends WP_UnitTestCase {

	/**
	 * Monitor instance.
	 *
	 * @var WP_MCP_AI_Nefarious_Usage_Monitor
	 */
	private $monitor;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();

		// Clear any previous violations.
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::VIOLATIONS_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SHUTDOWN_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION );

		// Reset monitor with default settings.
		$this->monitor->update_settings(
			array(
				'enabled'                 => true,
				'auto_shutdown_enabled'   => true,
				'max_requests_per_minute' => 60,
				'max_tools_per_hour'      => 500,
				'violation_threshold'     => 5,
			)
		);
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::VIOLATIONS_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SHUTDOWN_OPTION );
		delete_option( WP_MCP_AI_Nefarious_Usage_Monitor::SETTINGS_OPTION );
		parent::tearDown();
	}

	/**
	 * Test that monitor initializes with default settings.
	 */
	public function test_monitor_initializes_with_defaults() {
		$settings = $this->monitor->get_settings();

		$this->assertArrayHasKey( 'enabled', $settings );
		$this->assertArrayHasKey( 'auto_shutdown_enabled', $settings );
		$this->assertArrayHasKey( 'max_requests_per_minute', $settings );
		$this->assertArrayHasKey( 'max_tools_per_hour', $settings );
		$this->assertArrayHasKey( 'suspicious_patterns', $settings );
		$this->assertIsArray( $settings['suspicious_patterns'] );
		$this->assertNotEmpty( $settings['suspicious_patterns'] );
	}

	/**
	 * Test that monitor detects suspicious phishing patterns.
	 */
	public function test_detects_phishing_patterns() {
		$suspicious_content = 'Your account has been suspended! Verify your account immediately by clicking here.';

		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
		$method->setAccessible( true );

		$matches = $method->invoke( $this->monitor, $suspicious_content );

		$this->assertNotEmpty( $matches, 'Should detect phishing patterns' );
	}

	/**
	 * Test that monitor detects script injection attempts.
	 */
	public function test_detects_script_injection() {
		$suspicious_content = '<script>alert("XSS")</script>';

		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
		$method->setAccessible( true );

		$matches = $method->invoke( $this->monitor, $suspicious_content );

		$this->assertNotEmpty( $matches, 'Should detect script injection' );
	}

	/**
	 * Test that monitor does not flag normal content.
	 */
	public function test_does_not_flag_normal_content() {
		$normal_content = 'Please update your profile information when you have a chance. Thanks!';

		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
		$method->setAccessible( true );

		$matches = $method->invoke( $this->monitor, $normal_content );

		$this->assertEmpty( $matches, 'Should not flag normal content' );
	}

	/**
	 * Test that violations are recorded.
	 */
	public function test_records_violations() {
		$initial_violations = $this->monitor->get_violations();
		$this->assertEmpty( $initial_violations, 'Should start with no violations' );

		// Trigger a violation by using reflection to call private method.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'record_violation' );
		$method->setAccessible( true );

		$method->invoke(
			$this->monitor,
			'test_violation',
			'Test violation message',
			array( 'test' => 'data' )
		);

		$violations = $this->monitor->get_violations();
		$this->assertCount( 1, $violations, 'Should have one violation recorded' );
		$this->assertEquals( 'test_violation', $violations[0]['type'] );
	}

	/**
	 * Test that emergency shutdown is triggered after threshold violations.
	 */
	public function test_triggers_emergency_shutdown_after_threshold() {
		$this->assertFalse( $this->monitor->is_emergency_shutdown_active(), 'Shutdown should not be active initially' );

		// Trigger multiple violations to exceed threshold.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'record_violation' );
		$method->setAccessible( true );

		// Trigger 5 violations (matches threshold).
		for ( $i = 0; $i < 5; $i++ ) {
			$method->invoke(
				$this->monitor,
				'test_violation_' . $i,
				'Test violation ' . $i,
				array( 'iteration' => $i )
			);
		}

		$this->assertTrue( $this->monitor->is_emergency_shutdown_active(), 'Shutdown should be active after threshold violations' );
	}

	/**
	 * Test that emergency shutdown blocks tool execution.
	 */
	public function test_shutdown_blocks_tool_execution() {
		// Trigger shutdown.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke(
			$this->monitor,
			array(
				'type'    => 'test',
				'message' => 'Test shutdown',
			)
		);

		// Attempt to execute tool.
		$can_execute = $this->monitor->check_tool_execution( true, 'test_tool', array() );

		$this->assertFalse( $can_execute, 'Tool execution should be blocked during shutdown' );
	}

	/**
	 * Test that shutdown can be cleared.
	 */
	public function test_can_clear_emergency_shutdown() {
		// Trigger shutdown.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'trigger_emergency_shutdown' );
		$method->setAccessible( true );
		$method->invoke(
			$this->monitor,
			array(
				'type'    => 'test',
				'message' => 'Test shutdown',
			)
		);

		$this->assertTrue( $this->monitor->is_emergency_shutdown_active() );

		// Clear shutdown.
		$this->monitor->clear_emergency_shutdown();

		$this->assertFalse( $this->monitor->is_emergency_shutdown_active(), 'Shutdown should be cleared' );
	}

	/**
	 * Test that violations can be cleared.
	 */
	public function test_can_clear_violations() {
		// Add some violations.
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'record_violation' );
		$method->setAccessible( true );

		$method->invoke( $this->monitor, 'test', 'Test violation', array() );

		$violations = $this->monitor->get_violations();
		$this->assertNotEmpty( $violations, 'Should have violations' );

		// Clear violations.
		$this->monitor->clear_violations();

		$violations = $this->monitor->get_violations();
		$this->assertEmpty( $violations, 'Violations should be cleared' );
	}

	/**
	 * Test that settings can be updated.
	 */
	public function test_can_update_settings() {
		$new_settings = array(
			'enabled'                 => false,
			'max_requests_per_minute' => 120,
		);

		$this->monitor->update_settings( $new_settings );

		$settings = $this->monitor->get_settings();
		$this->assertFalse( $settings['enabled'], 'Enabled should be updated' );
		$this->assertEquals( 120, $settings['max_requests_per_minute'], 'Max requests should be updated' );
	}

	/**
	 * Test that monitor detects SQL injection patterns.
	 */
	public function test_detects_sql_injection() {
		$suspicious_content = "1' UNION SELECT * FROM wp_users--";

		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
		$method->setAccessible( true );

		$matches = $method->invoke( $this->monitor, $suspicious_content );

		$this->assertNotEmpty( $matches, 'Should detect SQL injection attempt' );
	}

	/**
	 * Test that monitor scans array content for suspicious patterns.
	 */
	public function test_scans_array_content() {
		$suspicious_array = array(
			'email' => 'user@example.com',
			'body'  => '<script>alert("XSS")</script>',
		);

		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
		$method->setAccessible( true );

		$matches = $method->invoke( $this->monitor, $suspicious_array );

		$this->assertNotEmpty( $matches, 'Should detect suspicious patterns in arrays' );
	}

	/**
	 * Test that disabled monitor does not interfere.
	 */
	public function test_disabled_monitor_does_not_interfere() {
		$this->monitor->update_settings( array( 'enabled' => false ) );

		// Should not block tool execution when disabled.
		$can_execute = $this->monitor->check_tool_execution( true, 'test_tool', array() );
		$this->assertTrue( $can_execute, 'Disabled monitor should not block tools' );
	}

	/**
	 * Test that recent violations are counted correctly.
	 */
	public function test_counts_recent_violations() {
		$reflection = new ReflectionClass( $this->monitor );
		$method     = $reflection->getMethod( 'record_violation' );
		$method->setAccessible( true );

		// Add 3 violations.
		for ( $i = 0; $i < 3; $i++ ) {
			$method->invoke( $this->monitor, 'test', 'Test violation', array() );
		}

		$count_method = $reflection->getMethod( 'count_recent_violations' );
		$count_method->setAccessible( true );

		$recent_count = $count_method->invoke( $this->monitor, 60 );
		$this->assertEquals( 3, $recent_count, 'Should count 3 recent violations' );
	}
}
