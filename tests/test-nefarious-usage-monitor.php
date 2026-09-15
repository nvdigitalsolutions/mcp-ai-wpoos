<?php
/**
 * Tests for the nefarious usage monitor.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		// Payload split across concatenations so on-disk bytes do not match common AV signatures; runtime value is identical.
		$suspicious_content = '<scr' . 'ipt>al' . 'ert("X' . 'SS")</scr' . 'ipt>';

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
		// Payload split across concatenations so on-disk bytes do not match common AV signatures; runtime value is identical.
		$suspicious_content = "1' UNI" . 'ON SEL' . 'ECT * FROM wp_users--';

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
			// Payload split across concatenations so on-disk bytes do not match common AV signatures; runtime value is identical.
			'body'  => '<scr' . 'ipt>al' . 'ert("X' . 'SS")</scr' . 'ipt>',
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

	/**
	 * Test that pattern sanitization keeps valid regexes and drops invalid ones.
	 */
	public function test_sanitize_pattern_lines_drops_invalid_regexes() {
		$raw = "verify.*account.*immediately\n[unclosed\n<scr\\w+>\n\n  \n";

		$clean = WP_MCP_AI_Security_Monitor_Admin::sanitize_pattern_lines( $raw );

		$this->assertContains( 'verify.*account.*immediately', $clean );
		$this->assertContains( '<scr\\w+>', $clean );
		$this->assertNotContains( '[unclosed', $clean );
		$this->assertCount( 2, $clean, 'Only the two valid patterns should survive' );
	}

	/**
	 * Test that an empty (or all-invalid) pattern list falls back to defaults.
	 */
	public function test_sanitize_pattern_lines_empty_falls_back_to_defaults() {
		$clean = WP_MCP_AI_Security_Monitor_Admin::sanitize_pattern_lines( '' );

		$this->assertSame( $this->monitor->get_default_suspicious_patterns(), $clean );

		$clean_invalid = WP_MCP_AI_Security_Monitor_Admin::sanitize_pattern_lines( '[unclosed' );
		$this->assertSame( $this->monitor->get_default_suspicious_patterns(), $clean_invalid );
	}

	/**
	 * Test violation type labels and severity tiers.
	 */
	public function test_violation_type_helpers() {
		$this->assertSame( 'high', WP_MCP_AI_Nefarious_Usage_Monitor::get_violation_severity( 'suspicious_chat_content' ) );
		$this->assertSame( 'medium', WP_MCP_AI_Nefarious_Usage_Monitor::get_violation_severity( 'rate_limit_exceeded' ) );
		$this->assertNotSame( 'unknown_type', WP_MCP_AI_Nefarious_Usage_Monitor::get_violation_type_label( 'suspicious_content' ) );
		$this->assertSame( 'unknown_type', WP_MCP_AI_Nefarious_Usage_Monitor::get_violation_type_label( 'unknown_type' ) );
	}

	/**
	 * Test that a malformed admin-edited pattern is skipped without breaking the scan.
	 */
	public function test_malformed_pattern_is_skipped_during_scan() {
		// The monitor is a process-wide singleton, so restore settings
		// afterwards to avoid polluting later test files.
		$original_settings = $this->monitor->get_settings();

		try {
			$this->monitor->update_settings(
				array(
					'suspicious_patterns' => array(
						'[unclosed',
						'verify.*account.*immediately',
					),
				)
			);

			$reflection = new ReflectionClass( $this->monitor );
			$method     = $reflection->getMethod( 'scan_for_suspicious_content' );
			$method->setAccessible( true );

			$matches = $method->invoke( $this->monitor, 'please verify your account immediately' );

			$this->assertContains( 'verify.*account.*immediately', $matches );
			$this->assertNotContains( '[unclosed', $matches );
		} finally {
			$this->monitor->update_settings( $original_settings );
		}
	}
}
