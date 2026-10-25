<?php
/**
 * Tests for the Remote Sites (Connections) REST controller.
 *
 * Covers the console Connections page contract:
 *   - Route registration under mcp-ai-pro/v1/remote-sites.
 *   - manage_options permission gate.
 *   - List redaction (secrets never echoed, has_credentials flags).
 *   - Base-plugin Gmail pseudo-connection aggregation.
 *   - Create / update (blank-secret-keeps) / delete round-trips.
 *   - Type catalogue integrity.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return; // Skip when the pro addon is not available in the test matrix.
}

/**
 * Remote Sites REST controller tests.
 */
class Test_Remote_Sites_REST extends WP_UnitTestCase {

	/**
	 * Option key the manager stores connections under.
	 *
	 * @var string
	 */
	const CONNECTIONS_OPTION = 'wp_mcp_ai_pro_remote_sites';

	/**
	 * Load a pro class from its file path if not already loaded.
	 *
	 * @param string $class_name    PHP class name.
	 * @param string $relative_path Path relative to WP_MCP_AI_PRO_PATH.
	 */
	private function load_pro_class( $class_name, $relative_path ) {
		if ( ! class_exists( $class_name ) ) {
			$full_path = WP_MCP_AI_PRO_PATH . $relative_path;
			if ( file_exists( $full_path ) ) {
				require_once $full_path;
			}
		}
		$this->assertTrue( class_exists( $class_name ), $class_name . ' must be loadable' );
	}

	/**
	 * Load the controller under test, plus the base settings class so the
	 * base-Gmail aggregation path is live.
	 */
	private function load_controller() {
		$this->load_pro_class( 'WP_MCP_AI_Pro_Remote_Site_Manager', 'includes/class-wp-mcp-ai-pro-remote-site-manager.php' );
		$this->load_pro_class( 'WP_MCP_AI_Pro_Remote_Sites_REST_Controller', 'includes/rest/class-wp-mcp-ai-pro-remote-sites-rest-controller.php' );

		if ( defined( 'WP_MCP_AI_PATH' ) && ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
			if ( file_exists( $settings_file ) ) {
				require_once $settings_file;
			}
		}
	}

	/**
	 * Seed a gmail connection in the manager option and return its id.
	 *
	 * @return string Connection id.
	 */
	private function seed_gmail_connection() {
		$connection_data = array(
			'connection_type' => 'gmail',
			'name'            => 'Gmail inbox',
			'url'             => 'https://gmail.googleapis.com',
			'client_id'       => 'client-id-123',
			'client_secret'   => 'client-secret-123',
			'refresh_token'   => 'refresh-token-123',
			'enabled'         => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $result );

		return $result;
	}

	/**
	 * Seed an administrator and set it as the current user.
	 *
	 * @return int User ID.
	 */
	private function login_as_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * Clean up the connections and base-settings options after each test.
	 */
	public function tearDown(): void {
		delete_option( self::CONNECTIONS_OPTION );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_credentials' );
		parent::tearDown();
	}

	/**
	 * The controller registers every console route under the namespace.
	 *
	 * Registering routes outside rest_api_init fires doing-it-wrong, so the
	 * hook is added and a fresh server re-fires the action.
	 */
	public function test_routes_registered() {
		$this->load_controller();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );

		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		$routes                    = $server->get_routes( 'mcp-ai-pro/v1' );

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/remote-sites', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/remote-sites/types', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/remote-sites/(?P<id>[\w-]+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/remote-sites/(?P<id>[\w-]+)/test', $routes );
	}

	/**
	 * Unauthenticated requests are rejected.
	 */
	public function test_permissions_reject_guest() {
		$this->load_controller();

		wp_set_current_user( 0 );

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$result     = $controller->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Administrators are allowed.
	 */
	public function test_permissions_allow_admin() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$this->assertTrue( $controller->permissions_check() );
	}

	/**
	 * The list redacts secrets and flags credential presence.
	 */
	public function test_list_redacts_secrets() {
		$this->load_controller();
		$this->login_as_admin();

		$this->seed_gmail_connection();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/remote-sites' );
		$response   = $controller->get_items( $request );
		$data       = $response->get_data();

		$this->assertIsArray( $data['items'] );
		$this->assertNotEmpty( $data['items'] );

		$gmail_item = null;
		foreach ( $data['items'] as $item ) {
			if ( 'gmail' === $item['connection_type'] && 'pro' === $item['source'] ) {
				$gmail_item = $item;
				break;
			}
		}

		$this->assertNotNull( $gmail_item, 'Seeded gmail connection must appear in the list' );
		$this->assertTrue( $gmail_item['has_credentials'] );
		$this->assertArrayNotHasKey( 'client_secret', $gmail_item );
		$this->assertArrayNotHasKey( 'refresh_token', $gmail_item );
		$this->assertArrayNotHasKey( 'client_id', $gmail_item );
	}

	/**
	 * The base-plugin Gmail settings connection is aggregated into the list.
	 */
	public function test_list_includes_base_gmail() {
		$this->load_controller();
		$this->login_as_admin();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'gmail_client_id'     => 'base-client-id',
				'gmail_refresh_token' => 'base-refresh-token',
				'gmail_user_email'    => 'owner@example.com',
			)
		);

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/remote-sites' );
		$response   = $controller->get_items( $request );
		$data       = $response->get_data();

		$base_item = null;
		foreach ( $data['items'] as $item ) {
			if ( 'base' === $item['source'] ) {
				$base_item = $item;
				break;
			}
		}

		$this->assertNotNull( $base_item, 'Base Gmail pseudo-connection must be listed' );
		$this->assertSame( 'base-gmail', $base_item['id'] );
		$this->assertTrue( $base_item['has_credentials'] );
		$this->assertSame( 'owner@example.com', $base_item['user_email'] );
		$this->assertArrayNotHasKey( 'gmail_refresh_token', $base_item );
	}

	/**
	 * Creating a connection persists it and returns the redacted item.
	 *
	 * Uses the canonical stored keys (the Telegram bot token lives under
	 * api_key, exactly like the wp-admin form saves it).
	 */
	public function test_create_connection() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites' );
		$request->set_body_params(
			array(
				'connection_type' => 'telegram',
				'name'            => 'Support bot',
				'api_key'         => '123:ABC',
			)
		);

		$response = $controller->create_item( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertSame( 'pro', $data['item']['source'] );
		$this->assertSame( 'telegram', $data['item']['connection_type'] );
		$this->assertTrue( $data['item']['has_credentials'] );
		$this->assertArrayNotHasKey( 'api_key', $data['item'] );

		$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $data['item']['id'] );
		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'https://api.telegram.org', $stored['url'], 'Provider URL must be auto-filled' );

		$stored_token = (string) $stored['api_key'];
		$this->assertNotEmpty( $stored_token );
		$plain = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_token );
		$this->assertTrue(
			'123:ABC' === $plain || '123:ABC' === $stored_token,
			'Stored token must round-trip through decryption'
		);
	}

	/**
	 * Creating a connection of an unknown type is rejected.
	 */
	public function test_create_rejects_unknown_type() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites' );
		$request->set_body_params( array( 'connection_type' => 'not_a_type' ) );

		$response = $controller->create_item( $request );
		$this->assertWPError( $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Creating a connection with a missing name surfaces the manager's
	 * plain-language validation error as a 400 (not a raw 500).
	 */
	public function test_create_missing_name_400() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites' );
		$request->set_body_params(
			array(
				'connection_type' => 'telegram',
				'api_key'         => '123:ABC',
			)
		);

		$response = $controller->create_item( $request );
		$this->assertWPError( $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Creating without a required secret fails with the field label.
	 */
	public function test_create_requires_required_fields() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites' );
		$request->set_body_params( array( 'connection_type' => 'telegram' ) );

		$response = $controller->create_item( $request );
		$this->assertWPError( $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Updating with a blank secret keeps the stored value (never echo back).
	 */
	public function test_update_blank_secret_keeps_stored() {
		$this->load_controller();
		$this->login_as_admin();

		$id = $this->seed_gmail_connection();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites/' . $id );
		// NOTE: set_body_params() replaces the whole params array, so the
		// route `id` must ride along inside it (direct handler calls never
		// get route captures — pattern 81).
		$request->set_body_params(
			array(
				'id'   => $id,
				'name' => 'Renamed inbox',
			)
		);

		$response = $controller->update_item( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertSame( 'Renamed inbox', $data['item']['name'] );

		$stored = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
		$this->assertSame( 'Renamed inbox', $stored['name'] );

		$stored_secret = (string) $stored['client_secret'];
		$this->assertNotEmpty( $stored_secret );
		$plain = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $stored_secret );
		$this->assertTrue(
			'client-secret-123' === $plain || 'client-secret-123' === $stored_secret,
			'Blank secret must keep the stored value'
		);
	}

	/**
	 * Updating a missing connection is a 404.
	 */
	public function test_update_missing_connection_404() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites/conn_missing' );
		$request->set_body_params( array( 'id' => 'conn_missing' ) );

		$response = $controller->update_item( $request );
		$this->assertWPError( $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	/**
	 * Deleting a connection removes it.
	 */
	public function test_delete_connection() {
		$this->load_controller();
		$this->login_as_admin();

		$id = $this->seed_gmail_connection();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'DELETE', '/mcp-ai-pro/v1/remote-sites/' . $id );
		$request->set_body_params( array( 'id' => $id ) );

		$response = $controller->delete_item( $request );
		$this->assertNotWPError( $response );
		$this->assertSame( $id, $response->get_data()['deleted'] );

		$this->assertNull( WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id ) );
	}

	/**
	 * The type catalogue covers the wp-admin dropdown's known types and is
	 * shape-stable for the console wizard.
	 */
	public function test_type_catalogue_shape() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/remote-sites/types' );
		$response   = $controller->get_types( $request );
		$data       = $response->get_data();

		$this->assertNotEmpty( $data['items'] );

		$ids = array_column( $data['items'], 'id' );
		foreach ( array( 'wordpress', 'gmail', 'telegram', 'mcp_server', 'shopify', 'mesh_peer' ) as $expected ) {
			$this->assertContains( $expected, $ids, $expected . ' must be in the catalogue' );
		}

		foreach ( $data['items'] as $type ) {
			$this->assertArrayHasKey( 'label', $type );
			$this->assertArrayHasKey( 'category', $type );
			$this->assertIsArray( $type['fields'] );
			foreach ( $type['fields'] as $field ) {
				$this->assertArrayHasKey( 'key', $field );
				$this->assertArrayHasKey( 'label', $field );
			}
		}
	}

	/**
	 * Testing the base Gmail pseudo-connection without settings is a
	 * non-exception error result (plain language for the console).
	 */
	public function test_base_gmail_test_unconfigured() {
		$this->load_controller();
		$this->login_as_admin();

		delete_option( 'wp_mcp_ai_settings' );

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites/base-gmail/test' );
		$request->set_body_params( array( 'id' => 'base-gmail' ) );

		$response = $controller->test_item( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertNotEmpty( $data['message'] );
	}

	/**
	 * Testing a missing connection returns success:false with a message
	 * instead of a raw WP_Error (console-friendly envelope).
	 */
	public function test_missing_connection_test_envelope() {
		$this->load_controller();
		$this->login_as_admin();

		$controller = new WP_MCP_AI_Pro_Remote_Sites_REST_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/remote-sites/conn_missing/test' );
		$request->set_body_params( array( 'id' => 'conn_missing' ) );

		$response = $controller->test_item( $request );
		$this->assertNotWPError( $response );

		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}
}
