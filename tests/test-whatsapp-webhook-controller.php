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
		$reflection        = new ReflectionClass( $controller );
		$verify_token_method = $reflection->getMethod( 'get_verify_token' );
		$verify_token_method->setAccessible( true );
		$app_secret_method = $reflection->getMethod( 'get_app_secret' );
		$app_secret_method->setAccessible( true );

		$verify_token = $verify_token_method->invoke( $controller );
		$app_secret   = $app_secret_method->invoke( $controller );

		$this->assertEquals( '', $verify_token, 'Should return empty string when no connection exists' );
		$this->assertEquals( '', $app_secret, 'Should return empty string when no connection exists' );
	}
}
