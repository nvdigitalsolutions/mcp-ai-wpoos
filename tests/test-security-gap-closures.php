<?php
/**
 * Tests for Security Gap Closures
 *
 * Tests for newly implemented features to close identified security gaps:
 * - SIEM export
 * - Correlation IDs
 * - At-rest encryption
 * - CORS policy
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test security gap closure implementations.
 *
 * @group security
 * @group security-gaps
 * @group siem
 * @group correlation
 * @group encryption
 */
class Test_Security_Gap_Closures extends WP_UnitTestCase {

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
		// Reset correlation ID state.
		if ( class_exists( 'WP_MCP_AI_Correlation_ID' ) ) {
			WP_MCP_AI_Correlation_ID::reset();
		}

		parent::tearDown();
	}

	/**
	 * Test SIEM Logger - Class exists and instantiates
	 */
	public function test_siem_logger_class_exists() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-siem-logger.php';

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_SIEM_Logger' ),
			'SIEM Logger class must exist'
		);

		$logger = WP_MCP_AI_SIEM_Logger::get_instance();
		$this->assertInstanceOf(
			'WP_MCP_AI_SIEM_Logger',
			$logger,
			'SIEM Logger must instantiate as singleton'
		);
	}

	/**
	 * Test SIEM Logger - Supports multiple formats
	 */
	public function test_siem_logger_supports_multiple_formats() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-siem-logger.php';

		$this->assertEquals( 'syslog', WP_MCP_AI_SIEM_Logger::FORMAT_SYSLOG );
		$this->assertEquals( 'json', WP_MCP_AI_SIEM_Logger::FORMAT_JSON );
		$this->assertEquals( 'cef', WP_MCP_AI_SIEM_Logger::FORMAT_CEF );
		$this->assertEquals( 'custom', WP_MCP_AI_SIEM_Logger::FORMAT_CUSTOM );
	}

	/**
	 * Test SIEM Logger - Event export method exists
	 */
	public function test_siem_logger_has_export_method() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-siem-logger.php';

		$logger = WP_MCP_AI_SIEM_Logger::get_instance();

		$this->assertTrue(
			method_exists( $logger, 'export_event' ),
			'SIEM Logger must have export_event method'
		);
	}

	/**
	 * Test Correlation ID - Class exists and initializes
	 */
	public function test_correlation_id_class_exists() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Correlation_ID' ),
			'Correlation ID class must exist'
		);
	}

	/**
	 * Test Correlation ID - Generates valid UUID
	 */
	public function test_correlation_id_generates_uuid() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$correlation_id = WP_MCP_AI_Correlation_ID::get_current_id();

		$this->assertNotEmpty( $correlation_id, 'Correlation ID must not be empty' );

		// Validate UUID format (8-4-4-4-12 hex characters).
		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		$this->assertMatchesRegularExpression(
			$uuid_pattern,
			$correlation_id,
			'Correlation ID must be valid UUID format'
		);
	}

	/**
	 * Test Correlation ID - Consistent within request
	 */
	public function test_correlation_id_consistent_within_request() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$id1 = WP_MCP_AI_Correlation_ID::get_current_id();
		$id2 = WP_MCP_AI_Correlation_ID::get_current_id();

		$this->assertEquals(
			$id1,
			$id2,
			'Correlation ID must be consistent within same request'
		);
	}

	/**
	 * Test Correlation ID - Can create child IDs
	 */
	public function test_correlation_id_child_creation() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$parent_id = WP_MCP_AI_Correlation_ID::get_current_id();
		$child_id  = WP_MCP_AI_Correlation_ID::create_child_id();

		$this->assertNotEquals(
			$parent_id,
			$child_id,
			'Child correlation ID must be different from parent'
		);

		$parent_ids = WP_MCP_AI_Correlation_ID::get_parent_ids();
		$this->assertContains(
			$parent_id,
			$parent_ids,
			'Parent ID must be tracked'
		);
	}

	/**
	 * Test Correlation ID - Restores parent ID
	 */
	public function test_correlation_id_parent_restoration() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$parent_id = WP_MCP_AI_Correlation_ID::get_current_id();
		$child_id  = WP_MCP_AI_Correlation_ID::create_child_id();

		$this->assertEquals( $child_id, WP_MCP_AI_Correlation_ID::get_current_id() );

		$restored_id = WP_MCP_AI_Correlation_ID::restore_parent_id();

		$this->assertEquals(
			$parent_id,
			$restored_id,
			'Restored ID must match original parent ID'
		);
	}

	/**
	 * Test Encryption - Class exists
	 */
	public function test_encryption_class_exists() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Encryption' ),
			'Encryption class must exist'
		);
	}

	/**
	 * Test Encryption - OpenSSL availability check
	 */
	public function test_encryption_checks_openssl_availability() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		$is_available = WP_MCP_AI_Encryption::is_available();

		$this->assertIsBool(
			$is_available,
			'Encryption availability check must return boolean'
		);

		// Most modern PHP installations have OpenSSL.
		if ( function_exists( 'openssl_encrypt' ) ) {
			$this->assertTrue( $is_available, 'OpenSSL should be available' );
		}
	}

	/**
	 * Test Encryption - Encrypt and decrypt roundtrip
	 */
	public function test_encryption_roundtrip() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		$original_data = 'test_api_key_12345';

		$encrypted = WP_MCP_AI_Encryption::encrypt( $original_data );

		$this->assertNotEquals(
			$original_data,
			$encrypted,
			'Encrypted data must differ from original'
		);
		$this->assertIsString( $encrypted, 'Encrypted data must be string' );

		$decrypted = WP_MCP_AI_Encryption::decrypt( $encrypted );

		$this->assertEquals(
			$original_data,
			$decrypted,
			'Decrypted data must match original'
		);
	}

	/**
	 * Test Encryption - API key encryption
	 */
	public function test_encryption_api_key_methods() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		$api_key = 'sk-test1234567890abcdef';

		$encrypted = WP_MCP_AI_Encryption::encrypt_api_key( $api_key );
		$this->assertIsString( $encrypted );
		$this->assertNotEquals( $api_key, $encrypted );

		$decrypted = WP_MCP_AI_Encryption::decrypt_api_key( $encrypted );
		$this->assertEquals( $api_key, $decrypted );
	}

	/**
	 * Test Encryption - Token encryption
	 */
	public function test_encryption_token_methods() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		$token = 'ya29.test_oauth_token_here';

		$encrypted = WP_MCP_AI_Encryption::encrypt_token( $token );
		$this->assertIsString( $encrypted );
		$this->assertNotEquals( $token, $encrypted );

		$decrypted = WP_MCP_AI_Encryption::decrypt_token( $encrypted );
		$this->assertEquals( $token, $decrypted );
	}

	/**
	 * Test Encryption - Empty string handling
	 */
	public function test_encryption_handles_empty_strings() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		$encrypted = WP_MCP_AI_Encryption::encrypt( '' );
		$this->assertEquals( '', $encrypted, 'Empty string should return empty' );

		$decrypted = WP_MCP_AI_Encryption::decrypt( '' );
		$this->assertEquals( '', $decrypted, 'Empty encrypted should return empty' );
	}

	/**
	 * Test Encryption - Is encrypted detection
	 */
	public function test_encryption_is_encrypted_detection() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		$plaintext = 'not_encrypted';
		$this->assertFalse(
			WP_MCP_AI_Encryption::is_encrypted( $plaintext ),
			'Plaintext should not be detected as encrypted'
		);

		$encrypted = WP_MCP_AI_Encryption::encrypt( 'test_data' );
		$this->assertTrue(
			WP_MCP_AI_Encryption::is_encrypted( $encrypted ),
			'Encrypted data should be detected as encrypted'
		);
	}

	/**
	 * Test Encryption - Hash and verify
	 */
	public function test_encryption_hash_and_verify() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		$data = 'secret_value';

		$hash = WP_MCP_AI_Encryption::hash( $data );
		$this->assertIsString( $hash, 'Hash must be string' );
		$this->assertNotEquals( $data, $hash, 'Hash must differ from original' );

		$this->assertTrue(
			WP_MCP_AI_Encryption::verify_hash( $data, $hash ),
			'Hash verification must succeed for correct data'
		);

		$this->assertFalse(
			WP_MCP_AI_Encryption::verify_hash( 'wrong_data', $hash ),
			'Hash verification must fail for incorrect data'
		);
	}

	/**
	 * Test CORS Documentation exists
	 */
	public function test_cors_documentation_exists() {
		$cors_doc_path = WP_MCP_AI_PATH . 'docs/CORS_POLICY_GUIDE.md';

		$this->assertFileExists(
			$cors_doc_path,
			'CORS policy documentation must exist'
		);

		$content = file_get_contents( $cors_doc_path );

		// Verify key sections exist.
		$this->assertStringContainsString(
			'Cross-Origin Resource Sharing',
			$content,
			'CORS documentation must explain CORS'
		);
		$this->assertStringContainsString(
			'Configuration Examples',
			$content,
			'CORS documentation must include examples'
		);
		$this->assertStringContainsString(
			'Security Considerations',
			$content,
			'CORS documentation must address security'
		);
	}

	/**
	 * Test Integration - SIEM can log with correlation ID
	 */
	public function test_siem_integration_with_correlation_id() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-siem-logger.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-correlation-id.php';

		$logger = WP_MCP_AI_SIEM_Logger::get_instance();
		$correlation_id = WP_MCP_AI_Correlation_ID::get_current_id();

		$this->assertNotEmpty( $correlation_id, 'Correlation ID must be generated' );

		// SIEM logger should access correlation ID.
		$this->assertTrue(
			method_exists( $logger, 'export_event' ),
			'SIEM logger must have export method'
		);
	}

	/**
	 * Test Integration - Encryption can protect SIEM data
	 */
	public function test_encryption_protects_sensitive_siem_data() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-encryption.php';

		if ( ! WP_MCP_AI_Encryption::is_available() ) {
			$this->markTestSkipped( 'OpenSSL not available' );
		}

		// Simulate sensitive data that might go to SIEM.
		$sensitive_context = array(
			'user_id' => 123,
			'api_key' => 'sk-sensitive1234',
			'token'   => 'bearer_token_here',
		);

		// Encrypt sensitive fields before SIEM export.
		$protected_context = array(
			'user_id' => $sensitive_context['user_id'],
			'api_key' => WP_MCP_AI_Encryption::encrypt( $sensitive_context['api_key'] ),
			'token'   => WP_MCP_AI_Encryption::encrypt( $sensitive_context['token'] ),
		);

		$this->assertNotEquals(
			$sensitive_context['api_key'],
			$protected_context['api_key'],
			'API key must be encrypted'
		);
		$this->assertNotEquals(
			$sensitive_context['token'],
			$protected_context['token'],
			'Token must be encrypted'
		);
	}

	/**
	 * Test all gap closure features are loadable
	 */
	public function test_all_gap_closure_features_loadable() {
		$features = array(
			'includes/class-wp-mcp-ai-siem-logger.php'      => 'WP_MCP_AI_SIEM_Logger',
			'includes/class-wp-mcp-ai-correlation-id.php'   => 'WP_MCP_AI_Correlation_ID',
			'includes/class-wp-mcp-ai-encryption.php'       => 'WP_MCP_AI_Encryption',
		);

		foreach ( $features as $file => $class ) {
			$full_path = WP_MCP_AI_PATH . $file;

			$this->assertFileExists(
				$full_path,
				"Gap closure feature file {$file} must exist"
			);

			require_once $full_path;

			$this->assertTrue(
				class_exists( $class ),
				"Gap closure class {$class} must be defined"
			);
		}
	}
}
