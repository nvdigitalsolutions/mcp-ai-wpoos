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
	 * Test that webhook controller falls back to access token if app secret is not set.
	 */
	public function test_webhook_controller_fallback_to_access_token() {
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
			// No api_secret provided
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

		// Should fall back to access token.
		$this->assertEquals( 'test_access_token_12345', $app_secret, 'Should fall back to access token when app secret is not set' );
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
}
