<?php
/**
 * Tests for PII Detector.
 *
 * @package WP_MCP_AI
 */

/**
 * PII Detector test case.
 */
class Test_PII_Detector extends WP_UnitTestCase {

	/**
	 * Test email detection.
	 */
	public function test_detect_email() {
		$text = 'Contact me at user@example.com for details.';
		$detected = WP_MCP_AI_PII_Detector::detect( $text );

		$this->assertContains( 'email', $detected );
	}

	/**
	 * Test phone number detection.
	 */
	public function test_detect_phone() {
		$text = 'Call me at 555-123-4567.';
		$detected = WP_MCP_AI_PII_Detector::detect( $text );

		$this->assertContains( 'phone_us', $detected );
	}

	/**
	 * Test SSN detection.
	 */
	public function test_detect_ssn() {
		$text = 'SSN: 123-45-6789';
		$detected = WP_MCP_AI_PII_Detector::detect( $text );

		$this->assertContains( 'ssn', $detected );
	}

	/**
	 * Test email redaction.
	 */
	public function test_redact_email() {
		$text = 'Email me at user@example.com';
		$redacted = WP_MCP_AI_PII_Detector::redact( $text );

		$this->assertStringNotContainsString( 'user@example.com', $redacted );
		$this->assertStringContainsString( '[EMAIL_REDACTED]', $redacted );
	}

	/**
	 * Test phone redaction.
	 */
	public function test_redact_phone() {
		$text = 'Call 555-123-4567';
		$redacted = WP_MCP_AI_PII_Detector::redact( $text );

		$this->assertStringNotContainsString( '555-123-4567', $redacted );
		$this->assertStringContainsString( '[PHONE_REDACTED]', $redacted );
	}

	/**
	 * Test partial email redaction.
	 */
	public function test_partial_redact_email() {
		$email = 'testuser@example.com';
		$redacted = WP_MCP_AI_PII_Detector::partial_redact_email( $email );

		$this->assertStringContainsString( 'te', $redacted ); // First 2 chars.
		$this->assertStringContainsString( '@example.com', $redacted );
		$this->assertStringContainsString( '*', $redacted );
	}

	/**
	 * Test partial phone redaction.
	 */
	public function test_partial_redact_phone() {
		$phone = '5551234567';
		$redacted = WP_MCP_AI_PII_Detector::partial_redact_phone( $phone );

		$this->assertStringContainsString( '4567', $redacted ); // Last 4 digits.
		$this->assertStringContainsString( '*', $redacted );
	}

	/**
	 * Test array redaction.
	 */
	public function test_redact_array() {
		$data = array(
			'name'    => 'John Doe',
			'email'   => 'john@example.com',
			'phone'   => '555-123-4567',
			'nested'  => array(
				'contact' => 'jane@example.com',
			),
		);

		$redacted = WP_MCP_AI_PII_Detector::redact_array( $data );

		$this->assertEquals( 'John Doe', $redacted['name'] ); // Not PII pattern.
		$this->assertStringContainsString( '[EMAIL_REDACTED]', $redacted['email'] );
		$this->assertStringContainsString( '[PHONE_REDACTED]', $redacted['phone'] );
		$this->assertStringContainsString( '[EMAIL_REDACTED]', $redacted['nested']['contact'] );
	}

	/**
	 * Test sensitive key redaction.
	 */
	public function test_redact_sensitive_keys() {
		$data = array(
			'username' => 'john',
			'password' => 'secret123',
			'api_key'  => 'sk-abc123',
			'email'    => 'john@example.com',
		);

		$redacted = WP_MCP_AI_PII_Detector::redact_sensitive_keys( $data );

		$this->assertEquals( 'john', $redacted['username'] );
		$this->assertEquals( '[REDACTED]', $redacted['password'] );
		$this->assertEquals( '[REDACTED]', $redacted['api_key'] );
		$this->assertEquals( 'john@example.com', $redacted['email'] ); // Not a sensitive key name.
	}

	/**
	 * Test PII detection report.
	 */
	public function test_get_detection_report() {
		$text = 'Contact: user@example.com or 555-123-4567';
		$report = WP_MCP_AI_PII_Detector::get_detection_report( $text );

		$this->assertTrue( $report['has_pii'] );
		$this->assertGreaterThan( 0, $report['count'] );
		$this->assertArrayHasKey( 'email', $report['types'] );
		$this->assertArrayHasKey( 'phone_us', $report['types'] );
	}

	/**
	 * Test contains PII check.
	 */
	public function test_contains_pii() {
		$this->assertTrue( WP_MCP_AI_PII_Detector::contains_pii( 'Email: test@example.com' ) );
		$this->assertFalse( WP_MCP_AI_PII_Detector::contains_pii( 'Just some text' ) );
	}

	/**
	 * Test sanitize for logging.
	 */
	public function test_sanitize_for_logging() {
		$text = 'Password: secret123 Email: user@example.com';
		$sanitized = WP_MCP_AI_PII_Detector::sanitize_for_logging( $text );

		$this->assertStringContainsString( '[EMAIL_REDACTED]', $sanitized );
		$this->assertStringContainsString( '[PASSWORD_REDACTED]', $sanitized );
	}
}
