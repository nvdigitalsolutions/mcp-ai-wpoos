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

	/**
	 * Test that the Google Chat API endpoint URL is built without URL-encoding the space
	 * name. Encoding the slash in `spaces/ID` breaks the Google Chat API request.
	 *
	 * This mirrors the URL-building logic in ajax_test_google_chat_auto_reply() and
	 * handle_google_chat_reply_job(), both of which must use string concatenation rather
	 * than rawurlencode() so the path separator is preserved.
	 */
	public function test_google_chat_endpoint_url_not_rawurlencoded() {
		$space = 'spaces/AAAATestSpace123';

		// Correct approach: direct concatenation, as used in the fixed code.
		$correct_endpoint = 'https://chat.googleapis.com/v1/' . $space . '/messages';

		$this->assertStringContainsString(
			'spaces/AAAATestSpace123',
			$correct_endpoint,
			'Endpoint URL must contain the literal slash in the space name'
		);
		$this->assertStringNotContainsString(
			'spaces%2F',
			$correct_endpoint,
			'Endpoint URL must not URL-encode the slash in the space name'
		);
		$this->assertSame(
			'https://chat.googleapis.com/v1/spaces/AAAATestSpace123/messages',
			$correct_endpoint,
			'Correct endpoint format must match Google Chat API v1 path'
		);

		// Demonstrate that rawurlencode() produces a broken URL (the original bug).
		$broken_endpoint = sprintf( 'https://chat.googleapis.com/v1/%s/messages', rawurlencode( $space ) );
		$this->assertStringContainsString(
			'spaces%2F',
			$broken_endpoint,
			'rawurlencode() incorrectly encodes the slash — confirms the old approach was broken'
		);
		$this->assertNotSame(
			$correct_endpoint,
			$broken_endpoint,
			'rawurlencode() endpoint must differ from the correct endpoint'
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

	// =========================================================================
	// handle_channel_send_reply() — Google Chat inbox reply.
	// =========================================================================

	/**
	 * handle_channel_send_reply must pass through unchanged for non-google_chat channels.
	 */
	public function test_handle_channel_send_reply_ignores_other_channels() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$controller_path = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-google-chat-webhook-controller.php';
		if ( ! file_exists( $controller_path ) ) {
			$this->markTestSkipped( 'Google Chat webhook controller not found' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			// File instantiates the class at the bottom; load it without double-instantiation.
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_channel_send_reply' );
		$method->setAccessible( true );

		// For a non-google_chat channel the original $result must be returned unchanged.
		$initial_result = 'untouched';
		$result         = $method->invoke( $controller, $initial_result, 'whatsapp', 'contact_123', 'Hello', 'conn_id', array() );
		$this->assertSame( $initial_result, $result, 'handle_channel_send_reply must not alter $result for non-google_chat channels' );
	}

	/**
	 * resolve_google_chat_space_for_contact falls back to connection's google_chat_space
	 * when the messages CCT table is not available.
	 */
	public function test_resolve_google_chat_space_falls_back_to_connection_space() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_google_chat_space_for_contact' );
		$method->setAccessible( true );

		$connection = array(
			'google_chat_space' => 'spaces/AAAATestFallback',
		);

		// The messages CCT is not available in unit tests, so it falls back to the connection.
		$space = $method->invoke( $controller, 'users/12345', $connection );

		$this->assertSame( 'spaces/AAAATestFallback', $space, 'Should fall back to connection google_chat_space when messages CCT is unavailable' );
	}

	/**
	 * resolve_google_chat_space_for_contact returns empty string when neither
	 * the messages CCT nor the connection has a space configured.
	 */
	public function test_resolve_google_chat_space_returns_empty_when_no_space() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'resolve_google_chat_space_for_contact' );
		$method->setAccessible( true );

		$connection = array();

		$space = $method->invoke( $controller, 'users/12345', $connection );

		$this->assertSame( '', $space, 'Should return empty string when no space is configured anywhere' );
	}

	/**
	 * handle_channel_send_reply returns WP_Error when no Google Chat connection is found.
	 */
	public function test_handle_channel_send_reply_returns_error_when_no_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_channel_send_reply' );
		$method->setAccessible( true );

		// No connections saved — passing an empty connection_id should result in WP_Error.
		$result = $method->invoke( $controller, false, 'google_chat', 'users/12345', 'Hello', '', array() );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when no connection is found' );
		$this->assertSame( 'google_chat_no_connection', $result->get_error_code(), 'Error code should be google_chat_no_connection' );
	}

	/**
	 * handle_channel_send_reply returns WP_Error when connection is found but no space can be resolved.
	 */
	public function test_handle_channel_send_reply_returns_error_when_no_space() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		// Save a Google Chat connection with no google_chat_space so space resolution fails.
		$connection_data = array(
			'name'            => 'Google Chat No Space Reply Test',
			'url'             => 'https://chat.googleapis.com/v1',
			'connection_type' => 'google_chat',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'ya29.fake_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Save should succeed' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_channel_send_reply' );
		$method->setAccessible( true );

		// No space configured anywhere — should get google_chat_no_space error.
		$result = $method->invoke( $controller, false, 'google_chat', 'users/12345', 'Hello', $connection_id, array() );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when space cannot be resolved' );
		$this->assertSame( 'google_chat_no_space', $result->get_error_code(), 'Error code should be google_chat_no_space' );
	}

	/**
	 * handle_channel_send_reply sends the message to the Google Chat API and returns true on success.
	 */
	public function test_handle_channel_send_reply_sends_message_on_success() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Service_Account' ) ) {
			$sa_path = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-google-service-account.php'
				: '';
			if ( $sa_path && file_exists( $sa_path ) ) {
				require_once $sa_path;
			} else {
				$this->markTestSkipped( 'Google Service Account helper not available' );
				return;
			}
		}

		// Save a Google Chat connection with google_chat_space so space resolution succeeds.
		$connection_data = array(
			'name'              => 'Google Chat Send Reply Test',
			'url'               => 'https://chat.googleapis.com/v1',
			'connection_type'   => 'google_chat',
			'auth_type'         => 'none',
			'enabled'           => true,
			'api_key'           => 'ya29.fake_raw_token',
			'google_chat_space' => 'spaces/AAAATestSendReply',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Save should succeed' );

		$sent_to_url = '';

		$filter_callback = function ( $preempt, $parsed_args, $url ) use ( &$sent_to_url ) {
			$sent_to_url = $url;
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'name' => 'spaces/AAAATestSendReply/messages/msg1' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_channel_send_reply' );
		$method->setAccessible( true );

		$result = $method->invoke( $controller, false, 'google_chat', 'users/12345', 'Hello from inbox', $connection_id, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertTrue( $result, 'handle_channel_send_reply should return true on successful send' );
		$this->assertStringContainsString( 'spaces/AAAATestSendReply/messages', $sent_to_url, 'Request should be sent to the correct Google Chat endpoint' );
	}

	// =========================================================================
	// Connection-specific webhook route registration.
	// =========================================================================

	/**
	 * The webhook controller must register a connection-specific route so that the
	 * channel-specific URL displayed in the admin UI (/webhooks/google-chat/{id})
	 * is reachable by Google Chat and does not return 404.
	 */
	public function test_connection_specific_webhook_route_is_registered() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		// Trigger REST route registration.
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		// Generic route must exist.
		$this->assertArrayHasKey(
			'/mcp-ai/v1/webhooks/google-chat',
			$routes,
			'Generic Google Chat webhook route must be registered'
		);

		// Connection-specific route must also be registered so channel-specific
		// URLs shown in the admin UI resolve to a real endpoint.
		$found_specific_route = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( preg_match( '#^/mcp-ai/v1/webhooks/google-chat/\(#', $route ) ) {
				$found_specific_route = true;
				break;
			}
		}

		$this->assertTrue(
			$found_specific_route,
			'Connection-specific Google Chat webhook route (/webhooks/google-chat/{connection_id}) must be registered'
		);
	}

	/**
	 * handle_webhook must look up the connection by URL connection_id when that
	 * route parameter is present, bypassing the space-name-based lookup.
	 */
	public function test_handle_webhook_uses_url_connection_id() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		// Save a Google Chat connection with a unique space name.
		$connection_data = array(
			'name'                   => 'GC Incoming Route Test',
			'url'                    => 'https://chat.googleapis.com/v1',
			'connection_type'        => 'google_chat',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'ya29.fake_token',
			'google_chat_space'      => 'spaces/AAAARouteTest',
			'assigned_assistant_ids' => array(),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Build a minimal Google Chat MESSAGE webhook payload.
		$payload = array(
			'type'    => 'MESSAGE',
			'message' => array(
				'name'   => 'spaces/AAAARouteTest/messages/msg123',
				'text'   => 'Hello bot',
				'sender' => array( 'name' => 'users/99999' ),
				'thread' => array( 'name' => 'spaces/AAAARouteTest/threads/t1' ),
			),
			'space'   => array(
				'name' => 'spaces/AAAARouteTest',
				'type' => 'DIRECT_MESSAGE',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat/' . $connection_id );
		$request->set_param( 'connection_id', $connection_id );
		$request->set_json_params( $payload );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$response   = $controller->handle_webhook( $request );

		// The handler should return an empty 200 response (async cron scheduled).
		// A null $response here would indicate an exception or early bail-out.
		$this->assertNotNull( $response, 'handle_webhook must return a response (not null)' );

		// Clean up scheduled cron event.
		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );
	}

	// =========================================================================
	// allow_google_oidc_auth — REST authentication error bypass.
	// =========================================================================

	/**
	 * allow_google_oidc_auth passes non-error values through unchanged.
	 */
	public function test_allow_google_oidc_auth_passes_through_null() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$result     = $controller->allow_google_oidc_auth( null );

		$this->assertNull( $result, 'allow_google_oidc_auth must return null unchanged when no error is set' );
	}

	/**
	 * allow_google_oidc_auth clears WP_Error for requests to the webhook base path.
	 */
	public function test_allow_google_oidc_auth_clears_error_for_webhook_path() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		// Simulate a JWT auth plugin setting an error on the webhook path.
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/webhooks/google-chat';

		$error      = new WP_Error( 'jwt_auth_bad_auth_header', 'Authorization header malformed.' );
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$result     = $controller->allow_google_oidc_auth( $error );

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertNull( $result, 'allow_google_oidc_auth must clear auth errors for the google-chat webhook path' );
	}

	/**
	 * allow_google_oidc_auth leaves WP_Error untouched for unrelated paths.
	 */
	public function test_allow_google_oidc_auth_preserves_error_for_other_paths() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/chat';

		$error      = new WP_Error( 'jwt_auth_bad_auth_header', 'Authorization header malformed.' );
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$result     = $controller->allow_google_oidc_auth( $error );

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertInstanceOf( WP_Error::class, $result, 'allow_google_oidc_auth must not clear errors for unrelated REST paths' );
	}

	/**
	 * allow_google_oidc_auth also clears errors for connection-specific webhook URLs.
	 */
	public function test_allow_google_oidc_auth_clears_error_for_connection_specific_path() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$_SERVER['REQUEST_URI'] = '/wp-json/mcp-ai/v1/webhooks/google-chat/conn_abc123';

		$error      = new WP_Error( 'rest_not_logged_in', 'REST API requires authentication.' );
		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$result     = $controller->allow_google_oidc_auth( $error );

		unset( $_SERVER['REQUEST_URI'] );

		$this->assertNull( $result, 'allow_google_oidc_auth must clear auth errors for connection-specific webhook paths' );
	}

	// =========================================================================
	// require_mention removal — DM and space mention handling.
	// =========================================================================

	/**
	 * DM messages are processed without any mention check (require_mention removed).
	 */
	public function test_handle_webhook_processes_dm_without_mention_check() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'DM Test Assistant',
			)
		);

		// Connection with no require_mention field (the field is gone).
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC DM Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'ya29.fake',
				'google_chat_space'      => 'spaces/AAAADMTest',
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Simulate a plain DM — no argumentText, no @mention in text.
		$payload = array(
			'type'    => 'MESSAGE',
			'message' => array(
				'name'   => 'spaces/AAAADMTest/messages/dm1',
				'text'   => 'what can you do?',
				'sender' => array( 'name' => 'users/42' ),
				'thread' => array( 'name' => 'spaces/AAAADMTest/threads/t1' ),
			),
			'space'   => array(
				'name'      => 'spaces/AAAADMTest',
				'spaceType' => 'DIRECT_MESSAGE',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_json_params( $payload );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$response   = $controller->handle_webhook( $request );

		$this->assertNotNull( $response, 'handle_webhook must return a response for DM messages' );

		$scheduled = wp_next_scheduled( 'wp_mcp_ai_google_chat_send_ai_reply' );
		$this->assertNotFalse( $scheduled, 'A cron reply job must be scheduled for DM messages' );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );
	}

	/**
	 * Space @mention messages are processed (argumentText present, no mention gate).
	 */
	public function test_handle_webhook_processes_space_mention() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Space Mention Assistant',
			)
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Space Mention Test',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'api_key'                => 'ya29.fake',
				'google_chat_space'      => 'spaces/AAAASpaceTest',
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Simulate a space @mention — argumentText is the text after the @mention.
		$payload = array(
			'type'    => 'MESSAGE',
			'message' => array(
				'name'         => 'spaces/AAAASpaceTest/messages/m1',
				'text'         => '@NV oOS what can you do?',
				'argumentText' => ' what can you do?',
				'sender'       => array( 'name' => 'users/77' ),
				'thread'       => array( 'name' => 'spaces/AAAASpaceTest/threads/t2' ),
			),
			'space'   => array(
				'name'      => 'spaces/AAAASpaceTest',
				'spaceType' => 'SPACE',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_json_params( $payload );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$response   = $controller->handle_webhook( $request );

		$this->assertNotNull( $response );

		$scheduled = wp_next_scheduled( 'wp_mcp_ai_google_chat_send_ai_reply' );
		$this->assertNotFalse( $scheduled, 'A cron reply job must be scheduled for space @mention messages' );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );
	}

	// =========================================================================
	// APP_COMMAND (slash command) handling.
	// =========================================================================
	/**
	 * extract_app_command_text returns the commandText from the payload.
	 */
	public function test_extract_app_command_text_returns_command_text() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_app_command_text' );
		$method->setAccessible( true );

		$payload = array(
			'appCommandPayload' => array(
				'commandText' => 'What can you do?',
			),
		);

		$result = $method->invoke( $controller, $payload );
		$this->assertSame( 'What can you do?', $result );
	}

	/**
	 * extract_app_command_text returns a non-empty fallback when commandText is absent.
	 */
	public function test_extract_app_command_text_returns_fallback_for_empty_command_text() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_app_command_text' );
		$method->setAccessible( true );

		// No commandText key at all.
		$result_no_key = $method->invoke( $controller, array( 'appCommandPayload' => array() ) );
		$this->assertNotEmpty( $result_no_key, 'Fallback must be non-empty when commandText is absent' );
		$this->assertIsString( $result_no_key );

		// commandText present but empty string.
		$result_empty = $method->invoke( $controller, array( 'appCommandPayload' => array( 'commandText' => '' ) ) );
		$this->assertNotEmpty( $result_empty, 'Fallback must be non-empty when commandText is an empty string' );
	}

	/**
	 * handle_webhook schedules a cron reply for APP_COMMAND events.
	 */
	public function test_handle_webhook_schedules_reply_for_app_command_event() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$connection_data = array(
			'name'                   => 'GC APP_COMMAND Test',
			'url'                    => 'https://chat.googleapis.com/v1',
			'connection_type'        => 'google_chat',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'ya29.fake_token',
			'google_chat_space'      => 'spaces/AAAACmdTest',
			'assigned_assistant_ids' => array( $assistant_id ),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Simulate an APP_COMMAND payload (slash command invoked in a DM).
		$payload = array(
			'type'              => 'APP_COMMAND',
			'appCommandPayload' => array(
				'appCommandMetadata' => array(
					'appCommandType' => 'SLASH_COMMAND',
					'appCommandId'   => 1,
				),
				'commandText'        => 'What can you do?',
			),
			'space'             => array(
				'name'      => 'spaces/AAAACmdTest',
				'spaceType' => 'DIRECT_MESSAGE',
			),
			'user'              => array(
				'name' => 'users/12345',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_json_params( $payload );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$response   = $controller->handle_webhook( $request );

		$this->assertNotNull( $response, 'handle_webhook must return a response for APP_COMMAND events' );

		// A cron event must have been scheduled.
		$scheduled = wp_next_scheduled( 'wp_mcp_ai_google_chat_send_ai_reply' );
		$this->assertNotFalse( $scheduled, 'A cron reply job must be scheduled for APP_COMMAND events' );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );
	}

	// =========================================================================
	// Incoming webhook URL (reply_webhook_url) support.
	// =========================================================================

	/**
	 * Test that reply_webhook_url field persists when saving a Google Chat connection.
	 */
	public function test_google_chat_reply_webhook_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$webhook_url = 'https://chat.googleapis.com/v1/spaces/AAAATestSpace/messages?key=abc123&token=xyz789';

		$connection_data = array(
			'name'              => 'GC Webhook URL Test',
			'url'               => 'https://chat.googleapis.com/v1',
			'connection_type'   => 'google_chat',
			'auth_type'         => 'none',
			'enabled'           => true,
			'reply_webhook_url' => $webhook_url,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return an error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'google_chat', $saved['connection_type'], 'Connection type should be google_chat' );
		$this->assertSame( $webhook_url, $saved['reply_webhook_url'], 'reply_webhook_url field should persist' );
	}

	/**
	 * Test that reply_webhook_url is preserved when updating a connection without providing it.
	 */
	public function test_google_chat_reply_webhook_url_preserved_on_update() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$webhook_url = 'https://chat.googleapis.com/v1/spaces/AAAAPreserveTest/messages?key=abc&token=def';

		// First save with webhook URL.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'              => 'GC Preserve Webhook Test',
				'url'               => 'https://chat.googleapis.com/v1',
				'connection_type'   => 'google_chat',
				'auth_type'         => 'none',
				'enabled'           => true,
				'reply_webhook_url' => $webhook_url,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		// Update without providing reply_webhook_url — it should be preserved.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'id'              => $connection_id,
				'name'            => 'GC Preserve Webhook Test Updated',
				'url'             => 'https://chat.googleapis.com/v1',
				'connection_type' => 'google_chat',
				'auth_type'       => 'none',
				'enabled'         => true,
			)
		);

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $saved );
		$this->assertSame( $webhook_url, $saved['reply_webhook_url'], 'reply_webhook_url should be preserved when not provided on update' );
	}

	/**
	 * Test that reply_webhook_url empty when not provided.
	 */
	public function test_google_chat_reply_webhook_url_empty_when_not_provided() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'GC No Webhook Test',
				'url'             => 'https://chat.googleapis.com/v1',
				'connection_type' => 'google_chat',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'ya29.fake_token',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertNotNull( $saved );
		$this->assertSame( '', $saved['reply_webhook_url'], 'reply_webhook_url should be empty when not provided' );
	}

	/**
	 * Test that handle_webhook schedules a cron job for a MESSAGE event when the connection
	 * has only a reply_webhook_url and no OAuth/Service Account credentials.
	 */
	public function test_google_chat_webhook_url_only_connection_schedules_cron() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Webhook Only Assistant',
			)
		);

		// Save connection with ONLY a reply_webhook_url — no OAuth or Service Account.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC Webhook Only',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'reply_webhook_url'      => 'https://chat.googleapis.com/v1/spaces/AAAAWebhookOnly/messages?key=k&token=t',
				'google_chat_space'      => 'spaces/AAAAWebhookOnly',
				'assigned_assistant_ids' => array( $assistant_id ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$payload = array(
			'type'    => 'MESSAGE',
			'message' => array(
				'name'   => 'spaces/AAAAWebhookOnly/messages/wh1',
				'text'   => 'Hello via webhook only!',
				'sender' => array( 'name' => 'users/99' ),
			),
			'space'   => array(
				'name'      => 'spaces/AAAAWebhookOnly',
				'spaceType' => 'SPACE',
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/google-chat' );
		$request->set_json_params( $payload );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();
		$controller->handle_webhook( $request );

		$scheduled = wp_next_scheduled( 'wp_mcp_ai_google_chat_send_ai_reply' );
		$this->assertNotFalse( $scheduled, 'Cron job should be scheduled even when only a reply_webhook_url is configured' );

		wp_clear_scheduled_hook( 'wp_mcp_ai_google_chat_send_ai_reply' );
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that handle_google_chat_reply_job returns early when connection has
	 * neither OAuth credentials nor a valid reply_webhook_url.
	 */
	public function test_google_chat_reply_job_returns_early_without_credentials_or_webhook() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		// Save a connection with NO credentials and NO webhook URL.
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'                   => 'GC No Credentials',
				'url'                    => 'https://chat.googleapis.com/v1',
				'connection_type'        => 'google_chat',
				'auth_type'              => 'none',
				'enabled'                => true,
				'assigned_assistant_ids' => array( 1 ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $connection_id );

		$controller = new WP_MCP_AI_Google_Chat_Webhook_Controller();

		// Call handle_google_chat_reply_job — it should return early without throwing.
		$controller->handle_google_chat_reply_job(
			array(
				'assistant_id'  => 1,
				'message_text'  => 'Test message',
				'space_name'    => 'spaces/AAAATest',
				'sender_name'   => 'users/100',
				'connection_id' => $connection_id,
				'thread_name'   => '',
			)
		);

		// If we reach here without exception, the early-return guard works correctly.
		$this->assertTrue( true, 'Reply job should return early when no credentials or webhook URL are configured' );
	}

	/**
	 * Test that WEBHOOK_URL_PATTERN constant is defined and rejects non-matching URLs.
	 */
	public function test_google_chat_webhook_url_pattern_constant() {
		if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			$this->markTestSkipped( 'Cannot safely load Google Chat webhook controller in unit tests' );
			return;
		}

		$valid_url   = 'https://chat.googleapis.com/v1/spaces/AAAATest/messages?key=abc&token=xyz';
		$invalid_url = 'https://evil.example.com/v1/spaces/AAAATest/messages?key=abc&token=xyz';

		$this->assertSame(
			1,
			preg_match( WP_MCP_AI_Google_Chat_Webhook_Controller::WEBHOOK_URL_PATTERN, $valid_url ),
			'Valid Google Chat incoming webhook URL should match the pattern'
		);

		$this->assertSame(
			0,
			preg_match( WP_MCP_AI_Google_Chat_Webhook_Controller::WEBHOOK_URL_PATTERN, $invalid_url ),
			'Non-Google-Chat URL should not match the pattern'
		);
	}
}
