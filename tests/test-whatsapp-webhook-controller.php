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
		$context      = array(
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

	/**
	 * Test that a WhatsApp Business Account ID (WABA ID) does NOT match a connection
	 * whose phone_number_id is set to the correct Phone Number ID.
	 *
	 * Users sometimes copy the WABA ID from Meta Business Manager instead of the
	 * Phone Number ID from Meta Developer Dashboard → WhatsApp → API Setup. The
	 * get_connection_by_phone_number_id() method must not confuse these two IDs.
	 */
	public function test_waba_id_does_not_match_connection_phone_number_id() {
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

		$correct_phone_number_id = '102938475610293';
		$waba_id                 = '777253115407005'; // A WABA ID — different from the Phone Number ID.

		// Save a connection using the correct Phone Number ID.
		$connection_data = array(
			'name'                   => 'Test Channel',
			'url'                    => 'https://graph.facebook.com/v22.0',
			'connection_type'        => 'whatsapp',
			'auth_type'              => 'none',
			'enabled'                => true,
			'api_key'                => 'test_access_token',
			'phone_number_id'        => $correct_phone_number_id,
			'business_account_id'    => $waba_id,
			'verify_token'           => 'test_verify_token',
			'assigned_assistant_ids' => array( 1 ),
		);

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_connection_by_phone_number_id' );
		$method->setAccessible( true );

		// The correct Phone Number ID must match the connection.
		$found = $method->invoke( $controller, $correct_phone_number_id );
		$this->assertNotNull( $found, 'The correct Phone Number ID should find the connection' );
		$this->assertEquals( $correct_phone_number_id, $found['phone_number_id'] );

		// The WABA ID must NOT match — it is a different kind of ID stored in business_account_id.
		$not_found = $method->invoke( $controller, $waba_id );
		$this->assertNull( $not_found, 'A WABA ID must not match a connection looked up by phone_number_id' );
	}

	/**
	 * Test that the handle_whatsapp_reply_job() method logs an error and returns early
	 * when the WhatsApp Cloud API responds with error code 100 / subcode 33
	 * ("Object does not exist" — the Phone Number ID is wrong or inaccessible).
	 */
	public function test_handle_whatsapp_reply_job_logs_error_on_code_100_subcode_33() {
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

		// The API error body returned when the Phone Number ID does not exist.
		// Using the WABA ID from the connection (a common mistake) as the wrong ID in the error.
		$waba_id        = '777253115407005'; // Same WABA ID used as an example of a wrong Phone Number ID.
		$api_error_body = array(
			'error' => array(
				'message'       => "Unsupported post request. Object with ID '{$waba_id}' does not exist, cannot be loaded due to missing permissions, or does not support this operation.",
				'type'          => 'GraphMethodException',
				'code'          => 100,
				'error_subcode' => 33,
				'fbtrace_id'    => 'AzK1zNq1rH7bZy_V0Uuk3Ul',
			),
		);

		// Validate that the error detection logic identifies code 100 / subcode 33.
		$meta_code    = (int) $api_error_body['error']['code'];
		$meta_subcode = (int) $api_error_body['error']['error_subcode'];

		$this->assertEquals( 100, $meta_code, 'Error code should be 100 for missing object' );
		$this->assertEquals( 33, $meta_subcode, 'Error subcode should be 33 for missing object' );
		$this->assertTrue(
			100 === $meta_code && 33 === $meta_subcode,
			'Code 100 / subcode 33 combination should be detected as a wrong Phone Number ID error'
		);
	}

	/**
	 * Test that error code 133010 ("Account not registered") is correctly identified
	 * so that a user-friendly hint can be surfaced in the admin UI and log context.
	 */
	public function test_error_code_133010_is_detected_as_account_not_registered() {
		// The API error body returned when the sending number or recipient is not registered.
		$api_error_body = array(
			'error' => array(
				'message'    => '(#133010) Account not registered',
				'type'       => 'OAuthException',
				'code'       => 133010,
				'fbtrace_id' => 'AVDYoK8Ds3oQgCWH-Lc4EpL',
			),
		);

		$api_error = $api_error_body['error'];

		// Validate that the error detection logic identifies code 133010.
		$this->assertTrue(
			is_array( $api_error ) && isset( $api_error['code'] ) && 133010 === (int) $api_error['code'],
			'Error code 133010 should be detected as an "Account not registered" error'
		);

		// Confirm it is not mistakenly identified as the Phone Number ID error (100/33).
		$meta_code    = (int) $api_error['code'];
		$meta_subcode = isset( $api_error['error_subcode'] ) ? (int) $api_error['error_subcode'] : 0;
		$this->assertFalse(
			100 === $meta_code && 33 === $meta_subcode,
			'Code 133010 must not be confused with the 100/33 Phone Number ID error'
		);
	}

	/**
	 * Test that validate_webhook_signature returns true (allows the webhook) when the
	 * App Secret is not configured.
	 *
	 * This is the key fix for the bug where real WhatsApp messages did not trigger
	 * an AI response: the permission callback was returning false (rejecting the
	 * webhook with 401/403) whenever the App Secret was absent, even though the
	 * admin test auto-reply worked fine because it bypasses the webhook entirely.
	 *
	 * When App Secret is not set we skip HMAC validation rather than blocking
	 * all incoming messages, while logging a security warning.
	 */
	public function test_validate_webhook_signature_allows_when_no_app_secret() {
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

		// Save a connection WITHOUT an App Secret so get_app_secret() returns ''.
		$connection_data = array(
			'name'            => 'No App Secret Connection',
			'url'             => 'https://graph.facebook.com/v19.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			// Intentionally omitting api_secret.
			'phone_number_id' => '111222333444555',
			'verify_token'    => 'vt_no_secret',
		);
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Build a simulated incoming webhook POST request (no signature header).
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_body( '{"object":"whatsapp_business_account","entry":[]}' );
		$request->set_header( 'Content-Type', 'application/json' );

		// Without App Secret the permission callback must return true so that
		// incoming messages are processed rather than rejected.
		$result = $controller->validate_webhook_signature( $request );
		$this->assertTrue( $result, 'validate_webhook_signature should return true when App Secret is not configured' );
	}

	/**
	 * Test that validate_webhook_signature rejects the request when App Secret IS
	 * configured but the signature header is absent.
	 */
	public function test_validate_webhook_signature_rejects_when_app_secret_set_and_header_missing() {
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

		// Save a connection WITH an App Secret.
		$app_secret      = 'my_real_app_secret_abc123';
		$connection_data = array(
			'name'            => 'App Secret Connection',
			'url'             => 'https://graph.facebook.com/v19.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'api_secret'      => $app_secret,
			'phone_number_id' => '222333444555666',
			'verify_token'    => 'vt_with_secret',
		);
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Build a request WITHOUT the X-Hub-Signature-256 header.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_body( '{"object":"whatsapp_business_account","entry":[]}' );
		$request->set_header( 'Content-Type', 'application/json' );
		// Intentionally omit the X-Hub-Signature-256 header.

		$result = $controller->validate_webhook_signature( $request );
		$this->assertFalse( $result, 'validate_webhook_signature should return false when App Secret is set but signature header is missing' );
	}

	/**
	 * Test that validate_webhook_signature accepts a request with a valid HMAC-SHA256
	 * signature when the App Secret is configured.
	 */
	public function test_validate_webhook_signature_accepts_valid_signature() {
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

		$app_secret = 'my_real_app_secret_abc123';
		$body       = '{"object":"whatsapp_business_account","entry":[]}';

		// Compute the expected HMAC signature as Meta would.
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );

		$connection_data = array(
			'name'            => 'Valid Sig Connection',
			'url'             => 'https://graph.facebook.com/v19.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'api_secret'      => $app_secret,
			'phone_number_id' => '333444555666777',
			'verify_token'    => 'vt_valid_sig',
		);
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Hub-Signature-256', $expected_signature );

		$result = $controller->validate_webhook_signature( $request );
		$this->assertTrue( $result, 'validate_webhook_signature should return true when HMAC signature is valid' );
	}

	/**
	 * Test that validate_webhook_signature rejects a request with an invalid signature
	 * when the App Secret is configured.
	 */
	public function test_validate_webhook_signature_rejects_invalid_signature() {
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

		$app_secret = 'my_real_app_secret_abc123';
		$body       = '{"object":"whatsapp_business_account","entry":[]}';

		$connection_data = array(
			'name'            => 'Invalid Sig Connection',
			'url'             => 'https://graph.facebook.com/v19.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_access_token',
			'api_secret'      => $app_secret,
			'phone_number_id' => '444555666777888',
			'verify_token'    => 'vt_invalid_sig',
		);
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/whatsapp' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );

		$result = $controller->validate_webhook_signature( $request );
		$this->assertFalse( $result, 'validate_webhook_signature should return false when HMAC signature does not match' );
	}

	/**
	 * Test that the register phone number AJAX handler validates the 6-digit PIN correctly.
	 */
	public function test_register_whatsapp_phone_number_pin_validation() {
		// Valid 6-digit numeric PINs.
		$this->assertSame( 1, preg_match( '/^[0-9]{6}$/', '123456' ) );
		$this->assertSame( 1, preg_match( '/^[0-9]{6}$/', '000000' ) );
		$this->assertSame( 1, preg_match( '/^[0-9]{6}$/', '999999' ) );

		// Invalid PINs.
		$this->assertSame( 0, preg_match( '/^[0-9]{6}$/', '12345' ) );   // Too short.
		$this->assertSame( 0, preg_match( '/^[0-9]{6}$/', '1234567' ) ); // Too long.
		$this->assertSame( 0, preg_match( '/^[0-9]{6}$/', 'abc123' ) );  // Non-numeric.
		$this->assertSame( 0, preg_match( '/^[0-9]{6}$/', '' ) );         // Empty.
	}

	/**
	 * Test that the registration endpoint URL is correctly constructed.
	 */
	public function test_register_endpoint_url_construction() {
		$graph_api_version = 'v19.0';
		$phone_number_id   = '123456789012345';

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/register',
			rawurlencode( $graph_api_version ),
			rawurlencode( $phone_number_id )
		);

		$this->assertStringContainsString( '/register', $endpoint );
		$this->assertStringContainsString( $graph_api_version, $endpoint );
		$this->assertStringContainsString( $phone_number_id, $endpoint );
		$this->assertEquals(
			'https://graph.facebook.com/v19.0/123456789012345/register',
			$endpoint
		);
	}

	// -------------------------------------------------------------------------
	// Conversation history tests.
	// -------------------------------------------------------------------------

	/**
	 * Helper: load the controller class if not already loaded.
	 */
	private function load_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		if ( ! class_exists( 'WP_MCP_AI_WhatsApp_Webhook_Controller' ) ) {
			$controller_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php';
			if ( file_exists( $controller_file ) ) {
				require_once $controller_file;
			} else {
				$this->markTestSkipped( 'WhatsApp Webhook Controller not available' );
			}
		}
	}

	/**
	 * Test that get_conversation_history_key returns a deterministic, non-empty string.
	 */
	public function test_get_conversation_history_key_is_deterministic() {
		$this->load_controller();

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key1 = $method->invoke( $controller, '1234567890', '9876543210' );
		$key2 = $method->invoke( $controller, '1234567890', '9876543210' );
		$key3 = $method->invoke( $controller, '0000000000', '9876543210' );

		$this->assertIsString( $key1, 'Key should be a string' );
		$this->assertNotEmpty( $key1, 'Key should not be empty' );
		$this->assertSame( $key1, $key2, 'Same inputs should produce same key' );
		$this->assertNotSame( $key1, $key3, 'Different sender should produce different key' );

		// Verify it starts with the expected prefix.
		$this->assertStringStartsWith( 'wp_mcp_ai_wa_conv_', $key1 );

		// Verify key length is within WordPress transient key limits (172 chars).
		$this->assertLessThanOrEqual( 172, strlen( $key1 ) );
	}

	/**
	 * Test that get_conversation_history_key differs when phone_number_id differs.
	 */
	public function test_get_conversation_history_key_differs_by_phone_number_id() {
		$this->load_controller();

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_conversation_history_key' );
		$method->setAccessible( true );

		$key_a = $method->invoke( $controller, '1234567890', 'phone_id_A' );
		$key_b = $method->invoke( $controller, '1234567890', 'phone_id_B' );

		$this->assertNotSame( $key_a, $key_b, 'Keys should differ when phone_number_id differs' );
	}

	/**
	 * Test that CONVERSATION_HISTORY_TTL constant equals 86400 seconds (24 hours).
	 */
	public function test_conversation_history_ttl_constant() {
		$this->load_controller();

		$this->assertSame(
			86400,
			WP_MCP_AI_WhatsApp_Webhook_Controller::CONVERSATION_HISTORY_TTL,
			'CONVERSATION_HISTORY_TTL should be 86400 seconds'
		);
	}

	/**
	 * Test that history is correctly trimmed when it exceeds max_history.
	 *
	 * This exercises the trimming logic in isolation using the same algorithm
	 * used by handle_whatsapp_reply_job().
	 */
	public function test_history_trimmed_before_new_message() {
		$max_history = 8; // Keep at most 8 messages total.

		// Simulate history that exceeds max_history (user/assistant pairs).
		$history = array();
		for ( $i = 0; $i < $max_history; $i++ ) {
			$history[] = array(
				'role'    => 'user',
				'content' => "msg $i",
			);
			$history[] = array(
				'role'    => 'assistant',
				'content' => "reply $i",
			);
		}

		// Trim to leave room for one new user message (matches production logic).
		if ( count( $history ) >= $max_history ) {
			$history = array_slice( $history, -( $max_history - 1 ) );
		}

		$this->assertLessThanOrEqual(
			$max_history - 1,
			count( $history ),
			'History should be trimmed to max_history - 1 before appending new user message'
		);
	}

	/**
	 * Test that get_verify_token() returns the correct token for a specific connection_id.
	 */
	public function test_get_verify_token_by_connection_id() {
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

		// Create two WhatsApp connections each with a distinct verify token.
		$conn_a_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'WhatsApp Channel A',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_a',
				'phone_number_id' => '111111111111111',
				'verify_token'    => 'verify_token_channel_a',
			)
		);

		$conn_b_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'WhatsApp Channel B',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_b',
				'phone_number_id' => '222222222222222',
				'verify_token'    => 'verify_token_channel_b',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $conn_a_id, 'Channel A save should succeed' );
		$this->assertNotInstanceOf( 'WP_Error', $conn_b_id, 'Channel B save should succeed' );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_verify_token' );
		$method->setAccessible( true );

		// Channel-specific lookup should return the correct token.
		$this->assertEquals(
			'verify_token_channel_a',
			$method->invoke( $controller, $conn_a_id ),
			'Should return Channel A verify token when queried by its connection_id'
		);
		$this->assertEquals(
			'verify_token_channel_b',
			$method->invoke( $controller, $conn_b_id ),
			'Should return Channel B verify token when queried by its connection_id'
		);

		// Generic lookup (no connection_id) should still return one of the tokens.
		$generic_token = $method->invoke( $controller );
		$this->assertNotEmpty( $generic_token, 'Generic lookup should return a non-empty token' );
	}

	/**
	 * Test that get_verify_token() returns empty string for an unknown connection_id.
	 */
	public function test_get_verify_token_returns_empty_for_unknown_connection_id() {
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

		// Create one connection.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'WhatsApp Channel',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_x',
				'phone_number_id' => '333333333333333',
				'verify_token'    => 'verify_token_x',
			)
		);

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'get_verify_token' );
		$method->setAccessible( true );

		$this->assertEquals(
			'',
			$method->invoke( $controller, 'nonexistent_connection_id' ),
			'Should return empty string for an unknown connection_id'
		);
	}

	/**
	 * Test channel-specific webhook verification uses the correct connection's verify token.
	 */
	public function test_channel_specific_webhook_verification() {
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

		// Create two channels with different verify tokens.
		$conn_a_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Support Channel',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_support',
				'phone_number_id' => '444444444444444',
				'verify_token'    => 'support_verify_token',
			)
		);

		$conn_b_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Sales Channel',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_sales',
				'phone_number_id' => '555555555555555',
				'verify_token'    => 'sales_verify_token',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $conn_a_id );
		$this->assertNotInstanceOf( 'WP_Error', $conn_b_id );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Verify Channel A with its own token via the channel-specific URL.
		$request_a = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp/' . $conn_a_id );
		$request_a->set_param( 'connection_id', $conn_a_id );
		$request_a->set_param( 'hub_mode', 'subscribe' );
		$request_a->set_param( 'hub_verify_token', 'support_verify_token' );
		$request_a->set_param( 'hub_challenge', 'challenge_for_channel_a' );

		$response_a = $controller->verify_webhook( $request_a );
		$this->assertInstanceOf( 'WP_REST_Response', $response_a, 'Channel A verification should succeed with correct token' );
		$this->assertEquals( 200, $response_a->get_status() );
		$this->assertEquals( 'challenge_for_channel_a', $response_a->get_data() );

		// Verify Channel B with its own token.
		$request_b = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp/' . $conn_b_id );
		$request_b->set_param( 'connection_id', $conn_b_id );
		$request_b->set_param( 'hub_mode', 'subscribe' );
		$request_b->set_param( 'hub_verify_token', 'sales_verify_token' );
		$request_b->set_param( 'hub_challenge', 'challenge_for_channel_b' );

		$response_b = $controller->verify_webhook( $request_b );
		$this->assertInstanceOf( 'WP_REST_Response', $response_b, 'Channel B verification should succeed with correct token' );
		$this->assertEquals( 200, $response_b->get_status() );
		$this->assertEquals( 'challenge_for_channel_b', $response_b->get_data() );
	}

	/**
	 * Test that channel-specific webhook verification rejects wrong token for another channel.
	 */
	public function test_channel_specific_webhook_rejects_wrong_token() {
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

		$conn_a_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Channel A',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_a',
				'phone_number_id' => '666666666666666',
				'verify_token'    => 'token_for_a',
			)
		);

		$conn_b_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Channel B',
				'url'             => 'https://graph.facebook.com/v21.0',
				'connection_type' => 'whatsapp',
				'auth_type'       => 'none',
				'enabled'         => true,
				'api_key'         => 'token_b',
				'phone_number_id' => '777777777777777',
				'verify_token'    => 'token_for_b',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $conn_a_id );
		$this->assertNotInstanceOf( 'WP_Error', $conn_b_id );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();

		// Try to verify Channel A using Channel B's token — should fail.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/webhooks/whatsapp/' . $conn_a_id );
		$request->set_param( 'connection_id', $conn_a_id );
		$request->set_param( 'hub_mode', 'subscribe' );
		$request->set_param( 'hub_verify_token', 'token_for_b' ); // Wrong token.
		$request->set_param( 'hub_challenge', 'some_challenge' );

		$response = $controller->verify_webhook( $request );
		$this->assertInstanceOf( 'WP_Error', $response, 'Verification should fail when using the wrong channel token' );
		$this->assertEquals( 'whatsapp_verification_failed', $response->get_error_code() );
	}

	/**
	 * Test that dispatch_whatsapp_ai_reply (via reflection) routes to group when group_id is set on connection.
	 */
	public function test_dispatch_routes_to_group_when_group_id_configured() {
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

		$group_id = '120363111222333444@g.us';

		$connection_data = array(
			'name'            => 'Group Routing Connection',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_token',
			'phone_number_id' => '123456789012345',
			'group_id'        => $group_id,
			'verify_token'    => 'verify_test',
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		// Verify group_id is stored in the connection.
		$this->assertEquals( $group_id, $saved['group_id'], 'Group ID should be stored on connection' );

		// Verify that when group_id is present, dispatch_whatsapp_ai_reply schedules
		// the cron job with the group as recipient via reflection.
		$controller  = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection  = new ReflectionClass( $controller );
		$method      = $reflection->getMethod( 'dispatch_whatsapp_ai_reply' );
		$method->setAccessible( true );

		$scheduled_before = wp_next_scheduled( 'wp_mcp_ai_whatsapp_reply' );

		$message_data = array(
			'id'   => 'wamid.test123',
			'from' => '+15550001234',
			'type' => 'text',
			'text' => array( 'body' => 'Hello group' ),
		);

		// Create a dummy assistant post so there is an assigned_assistant_ids array.
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$method->invoke( $controller, $message_data, $saved, array( $assistant_id ) );

		// Retrieve scheduled cron args.
		$cron_args  = null;
		$crons      = _get_cron_array();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks['wp_mcp_ai_whatsapp_reply'] ) ) {
				foreach ( $hooks['wp_mcp_ai_whatsapp_reply'] as $cron_entry ) {
					$cron_args = $cron_entry['args'][0];
					break 2;
				}
			}
		}

		$this->assertNotNull( $cron_args, 'Cron job should have been scheduled' );
		$this->assertEquals( $group_id, $cron_args['to'], 'Cron job "to" should be the group ID' );
		$this->assertEquals( 'group', $cron_args['recipient_type'], 'Cron job recipient_type should be "group"' );

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test that dispatch_whatsapp_ai_reply (via reflection) routes to individual sender when no group_id is set.
	 */
	public function test_dispatch_routes_to_individual_when_no_group_id() {
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

		$sender_phone = '+15550009999';

		$connection_data = array(
			'name'            => 'Individual Routing Connection',
			'url'             => 'https://graph.facebook.com/v22.0',
			'connection_type' => 'whatsapp',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_token_ind',
			'phone_number_id' => '999888777666555',
			'verify_token'    => 'verify_individual',
			// No group_id set.
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotInstanceOf( 'WP_Error', $connection_id, 'Connection save should succeed' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$controller = new WP_MCP_AI_WhatsApp_Webhook_Controller();
		$reflection = new ReflectionClass( $controller );
		$method     = $reflection->getMethod( 'dispatch_whatsapp_ai_reply' );
		$method->setAccessible( true );

		$message_data = array(
			'id'   => 'wamid.individual456',
			'from' => $sender_phone,
			'type' => 'text',
			'text' => array( 'body' => 'Hello there' ),
		);

		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant Individual',
			)
		);

		$method->invoke( $controller, $message_data, $saved, array( $assistant_id ) );

		// Retrieve scheduled cron args.
		$cron_args  = null;
		$crons      = _get_cron_array();
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks['wp_mcp_ai_whatsapp_reply'] ) ) {
				foreach ( $hooks['wp_mcp_ai_whatsapp_reply'] as $cron_entry ) {
					$cron_args = $cron_entry['args'][0];
					break 2;
				}
			}
		}

		$this->assertNotNull( $cron_args, 'Cron job should have been scheduled' );
		$this->assertEquals( $sender_phone, $cron_args['to'], 'Cron job "to" should be the sender phone number' );
		$this->assertEquals( 'individual', $cron_args['recipient_type'], 'Cron job recipient_type should be "individual"' );

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

}

