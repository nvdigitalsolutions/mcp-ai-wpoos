<?php
/**
 * Test Messenger Webhook Controller.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Messenger Webhook Controller.
 */
class Test_Messenger_Webhook_Controller extends WP_UnitTestCase {

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

	/**
	 * Load the Messenger webhook controller class if available.
	 *
	 * @return bool True if loaded, false if skipped.
	 */
	private function load_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Messenger_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-messenger-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'Messenger Webhook Controller not available' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Create a test Facebook Messenger connection.
	 *
	 * @param array $overrides Optional field overrides.
	 * @return int|WP_Error Connection ID.
	 */
	private function create_messenger_connection( $overrides = array() ) {
		$defaults = array(
			'name'            => 'Test Messenger Connection',
			'url'             => 'https://graph.facebook.com/v19.0',
			'connection_type' => 'facebook_messenger',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_page_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'verify_token'    => 'my_messenger_verify_token_xyz',
		);

		return WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( array_merge( $defaults, $overrides ) );
	}

	/**
	 * Test that the controller can retrieve verify token from a Messenger connection.
	 */
	public function test_get_verify_token_from_connection() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$connection_id = $this->create_messenger_connection();
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_verify_token' );
		$method->setAccessible( true );

		$verify_token = $method->invoke( $controller );

		$this->assertEquals( 'my_messenger_verify_token_xyz', $verify_token, 'Verify token should be retrieved correctly' );
	}

	/**
	 * Test that the controller can retrieve app secret from a Messenger connection.
	 */
	public function test_get_app_secret_from_connection() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$connection_id = $this->create_messenger_connection();
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_app_secret' );
		$method->setAccessible( true );

		$app_secret = $method->invoke( $controller );

		$this->assertEquals( 'test_app_secret_67890', $app_secret, 'App secret should be retrieved and decrypted correctly' );
	}

	/**
	 * Test that the controller falls back to access token when app secret is missing.
	 */
	public function test_get_app_secret_fallback_to_access_token() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$connection_id = $this->create_messenger_connection( array( 'api_secret' => '' ) );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_app_secret' );
		$method->setAccessible( true );

		$app_secret = $method->invoke( $controller );

		$this->assertEquals( 'test_page_access_token_12345', $app_secret, 'Should fall back to access token when app secret is not set' );
	}

	/**
	 * Test that the controller returns empty string when no Messenger connection exists.
	 */
	public function test_returns_empty_when_no_connection() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );

		$verify_token_method = $reflection->getMethod( 'get_verify_token' );
		$verify_token_method->setAccessible( true );
		$app_secret_method = $reflection->getMethod( 'get_app_secret' );
		$app_secret_method->setAccessible( true );

		$this->assertEquals( '', $verify_token_method->invoke( $controller ), 'Should return empty string when no connection exists' );
		$this->assertEquals( '', $app_secret_method->invoke( $controller ), 'Should return empty string when no connection exists' );
	}

	/**
	 * Test that WhatsApp connections are NOT returned for Messenger controller.
	 */
	public function test_does_not_use_whatsapp_connections() {
		if ( ! $this->load_controller() ) {
			return;
		}

		// Create a WhatsApp connection (not Messenger).
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Test WhatsApp Connection',
				'url'             => 'https://graph.facebook.com/v18.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'whatsapp_token',
				'verify_token'    => 'whatsapp_verify_token',
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_verify_token' );
		$method->setAccessible( true );

		// Should return empty because there is no facebook_messenger connection.
		$this->assertEquals( '', $method->invoke( $controller ), 'Should not use WhatsApp connection verify token' );
	}

	/**
	 * Test webhook verification succeeds with correct parameters.
	 */
	public function test_verify_webhook_succeeds_with_correct_parameters() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$this->create_messenger_connection( array( 'verify_token' => 'test_verify_token_abc123' ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'test_verify_token_abc123' );
		$request->set_param( 'hub_challenge', 'CHALLENGE_STRING_12345' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response, 'Response should be a WP_REST_Response object' );
		$this->assertEquals( 200, $response->get_status(), 'Status should be 200' );
		$this->assertEquals( 'CHALLENGE_STRING_12345', $response->get_data(), 'Response should echo back the challenge' );
	}

	/**
	 * Test webhook verification fails with incorrect verify token.
	 */
	public function test_verify_webhook_fails_with_wrong_token() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$this->create_messenger_connection( array( 'verify_token' => 'correct_token' ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'wrong_token' );
		$request->set_param( 'hub_challenge', 'CHALLENGE' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error' );
		$this->assertEquals( 'messenger_verification_failed', $response->get_error_code() );
		$this->assertEquals( 403, $response->get_error_data( 'messenger_verification_failed' )['status'] );
	}

	/**
	 * Test webhook verification fails with wrong hub mode.
	 */
	public function test_verify_webhook_fails_with_wrong_mode() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$this->create_messenger_connection( array( 'verify_token' => 'my_token' ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_param( 'hub_mode', 'unsubscribe' );
		$request->set_param( 'hub_verify_token', 'my_token' );
		$request->set_param( 'hub_challenge', 'CHALLENGE' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error' );
		$this->assertEquals( 'messenger_verification_failed', $response->get_error_code() );
	}

	/**
	 * Test webhook verification fails when no verify token is configured.
	 */
	public function test_verify_webhook_fails_when_no_token_configured() {
		if ( ! $this->load_controller() ) {
			return;
		}

		// No connection created.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'any_token' );
		$request->set_param( 'hub_challenge', 'CHALLENGE' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error' );
		$this->assertEquals( 'messenger_no_verify_token', $response->get_error_code() );
		$this->assertEquals( 500, $response->get_error_data( 'messenger_no_verify_token' )['status'] );
	}

	/**
	 * Test handle_webhook rejects non-page object types.
	 */
	public function test_handle_webhook_rejects_non_page_object() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'whatsapp_business_account',
				'entry'  => array(),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = rest_get_server()->response_to_data( $controller->handle_webhook( $request ), false );

		$this->assertFalse( $response['success'], 'Should reject non-page object type' );
		$this->assertEquals( 'Invalid object type', $response['message'] );
	}

	/**
	 * Test handle_webhook accepts page object type.
	 */
	public function test_handle_webhook_accepts_page_object() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = rest_get_server()->response_to_data( $controller->handle_webhook( $request ), false );

		$this->assertTrue( $response['success'], 'Should accept page object type' );
		$this->assertEquals( 'EVENT_RECEIVED', $response['message'] );
	}

	/**
	 * Test handle_webhook returns 200 for empty payload.
	 */
	public function test_handle_webhook_returns_200_for_empty_payload() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		// No JSON body set — get_json_params() returns null/empty.

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$response   = rest_get_server()->response_to_data( $controller->handle_webhook( $request ), false );

		// Should return 200 (not error) to prevent Meta retries.
		$this->assertFalse( $response['success'] );
		$this->assertEquals( 'Empty payload', $response['message'] );
	}

	/**
	 * Test that incoming message event fires the correct WordPress action.
	 */
	public function test_incoming_message_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_message_received',
			function ( $message_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $message_data;
			},
			10,
			1
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'message'   => array(
									'mid'  => 'mid.test123',
									'text' => 'Hello, world!',
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_message_received action should fire' );
		$this->assertEquals( 'mid.test123', $fired_data['id'] );
		$this->assertEquals( '9876543210', $fired_data['sender_id'] );
		$this->assertEquals( 'Hello, world!', $fired_data['text'] );
	}

	/**
	 * Test that postback event fires the correct WordPress action.
	 */
	public function test_postback_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_postback',
			function ( $postback_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $postback_data;
			},
			10,
			1
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'postback'  => array(
									'title'   => 'Get Started',
									'payload' => 'GET_STARTED_PAYLOAD',
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_postback action should fire' );
		$this->assertEquals( 'GET_STARTED_PAYLOAD', $fired_data['payload'] );
		$this->assertEquals( 'Get Started', $fired_data['title'] );
	}

	/**
	 * Test that read receipt event fires the correct WordPress action.
	 */
	public function test_read_receipt_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_read_receipt',
			function ( $read_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $read_data;
			},
			10,
			1
		);

		$watermark = time() * 1000;

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'read'      => array(
									'watermark' => $watermark,
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_read_receipt action should fire' );
		$this->assertEquals( $watermark, $fired_data['watermark'] );
	}

	/**
	 * Test that delivery receipt event fires the correct WordPress action.
	 */
	public function test_delivery_receipt_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired = false;

		add_action(
			'wp_mcp_ai_messenger_delivery_receipt',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'delivery'  => array(
									'watermark' => time() * 1000,
									'mids'      => array( 'mid.delivery123' ),
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_delivery_receipt action should fire' );
	}

	/**
	 * Test that reaction event fires the correct WordPress action.
	 */
	public function test_reaction_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_reaction',
			function ( $reaction_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $reaction_data;
			},
			10,
			1
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'reaction'  => array(
									'mid'    => 'mid.react123',
									'action' => 'react',
									'emoji'  => '👍',
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_reaction action should fire' );
		$this->assertEquals( 'react', $fired_data['action'] );
		$this->assertEquals( 'mid.react123', $fired_data['mid'] );
	}

	/**
	 * Test that message echo event fires the correct WordPress action.
	 */
	public function test_message_echo_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired = false;

		add_action(
			'wp_mcp_ai_messenger_message_echo',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '123456789' ),
								'recipient' => array( 'id' => '9876543210' ),
								'timestamp' => time(),
								'message'   => array(
									'mid'     => 'mid.echo123',
									'text'    => 'Echo message',
									'is_echo' => true,
									'app_id'  => 12345,
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_message_echo action should fire for echo messages' );
	}

	/**
	 * Test that opt-in event fires the correct WordPress action.
	 */
	public function test_optin_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_optin',
			function ( $optin_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $optin_data;
			},
			10,
			1
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'optin'     => array(
									'ref'  => 'PASS_THROUGH_PARAM',
									'type' => 'one_time_notif_req',
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_optin action should fire' );
		$this->assertEquals( 'PASS_THROUGH_PARAM', $fired_data['ref'] );
		$this->assertEquals( 'one_time_notif_req', $fired_data['type'] );
	}

	/**
	 * Test that referral event fires the correct WordPress action.
	 */
	public function test_referral_fires_action() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$fired      = false;
		$fired_data = null;

		add_action(
			'wp_mcp_ai_messenger_referral',
			function ( $referral_data ) use ( &$fired, &$fired_data ) {
				$fired      = true;
				$fired_data = $referral_data;
			},
			10,
			1
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_json_params(
			array(
				'object' => 'page',
				'entry'  => array(
					array(
						'id'        => '123456789',
						'time'      => time(),
						'messaging' => array(
							array(
								'sender'    => array( 'id' => '9876543210' ),
								'recipient' => array( 'id' => '123456789' ),
								'timestamp' => time(),
								'referral'  => array(
									'ref'    => 'REF_DATA_PASSED_IN_M.ME_LINK',
									'source' => 'SHORTLINK',
									'type'   => 'OPEN_THREAD',
								),
							),
						),
					),
				),
			)
		);

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$controller->handle_webhook( $request );

		$this->assertTrue( $fired, 'wp_mcp_ai_messenger_referral action should fire' );
		$this->assertEquals( 'REF_DATA_PASSED_IN_M.ME_LINK', $fired_data['ref'] );
		$this->assertEquals( 'SHORTLINK', $fired_data['source'] );
		$this->assertEquals( 'OPEN_THREAD', $fired_data['type'] );
	}

	/**
	 * Test validate_webhook_signature returns true when App Secret is not configured.
	 *
	 * This mirrors the fix for WhatsApp: when no App Secret is stored, we skip
	 * HMAC validation and allow the webhook through rather than rejecting it with
	 * 401/403 (which would cause Meta to retry endlessly and never deliver messages).
	 */
	public function test_validate_signature_allows_when_no_app_secret_configured() {
		if ( ! $this->load_controller() ) {
			return;
		}

		// Create a connection WITHOUT an App Secret.
		$this->create_messenger_connection( array( 'api_secret' => '' ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_body( '{"object":"page","entry":[]}' );
		// Intentionally omit the X-Hub-Signature-256 header.

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$result     = $controller->validate_webhook_signature( $request );

		$this->assertTrue( $result, 'Should allow webhook through when App Secret is not configured (skip validation)' );
	}

	/**
	 * Test validate_webhook_signature returns false when signature header is missing.
	 */
	public function test_validate_signature_fails_without_header() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$this->create_messenger_connection();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		// No signature header set.

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$result     = $controller->validate_webhook_signature( $request );

		$this->assertFalse( $result, 'Should reject request without signature header' );
	}

	/**
	 * Test validate_webhook_signature returns false when signature is invalid.
	 */
	public function test_validate_signature_fails_with_invalid_signature() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$this->create_messenger_connection();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_body( '{"object":"page","entry":[]}' );
		$request->set_header( 'x-hub-signature-256', 'sha256=invalidsignature' );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$result     = $controller->validate_webhook_signature( $request );

		$this->assertFalse( $result, 'Should reject request with invalid signature' );
	}

	/**
	 * Test validate_webhook_signature returns true with correct HMAC-SHA256 signature.
	 */
	public function test_validate_signature_succeeds_with_correct_signature() {
		if ( ! $this->load_controller() ) {
			return;
		}

		$app_secret = 'test_app_secret_67890';
		$body       = '{"object":"page","entry":[]}';
		$signature  = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );

		$this->create_messenger_connection( array( 'api_secret' => $app_secret ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/messenger' );
		$request->set_body( $body );
		$request->set_header( 'x-hub-signature-256', $signature );

		$controller = new WP_MCP_AI_Messenger_Webhook_Controller();
		$result     = $controller->validate_webhook_signature( $request );

		$this->assertTrue( $result, 'Should accept request with correct HMAC-SHA256 signature' );
	}
}
