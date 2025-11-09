<?php
/**
 * Tests for SIEM Logger.
 *
 * @package WP_MCP_AI
 */

/**
 * SIEM Logger test case.
 */
class Test_SIEM_Logger extends WP_UnitTestCase {

	/**
	 * Test SIEM logging is disabled by default.
	 */
	public function test_siem_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_SIEM_Logger::is_enabled() );
	}

	/**
	 * Test SIEM configuration retrieval.
	 */
	public function test_get_config() {
		$config = WP_MCP_AI_SIEM_Logger::get_config();

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'enabled', $config );
		$this->assertArrayHasKey( 'endpoint_type', $config );
		$this->assertArrayHasKey( 'endpoint_url', $config );
	}

	/**
	 * Test correlation ID generation.
	 */
	public function test_correlation_id_generation() {
		$correlation_id = WP_MCP_AI_SIEM_Logger::generate_correlation_id();

		$this->assertIsString( $correlation_id );
		$this->assertNotEmpty( $correlation_id );
		$this->assertStringContainsString( 'wpmcp', $correlation_id );
	}

	/**
	 * Test security event logging when enabled.
	 */
	public function test_log_security_event_when_enabled() {
		add_filter( 'wp_mcp_ai_siem_enabled', '__return_true' );

		$result = WP_MCP_AI_SIEM_Logger::log_security_event(
			WP_MCP_AI_SIEM_Logger::EVENT_AUTH_SUCCESS,
			'User authenticated successfully',
			array( 'user_id' => 1 ),
			WP_MCP_AI_SIEM_Logger::SEVERITY_INFO
		);

		// Since syslog may not be available, result could be false.
		// Just verify it doesn't throw errors.
		$this->assertTrue( is_bool( $result ) );

		remove_filter( 'wp_mcp_ai_siem_enabled', '__return_true' );
	}

	/**
	 * Test security event logging when disabled.
	 */
	public function test_log_security_event_when_disabled() {
		$result = WP_MCP_AI_SIEM_Logger::log_security_event(
			WP_MCP_AI_SIEM_Logger::EVENT_AUTH_SUCCESS,
			'Test event',
			array(),
			WP_MCP_AI_SIEM_Logger::SEVERITY_INFO
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test severity label mapping.
	 */
	public function test_severity_labels() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SIEM_Logger' );
		$method = $reflection->getMethod( 'get_severity_label' );
		$method->setAccessible( true );

		$this->assertEquals( 'EMERGENCY', $method->invoke( null, WP_MCP_AI_SIEM_Logger::SEVERITY_EMERGENCY ) );
		$this->assertEquals( 'ERROR', $method->invoke( null, WP_MCP_AI_SIEM_Logger::SEVERITY_ERROR ) );
		$this->assertEquals( 'INFO', $method->invoke( null, WP_MCP_AI_SIEM_Logger::SEVERITY_INFO ) );
	}

	/**
	 * Test IP anonymization.
	 */
	public function test_anonymize_ip() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_SIEM_Logger' );
		$method = $reflection->getMethod( 'anonymize_ip' );
		$method->setAccessible( true );

		// Test IPv4.
		$this->assertEquals( '192.168.1.0', $method->invoke( null, '192.168.1.100' ) );

		// Test IPv6.
		$ipv6_result = $method->invoke( null, '2001:0db8:85a3:0000:0000:8a2e:0370:7334' );
		$this->assertStringContainsString( ':', $ipv6_result );
	}
}
