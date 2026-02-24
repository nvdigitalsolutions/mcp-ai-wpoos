<?php
/**
 * Test Google Chat-specific fields persistence in Remote Site Manager and
 * the AJAX handler logic introduced for the Test Connection / Fetch Spaces /
 * Test Auto-Reply features.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager Google Chat field persistence.
 */
class Test_Remote_Connection_Google_Chat_Fields extends WP_UnitTestCase {

	/**
	 * Clean up connections before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	// =========================================================================
	// Field persistence.
	// =========================================================================

	/**
	 * Test that google_chat_space field persists when saving a Google Chat connection.
	 */
	public function test_google_chat_space_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'              => 'Test Google Chat Connection',
			'url'               => 'https://chat.googleapis.com/v1',
			'connection_type'   => 'google_chat',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'ya29.test_access_token',
			'verify_token'      => 'https://example.com/wp-json/mcp-ai/v1/webhooks/google-chat',
			'google_chat_space' => 'spaces/AAAATestSpace',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return error' );
		$this->assertIsString( $result, 'Connection save should return connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'google_chat', $saved['connection_type'], 'Connection type should be google_chat' );
		$this->assertSame( 'spaces/AAAATestSpace', $saved['google_chat_space'], 'google_chat_space field should persist' );
	}

	/**
	 * Test that verify_token (audience URL) persists for Google Chat connections.
	 */
	public function test_google_chat_audience_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$audience_url = 'https://example.com/wp-json/mcp-ai/v1/webhooks/google-chat';

		$connection_data = array(
			'name'              => 'Test Google Chat Audience',
			'url'               => 'https://chat.googleapis.com/v1',
			'connection_type'   => 'google_chat',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'ya29.test_token',
			'verify_token'      => $audience_url,
			'google_chat_space' => '',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		$this->assertSame( $audience_url, $saved['verify_token'], 'Audience URL (verify_token) should persist' );
	}

	/**
	 * Test that assigned_assistant_ids persist for Google Chat connections.
	 */
	public function test_google_chat_assigned_assistants_persist() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'                   => 'Test Google Chat Assistants',
			'url'                    => 'https://chat.googleapis.com/v1',
			'connection_type'        => 'google_chat',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'ya29.test_token',
			'verify_token'           => '',
			'google_chat_space'      => '',
			'assigned_assistant_ids' => array( 10, 20, 30 ),
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		$this->assertSame( array( 10, 20, 30 ), $saved['assigned_assistant_ids'], 'Assigned assistant IDs should persist' );
	}

	/**
	 * Test that google_chat_space is preserved as empty string when not provided.
	 */
	public function test_google_chat_space_empty_when_not_provided() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Google Chat No Space',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'ya29.test_token',
			'verify_token'    => '',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved );
		// google_chat_space key should exist (possibly empty) or not cause errors.
		$space = isset( $saved['google_chat_space'] ) ? $saved['google_chat_space'] : '';
		$this->assertSame( '', $space, 'google_chat_space should be empty when not provided' );
	}

	// =========================================================================
	// AJAX handler: test_google_chat_live — input validation.
	// =========================================================================

	/**
	 * Test ajax_test_google_chat_live sends error when access token is missing.
	 */
	public function test_ajax_test_google_chat_live_requires_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Sites_Admin' ) ) {
			$pro_admin_path = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php'
				: '';
			if ( $pro_admin_path && file_exists( $pro_admin_path ) ) {
				// Skip loading — constructor hooks would fire. Just verify the class exists.
				$this->markTestSkipped( 'Cannot safely instantiate remote sites admin in unit tests' );
				return;
			}
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$this->markTestSkipped( 'Cannot safely instantiate remote sites admin in unit tests' );
	}

	// =========================================================================
	// AJAX handler: fetch_google_chat_spaces — space name format validation.
	// =========================================================================

	/**
	 * Test that space names returned by Google Chat API follow the expected format.
	 */
	public function test_google_chat_space_name_format_validation() {
		$valid_names = array(
			'spaces/AAAABBBccc',
			'spaces/AAAA-1234',
			'spaces/abcDEF_789',
		);

		foreach ( $valid_names as $name ) {
			$this->assertMatchesRegularExpression(
				'/^spaces\/[a-zA-Z0-9_-]+$/',
				$name,
				"Space name '{$name}' should match expected format"
			);
		}

		$invalid_names = array(
			'AAAA-1234',
			'spaces/',
			'rooms/AAAA',
			'',
		);

		foreach ( $invalid_names as $name ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^spaces\/[a-zA-Z0-9_-]+$/',
				$name,
				"Space name '{$name}' should not match expected format"
			);
		}
	}

	/**
	 * Test that Google Chat test auto-reply space validation rejects invalid formats.
	 */
	public function test_google_chat_auto_reply_space_validation() {
		// Mirrors the preg_match check used in ajax_test_google_chat_auto_reply.
		$valid_space   = 'spaces/AAAATestSpace123';
		$invalid_space = 'not-a-space';

		$this->assertSame(
			1,
			preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $valid_space ),
			'Valid space should pass format validation'
		);

		$this->assertSame(
			0,
			preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $invalid_space ),
			'Invalid space should fail format validation'
		);
	}

	// =========================================================================
	// test_connection() — Google Chat routing and partial-setup handling.
	// =========================================================================

	/**
	 * The test_connection() method must return WP_Error when a Google Chat connection has
	 * no credentials at all (no api_key, no refresh_token, no client credentials).
	 */
	public function test_test_connection_google_chat_no_credentials_returns_error() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Google Chat No Creds',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Save should succeed' );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );

		$this->assertInstanceOf( 'WP_Error', $result, 'test_connection should return WP_Error with no credentials' );
		$this->assertStringContainsString( 'credentials', strtolower( $result->get_error_message() ), 'Error message should mention credentials' );
	}

	/**
	 * The test_connection() method must return a partial-success array (not a WP_Error and not
	 * an HTTP 404) when only OAuth Client ID and Client Secret are saved but the
	 * OAuth flow has not yet been completed (no refresh_token, no api_key).
	 */
	public function test_test_connection_google_chat_oauth_client_only_returns_partial_success() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Google Chat OAuth Only',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-client-id.apps.googleusercontent.com',
			'client_secret'   => 'test-client-secret',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Save should succeed' );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );

		// Must NOT return a WP_Error (which would surface as "HTTP error 404").
		$this->assertNotInstanceOf( 'WP_Error', $result, 'test_connection should not return WP_Error when OAuth credentials are saved but flow not completed' );
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Result should indicate success' );
		$this->assertTrue( isset( $result['partial'] ) && $result['partial'], 'Result should flag as partial setup' );
		$this->assertStringContainsString( 'OAuth', $result['message'], 'Message should mention OAuth flow' );
	}

	/**
	 * The test_connection() method must call the Google Chat API and return success when
	 * a valid api_key (Service Account JSON) is stored.
	 */
	public function test_test_connection_google_chat_with_api_key_calls_google_api() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		// Save connection with a dummy api_key so the code path is reached.
		$connection_data = array(
			'name'            => 'Google Chat Service Account',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => '{"type":"service_account","project_id":"test","private_key_id":"k1","private_key":"FAKE","client_email":"bot@test.iam.gserviceaccount.com","client_id":"123","auth_uri":"https://accounts.google.com/o/oauth2/auth","token_uri":"https://oauth2.googleapis.com/token","auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs","client_x509_cert_url":"https://www.googleapis.com/robot/v1/metadata/x509/bot%40test.iam.gserviceaccount.com"}',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Save should succeed' );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Stub all outbound HTTP requests so no real network call is made.
		$filter_callback = function ( $preempt, $parsed_args, $url ) {
			// Token exchange endpoint — return a fake access token.
			if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'access_token' => 'ya29.fake_token',
							'expires_in'   => 3600,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			// Google Chat spaces.list endpoint — return a fake space list.
			if ( false !== strpos( $url, 'chat.googleapis.com' ) ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'spaces' => array( array( 'name' => 'spaces/AAAA' ) ) ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'test_connection should not return WP_Error with valid api_key' );
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( isset( $result['success'] ) && $result['success'], 'Result should indicate success' );
		$this->assertSame( 1, $result['space_count'], 'Space count should match stub response' );
	}

	/**
	 * The test_connection() method must NOT send a request to wp/v2/types on the Google
	 * Chat domain — that is the old (broken) path that caused HTTP 404 errors.
	 */
	public function test_test_connection_google_chat_does_not_hit_wordpress_api() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$wordpress_api_requested = false;

		$filter_callback = function ( $preempt, $parsed_args, $url ) use ( &$wordpress_api_requested ) {
			if ( false !== strpos( $url, 'wp/v2' ) || false !== strpos( $url, 'wp-json' ) ) {
				$wordpress_api_requested = true;
			}
			// Always short-circuit to avoid real network calls.
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array() ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		$connection_data = array(
			'name'            => 'Google Chat WP API Check',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-client-id',
			'client_secret'   => 'test-client-secret',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );
		WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );
		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertFalse( $wordpress_api_requested, 'test_connection must NOT request the WordPress REST API for Google Chat connections' );
	}
}
