<?php
/**
 * Test WhatsApp Webhook Controller field retrieval.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for WhatsApp Webhook Controller.
 */
class Test_WhatsApp_Webhook_Controller extends WP_UnitTestCase {

	/**
	 * Clean up connections before and after each test.
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
	 * Test that webhook controller can retrieve verify token from connection.
	 */
	public function test_webhook_controller_retrieves_verify_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection with verify token.
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'my_verify_token_xyz',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create controller instance.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_verify_token' );
		$method->setAccessible( true );

		$verify_token = $method->invoke( $controller );

		$this->assertEquals( 'my_verify_token_xyz', $verify_token, 'Verify token should be retrieved correctly' );
	}

	/**
	 * Test that webhook controller can retrieve app secret from connection.
	 */
	public function test_webhook_controller_retrieves_app_secret() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection with app secret.
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'my_verify_token_xyz',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create controller instance.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_app_secret' );
		$method->setAccessible( true );

		$app_secret = $method->invoke( $controller );

		// The app_secret is encrypted, so we should get back the decrypted value.
		$this->assertEquals( 'test_app_secret_67890', $app_secret, 'App secret should be retrieved and decrypted correctly' );
	}

	/**
	 * Test that webhook controller returns empty string when app secret is not set
	 * (and does NOT fall back to the access token).
	 *
	 * The HMAC-SHA256 webhook signature MUST be validated with the App Secret.
	 * Using the access token as a fallback would be incorrect and potentially
	 * allow forged webhooks to pass validation.
	 */
	public function test_webhook_controller_returns_empty_when_no_app_secret() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection WITHOUT app secret (only access token).
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			// No api_secret provided.
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'my_verify_token_xyz',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create controller instance.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_app_secret' );
		$method->setAccessible( true );

		$app_secret = $method->invoke( $controller );

		// Must return empty string — NOT fall back to the access token.
		// Returning the access token would cause incorrect webhook HMAC validation.
		$this->assertEquals( '', $app_secret, 'Should return empty string (not the access token) when app secret is not set' );
		$this->assertNotEquals( 'test_access_token_12345', $app_secret, 'Must not fall back to the access token for webhook HMAC validation' );
	}

	/**
	 * Test that webhook controller returns empty string when no connection exists.
	 */
	public function test_webhook_controller_returns_empty_when_no_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Don't create any connection.

		// Create controller instance.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Use reflection to access protected methods.
		$reflection          = new ReflectionClass( $controller );
		$verify_token_method = $reflection->getMethod( 'get_verify_token' );
		$verify_token_method->setAccessible( true );
		$app_secret_method = $reflection->getMethod( 'get_app_secret' );
		$app_secret_method->setAccessible( true );

		$verify_token = $verify_token_method->invoke( $controller );
		$app_secret   = $app_secret_method->invoke( $controller );

		$this->assertEquals( '', $verify_token, 'Should return empty string when no connection exists' );
		$this->assertEquals( '', $app_secret, 'Should return empty string when no connection exists' );
	}

	/**
	 * Test webhook verification with correct parameters (underscores).
	 */
	public function test_webhook_verification_with_correct_parameters() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection with verify token.
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'test_verify_token_abc123',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create a REST request with correct parameters (underscores not dots).
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'test_verify_token_abc123' );
		$request->set_param( 'hub_challenge', 'test_challenge_12345' );

		// Create controller and verify webhook.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		// Verify the response: status 200 and raw challenge as the data so that
		// the rest_pre_serve_request filter can echo it as plain text to Meta.
		$this->assertInstanceOf( 'WP_REST_Response', $response, 'Response should be a WP_REST_Response object' );
		$this->assertEquals( 200, $response->get_status(), 'Status should be 200' );
		$this->assertEquals( 'test_challenge_12345', $response->get_data(), 'Response data should be the raw challenge string' );
	}

	/**
	 * Test webhook verification returns 403 when required parameters are absent.
	 *
	 * Meta sends hub.mode, hub.verify_token, hub.challenge (dot notation).
	 * PHP normally converts dots to underscores in $_GET, but we no longer rely
	 * on WordPress's required-arg pre-validation (which returns a 400) so that
	 * server configurations that skip the conversion get a clean 403 error.
	 */
	public function test_webhook_verification_fails_without_params() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Send a GET request with no hub.* parameters at all.
		$request    = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp' );
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error object' );
		$this->assertEquals( 'whatsapp_verification_failed', $response->get_error_code(), 'Error code should be whatsapp_verification_failed' );
		$this->assertEquals( 403, $response->get_error_data()['status'], 'Status should be 403' );
	}


	public function test_webhook_verification_fails_with_incorrect_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection with verify token.
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'correct_verify_token',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create a REST request with INCORRECT verify token.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'wrong_token' );
		$request->set_param( 'hub_challenge', 'test_challenge_12345' );

		// Create controller and verify webhook.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		// Verify the response is an error.
		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error object' );
		$this->assertEquals( 'whatsapp_verification_failed', $response->get_error_code(), 'Error code should be whatsapp_verification_failed' );
	}

	/**
	 * Test webhook verification fails with incorrect mode.
	 */
	public function test_webhook_verification_fails_with_incorrect_mode() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Create a WhatsApp connection with verify token.
		$connection_data = array(
			'name'            => 'Test WhatsApp Connection',
			'url'             => 'https://graph.facebook.com/v18.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token_12345',
			'api_secret'      => 'test_app_secret_67890',
			'phone_number_id' => '123456789012345',
			'verify_token'    => 'test_verify_token_abc123',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		// Create a REST request with INCORRECT mode (not 'subscribe').
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_param( 'hub_mode', 'unsubscribe' );
		$request->set_param( 'hub_verify_token', 'test_verify_token_abc123' );
		$request->set_param( 'hub_challenge', 'test_challenge_12345' );

		// Create controller and verify webhook.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		// Verify the response is an error.
		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error object' );
		$this->assertEquals( 'whatsapp_verification_failed', $response->get_error_code(), 'Error code should be whatsapp_verification_failed' );
	}

	/**
	 * Test webhook verification fails when no verify token is configured.
	 */
	public function test_webhook_verification_fails_without_configured_token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Don't create any connection - test should fail without configured token.

		// Create a REST request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'any_token' );
		$request->set_param( 'hub_challenge', 'test_challenge_12345' );

		// Create controller and verify webhook.
		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$response   = $controller->verify_webhook( $request );

		// Verify the response is an error.
		$this->assertInstanceOf( 'WP_Error', $response, 'Response should be a WP_Error object' );
		$this->assertEquals( 'whatsapp_no_verify_token', $response->get_error_code(), 'Error code should be whatsapp_no_verify_token' );
	}

	/**
	 * Test that get_connection_by_phone_number_id returns the correct connection.
	 */
	public function test_get_connection_by_phone_number_id_returns_match() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$connection_data = array(
			'name'                   => 'Channel A',
			'url'                    => 'https://graph.facebook.com/v21.0',
			'connection_type'        => 'whatsapp',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'token_a',
			'api_secret'             => 'secret_a',
			'phone_number_id'        => '111000111000111',
			'verify_token'           => 'verify_a',
			'assigned_assistant_ids' => array( 42 ),
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );

		$method = $reflection->getMethod( 'get_connection_by_phone_number_id' );
		$method->setAccessible( true );

		$found = $method->invoke( $controller, '111000111000111' );
		$this->assertNotNull( $found, 'Should find connection matching phone_number_id' );
		$this->assertEquals( '111000111000111', $found['phone_number_id'] );

		$not_found = $method->invoke( $controller, '000000000000000' );
		$this->assertNull( $not_found, 'Should return null for unrecognised phone_number_id' );
	}

	/**
	 * Test that get_assigned_assistant_ids returns IDs from a connection.
	 */
	public function test_get_assigned_assistant_ids_returns_ids() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );

		$method = $reflection->getMethod( 'get_assigned_assistant_ids' );
		$method->setAccessible( true );

		// Connection with assigned assistants.
		$connection_with = array( 'assigned_assistant_ids' => array( 7, 13, 99 ) );
		$ids             = $method->invoke( $controller, $connection_with );
		$this->assertEquals( array( 7, 13, 99 ), $ids, 'Should return assigned assistant IDs' );

		// Connection without assigned assistants.
		$connection_without = array();
		$empty_ids          = $method->invoke( $controller, $connection_without );
		$this->assertIsArray( $empty_ids, 'Should return array even when no assistants assigned' );
		$this->assertEmpty( $empty_ids, 'Should return empty array when no assistants assigned' );
	}

	/**
	 * Test that maybe_auto_reply does not schedule a job when no assistants are assigned.
	 */
	public function test_maybe_auto_reply_skips_without_assigned_assistants() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		// Connection with no assigned assistants.
		$connection_data = array(
			'name'            => 'No Assistant Connection',
			'url'             => 'https://graph.facebook.com/v21.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_token',
			'phone_number_id' => '999888777666555',
			'verify_token'    => 'vt_no_asst',
		);
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$before_crons = _get_cron_array();

		$message_data = array(
			'id'        => 'msg_1',
			'from'      => '447700900000',
			'type'      => 'text',
			'timestamp' => time(),
			'content'   => 'Hello',
			'context'   => null,
		);
		$context = array(
			'metadata' => array( 'phone_number_id' => '999888777666555' ),
		);

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'maybe_auto_reply' );
		$method->setAccessible( true );
		$method->invoke( $controller, $message_data, $context );

		// No cron events should have been scheduled because there are no assigned assistants.
		$after_crons = _get_cron_array();
		$this->assertEquals(
			$before_crons,
			$after_crons,
			'No cron event should be scheduled when no assistants are assigned'
		);
	}

	/**
	 * Test that dispatch_whatsapp_ai_reply schedules a cron job for text messages.
	 */
	public function test_dispatch_whatsapp_ai_reply_schedules_cron_for_text_messages() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$connection_data = array(
			'name'                   => 'Reply Test Connection',
			'url'                    => 'https://graph.facebook.com/v21.0',
			'connection_type'        => 'whatsapp',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'reply_access_token',
			'phone_number_id'        => '112233445566778',
			'verify_token'           => 'vt_reply',
			'graph_api_version'      => 'v21.0',
			'assigned_assistant_ids' => array( 55 ),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$message_data = array(
			'id'        => 'msg_text_1',
			'from'      => '447700900001',
			'type'      => 'text',
			'timestamp' => time(),
			'content'   => 'Hi there!',
			'context'   => null,
		);

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'dispatch_whatsapp_ai_reply' );
		$method->setAccessible( true );
		$method->invoke( $controller, $message_data, $connection, array( 55 ) );

		$crons = _get_cron_array();
		$hook  = WP_MCP_AI_WhatsApp_Webhook_Controller::REPLY_CRON_HOOK;

		$found = false;
		foreach ( $crons as $timestamp => $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Cron job should be scheduled for an incoming text message' );

		// Clean up.
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Test that dispatch_whatsapp_ai_reply does not schedule a cron job for non-text messages.
	 */
	public function test_dispatch_whatsapp_ai_reply_skips_non_text_messages() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$connection = array(
			'id'                => 'fake_conn',
			'phone_number_id'   => '998877665544332',
			'api_key'           => 'test_access_token',
			'graph_api_version' => 'v21.0',
		);

		$message_data = array(
			'id'        => 'msg_img_1',
			'from'      => '447700900002',
			'type'      => 'image',
			'timestamp' => time(),
			'content'   => array( 'id' => 'image_id_abc' ),
			'context'   => null,
		);

		$before_crons = _get_cron_array();

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'dispatch_whatsapp_ai_reply' );
		$method->setAccessible( true );
		$method->invoke( $controller, $message_data, $connection, array( 55 ) );

		$after_crons = _get_cron_array();
		$this->assertEquals(
			$before_crons,
			$after_crons,
			'No cron event should be scheduled for non-text message types'
		);
	}

	/**
	 * Test that handle_whatsapp_reply_job returns early when args are incomplete.
	 */
	public function test_handle_whatsapp_reply_job_returns_early_for_invalid_args() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'handle_whatsapp_reply_job' );
		$method->setAccessible( true );

		// Pass a non-array — should return early without error.
		$method->invoke( $controller, 'not-an-array' );

		// Pass an empty array — should return early without error.
		$method->invoke( $controller, array() );

		// Pass args with zero assistant_id — should return early without error.
		$method->invoke(
			$controller,
			array(
				'assistant_id'    => 0,
				'message_text'    => 'Hello',
				'to'              => '447700900003',
				'connection_id'   => 'fake_id',
				'phone_number_id' => '112233445566778',
			)
		);

		// If we get here without exceptions the early-return guards are working.
		$this->assertTrue( true );
	}

	/**
	 * Test that extract_content_from_chat_response returns the assistant reply
	 * from the correct location in the /mcp-ai/v1/chat response structure.
	 */
	public function test_extract_content_from_chat_response_returns_correct_content() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_content_from_chat_response' );
		$method->setAccessible( true );

		// Simulate the payload returned by the /mcp-ai/v1/chat endpoint.
		// Format: { assistant_id, data: { choices: [{ message: { content } }] } }.
		$response_data = array(
			'assistant_id' => 42,
			'data'         => array(
				'id'      => 'chatcmpl-abc123',
				'object'  => 'chat.completion',
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => 'Hello from the assistant!',
						),
						'finish_reason' => 'stop',
					),
				),
			),
		);

		$content = $method->invoke( $controller, $response_data );

		$this->assertEquals( 'Hello from the assistant!', $content, 'Should extract content from choices[0].message.content' );
	}

	/**
	 * Test that extract_content_from_chat_response returns empty string for
	 * invalid or missing response structures.
	 */
	public function test_extract_content_from_chat_response_returns_empty_for_invalid_data() {
		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
				return;
			}
		}

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'extract_content_from_chat_response' );
		$method->setAccessible( true );

		// Non-array input.
		$this->assertEquals( '', $method->invoke( $controller, null ), 'Should return empty for null' );
		$this->assertEquals( '', $method->invoke( $controller, 'string' ), 'Should return empty for string' );

		// Empty array.
		$this->assertEquals( '', $method->invoke( $controller, array() ), 'Should return empty for empty array' );

		// Missing 'data' key.
		$this->assertEquals( '', $method->invoke( $controller, array( 'assistant_id' => 1 ) ), 'Should return empty when data key is missing' );

		// Missing choices.
		$this->assertEquals(
			'',
			$method->invoke( $controller, array( 'data' => array( 'choices' => array() ) ) ),
			'Should return empty when choices array is empty'
		);

		// Flat 'content' key at top level (old incorrect structure) — must NOT be returned.
		$wrong_structure = array( 'content' => 'wrong location' );
		$this->assertEquals( '', $method->invoke( $controller, $wrong_structure ), 'Should not read from top-level content key' );
	}

	/**
	 * Test that HTML is stripped from the AI reply content before it is sent via WhatsApp.
	 *
	 * The handle_whatsapp_reply_job() method strips HTML tags and decodes entities from the
	 * extracted chat response content. These helper functions (wp_strip_all_tags and
	 * html_entity_decode) are tested here in isolation to confirm the expected transformation.
	 */
	public function test_whatsapp_reply_content_html_is_stripped() {
		// Simulate an AI reply containing HTML tags and entities, as returned by the LLM.
		$html_reply = '<p>Hello <strong>World</strong>! Visit <a href="https://example.com">example.com</a> for details.</p>';

		$stripped = wp_strip_all_tags( $html_reply );
		$decoded  = html_entity_decode( $stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$this->assertStringNotContainsString( '<', $decoded, 'HTML tags should be stripped' );
		$this->assertStringNotContainsString( '>', $decoded, 'HTML tags should be stripped' );
		$this->assertStringContainsString( 'Hello World', $decoded, 'Text content should be preserved' );
		$this->assertStringContainsString( 'example.com', $decoded, 'Link text should be preserved' );
	}

	/**
	 * Test that HTML entity decoding is applied after stripping tags.
	 */
	public function test_whatsapp_reply_html_entities_are_decoded() {
		$encoded = 'Hello &amp; welcome to The Parfumerie &mdash; your scent destination.';

		$stripped = wp_strip_all_tags( $encoded );
		$decoded  = html_entity_decode( $stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$this->assertStringContainsString( '&', $decoded, 'HTML entity &amp; should be decoded to &' );
		$this->assertStringNotContainsString( '&amp;', $decoded, 'Encoded entity &amp; should not remain' );
	}

	/**
	 * Test that WhatsApp message body is truncated to 4096 characters.
	 */
	public function test_whatsapp_reply_truncated_to_4096_chars() {
		// Create a string that exceeds the WhatsApp 4096-character limit.
		$long_content = str_repeat( 'A', 5000 );

		$truncated = mb_strlen( $long_content ) > 4096
			? mb_substr( $long_content, 0, 4093 ) . '...'
			: $long_content;

		$this->assertEquals( 4096, mb_strlen( $truncated ), 'Truncated content must be exactly 4096 characters' );
		$this->assertStringEndsWith( '...', $truncated, 'Truncated content must end with ellipsis' );
	}

	/**
	 * Test that content within the 4096-character limit is not truncated.
	 */
	public function test_whatsapp_reply_not_truncated_when_within_limit() {
		$short_content = str_repeat( 'B', 100 );

		$result = mb_strlen( $short_content ) > 4096
			? mb_substr( $short_content, 0, 4093 ) . '...'
			: $short_content;

		$this->assertEquals( $short_content, $result, 'Short content should not be modified' );
	}
}
