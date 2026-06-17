<?php
/**
 * Tests for WP_MCP_AI_REST_Slash_Command_Controller.
 *
 * Exercises the slash-command REST surface — particularly the
 * `check_permission()` callback, which gates every other endpoint and
 * supports both cookie-based auth and bearer-token assistant credentials.
 *
 * Heavy execution paths (the actual `execute_command` handler dispatch) are
 * deliberately not covered here: they require the full slash-command
 * registry and are exercised by the dedicated slash-command test files.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the slash-command REST controller.
 */
class Test_REST_Slash_Command_Controller extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Subscriber user ID (has 'read' cap → minimum to use slash commands).
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		// Force-register the controller's routes so dispatch() resolves them
		// even if bootstrap didn't wire them in this test context.
		$controller = new WP_MCP_AI_REST_Slash_Command_Controller();
		$controller->register_routes();

		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Route registration.
	// -------------------------------------------------------------------------

	/**
	 * Both slash-command routes must be registered.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command/list', $routes );
	}

	// -------------------------------------------------------------------------
	// check_permission() — auth gate.
	// -------------------------------------------------------------------------

	/**
	 * An anonymous request without a bearer token must be rejected with 401.
	 */
	public function test_check_permission_rejects_anonymous() {
		wp_set_current_user( 0 );

		$controller = new WP_MCP_AI_REST_Slash_Command_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'not_authenticated', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * An anonymous request with an invalid bearer token must be rejected.
	 *
	 * The token below intentionally has the assistant-credential shape but
	 * matches no assistant in the database.
	 */
	public function test_check_permission_rejects_invalid_bearer() {
		wp_set_current_user( 0 );

		$controller = new WP_MCP_AI_REST_Slash_Command_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );
		$request->set_header( 'authorization', 'Bearer cred_abc123.bogussecret' );

		$result = $controller->check_permission( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * A logged-in subscriber (which has 'read') passes the permission check.
	 */
	public function test_check_permission_allows_logged_in_subscriber() {
		wp_set_current_user( $this->subscriber_id );

		$controller = new WP_MCP_AI_REST_Slash_Command_Controller();
		$request    = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );

		$result = $controller->check_permission( $request );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// execute_command() — input validation.
	// -------------------------------------------------------------------------

	/**
	 * POST /slash-command without a `command` param must return 400.
	 */
	public function test_execute_command_rejects_missing_command() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );
		$request->set_param( 'command', '' );

		$response = $this->server->dispatch( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'missing_command', $response->as_error()->get_error_code() );
		$this->assertSame( 400, $response->get_status() );
	}
}
