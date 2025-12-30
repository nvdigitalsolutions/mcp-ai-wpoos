<?php
/**
 * Helper utilities for REST API tests.
 *
 * Provides common fixture setup methods for testing REST endpoints
 * that require assistants, authentication, and permissions.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */
trait WP_MCP_AI_REST_Test_Helper {

	/**
	 * Create a published assistant post for testing.
	 *
	 * @param string $title Optional assistant title. Default 'Test Assistant'.
	 * @param array  $meta  Optional array of post meta to set.
	 * @return int Assistant post ID.
	 */
	protected function create_assistant_post( $title = 'Test Assistant', $meta = array() ) {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id, 'Assistant post creation should not return WP_Error' );
		$this->assertNotEmpty( $assistant_id, 'Assistant post ID should not be empty' );

		// Set any provided meta.
		if ( ! empty( $meta ) ) {
			foreach ( $meta as $meta_key => $meta_value ) {
				update_post_meta( $assistant_id, $meta_key, $meta_value );
			}
		}

		return $assistant_id;
	}

	/**
	 * Prepare the REST controller with the provided mock client.
	 *
	 * Ensures the REST server is initialized and routes are registered.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $mock_client Mocked router instance.
	 */
	protected function bootstrap_rest_controller( WP_MCP_AI_Language_Model_Router $mock_client ) {
		// Remove any existing REST controller.
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		// Create new REST controller with mock client.
		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );

		// Initialize REST server and trigger route registration.
		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a user with specified role and capabilities.
	 *
	 * @param string $role         WordPress user role (e.g., 'administrator', 'editor', 'author').
	 * @param array  $extra_caps   Optional additional capabilities to grant.
	 * @return int User ID.
	 */
	protected function create_test_user( $role = 'administrator', $extra_caps = array() ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		$this->assertNotEmpty( $user_id, 'Test user ID should not be empty' );

		// Grant any extra capabilities.
		if ( ! empty( $extra_caps ) ) {
			$user = get_user_by( 'id', $user_id );
			foreach ( $extra_caps as $cap ) {
				$user->add_cap( $cap );
			}
		}

		return $user_id;
	}

	/**
	 * Create a REST request with authentication.
	 *
	 * Automatically sets up nonce authentication for the current user.
	 *
	 * @param string $method HTTP method (GET, POST, etc.).
	 * @param string $route  REST route path.
	 * @param array  $params Optional request parameters.
	 * @return WP_REST_Request
	 */
	protected function create_authenticated_request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		// Set request parameters.
		if ( ! empty( $params ) ) {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}

		// Add nonce for current user if user is logged in.
		if ( get_current_user_id() > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		return $request;
	}

	/**
	 * Issue an assistant credential token for testing.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $issuer_id    User ID of the credential issuer.
	 * @return array Credential data including 'token' key.
	 */
	protected function issue_test_credential( $assistant_id, $issuer_id = null ) {
		if ( null === $issuer_id ) {
			$issuer_id = $this->create_test_user( 'administrator' );
		}

		// Set current user to issuer for credential creation.
		$original_user = get_current_user_id();
		wp_set_current_user( $issuer_id );

		$issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

		// Restore original user.
		wp_set_current_user( $original_user );

		$this->assertArrayHasKey( 'token', $issued, 'Issued credential should contain a token' );
		$this->assertNotEmpty( $issued['token'], 'Credential token should not be empty' );

		return $issued;
	}

	/**
	 * Generate a guest token for testing.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string Guest token.
	 */
	protected function generate_test_guest_token( $assistant_id ) {
		$guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );

		$this->assertNotEmpty( $guest_token, 'Guest token should not be empty' );

		return $guest_token;
	}

	/**
	 * Set up REST server for testing.
	 *
	 * Should be called in setUp() to ensure REST server is properly initialized.
	 */
	protected function setup_rest_server() {
		global $wp_rest_server;

		// Initialize REST server if not already done.
		if ( empty( $wp_rest_server ) ) {
			$wp_rest_server = new WP_REST_Server();
			do_action( 'rest_api_init', $wp_rest_server );
		}
	}

	/**
	 * Tear down REST server.
	 *
	 * Should be called in tearDown() to clean up REST server state.
	 */
	protected function teardown_rest_server() {
		global $wp_rest_server;
		$wp_rest_server = null;

		// Clean up any REST controller instances.
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			unset( $GLOBALS['wp_mcp_ai_rest_controller'] );
		}
	}
}
