<?php
/**
 * Tests for the WP_MCP_AI_Pro_Google_Service_Account helper class.
 *
 * Validates JSON key parsing, error handling for invalid inputs, and
 * the JWT building/signing flow without making real network requests.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Google Service Account helper.
 */
class Test_Google_Service_Account_Helper extends WP_UnitTestCase {

	/**
	 * Minimal valid service account JSON fixture.
	 */
	private function valid_key_json() {
		return wp_json_encode(
			array(
				'type'         => 'service_account',
				'project_id'   => 'my-project',
				'client_email' => 'bot@my-project.iam.gserviceaccount.com',
				'private_key'  => "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBALRiMLAHudeSA/xKl1oVGS8a8+cKfR6B0/7lBNDHzxFf6JJ4\n-----END RSA PRIVATE KEY-----\n",
				'token_uri'    => 'https://oauth2.googleapis.com/token',
			)
		);
	}

	/**
	 * Load the service account helper class.
	 */
	private function load_helper() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		$path = WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-google-service-account.php';

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'Google Service Account class not found at ' . $path );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
			require_once $path;
		}
	}

	// =========================================================================
	// parse_key – input validation.
	// =========================================================================

	/**
	 * Test parse_key returns error on empty string.
	 */
	public function test_parse_key_rejects_empty_string() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_gc_sa_empty_key', $result->get_error_code() );
	}

	/**
	 * Test parse_key returns error for non-string input.
	 */
	public function test_parse_key_rejects_non_string() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( 12345 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test parse_key returns error for invalid JSON.
	 */
	public function test_parse_key_rejects_invalid_json() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( 'not-valid-json{' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_gc_sa_invalid_json', $result->get_error_code() );
	}

	/**
	 * Test parse_key returns error when client_email is missing.
	 */
	public function test_parse_key_rejects_missing_client_email() {
		$this->load_helper();

		$json = wp_json_encode(
			array(
				'type'        => 'service_account',
				'private_key' => '-----BEGIN RSA PRIVATE KEY-----',
			)
		);

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( $json );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_gc_sa_incomplete_key', $result->get_error_code() );
	}

	/**
	 * Test parse_key returns error when private_key is missing.
	 */
	public function test_parse_key_rejects_missing_private_key() {
		$this->load_helper();

		$json = wp_json_encode(
			array(
				'type'         => 'service_account',
				'client_email' => 'bot@project.iam.gserviceaccount.com',
			)
		);

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( $json );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_gc_sa_incomplete_key', $result->get_error_code() );
	}

	/**
	 * Test parse_key returns array with valid JSON key.
	 */
	public function test_parse_key_accepts_valid_json() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::parse_key( $this->valid_key_json() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'client_email', $result );
		$this->assertArrayHasKey( 'private_key', $result );
		$this->assertSame( 'bot@my-project.iam.gserviceaccount.com', $result['client_email'] );
	}

	// =========================================================================
	// get_access_token – credential validation.
	// =========================================================================

	/**
	 * Test get_access_token returns error for empty credentials.
	 */
	public function test_get_access_token_rejects_empty_credentials() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token(
			array(),
			'https://www.googleapis.com/auth/chat.bot'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_gc_sa_incomplete_credentials', $result->get_error_code() );
	}

	/**
	 * Test get_access_token_from_key returns error on invalid JSON.
	 */
	public function test_get_access_token_from_key_rejects_invalid_json() {
		$this->load_helper();

		$result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
			'invalid-json',
			'https://www.googleapis.com/auth/chat.bot'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_access_token_from_key returns signing error for invalid private key.
	 */
	public function test_get_access_token_from_key_returns_error_for_invalid_private_key() {
		$this->load_helper();

		$json = wp_json_encode(
			array(
				'type'         => 'service_account',
				'client_email' => 'bot@my-project.iam.gserviceaccount.com',
				'private_key'  => 'not-a-real-key',
				'token_uri'    => 'https://oauth2.googleapis.com/token',
			)
		);

		$result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key(
			$json,
			'https://www.googleapis.com/auth/chat.bot'
		);

		// Should fail at JWT signing step.
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_access_token uses cached token when available.
	 */
	public function test_get_access_token_uses_transient_cache() {
		$this->load_helper();

		$client_email = 'cached-bot@my-project.iam.gserviceaccount.com';
		$scope        = 'https://www.googleapis.com/auth/chat.bot';
		$cache_key    = 'wp_mcp_ai_gc_sa_token_' . md5( strtolower( $client_email ) . '|' . $scope );

		// Pre-populate cache.
		set_transient( $cache_key, 'cached-access-token-value', 300 );

		$credentials = array(
			'client_email' => $client_email,
			'private_key'  => 'any-key',
		);

		$result = WP_MCP_AI_Pro_Google_Service_Account::get_access_token( $credentials, $scope );

		delete_transient( $cache_key );

		$this->assertSame( 'cached-access-token-value', $result, 'Should return the cached token without attempting JWT signing' );
	}
}
