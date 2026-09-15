<?php
/**
 * Tests for the assistant portability REST controller.
 *
 * Covers route registration, the manage_options permission boundary, export
 * payloads (bundle + A2A), and import application through the REST surface.
 *
 * @package WP_MCP_AI
 * @since   1.1.80
 */

/**
 * Assistant portability REST controller tests.
 *
 * @since 1.1.80
 */
class Test_REST_Assistant_Portability extends WP_UnitTestCase {

	/**
	 * Admin user ID for capability checks.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID for negative capability checks.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up fixtures and register routes.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$cpt_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
			if ( file_exists( $cpt_file ) ) {
				require_once $cpt_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_Portability' ) ) {
			$engine_file = WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-portability.php';
			if ( file_exists( $engine_file ) ) {
				require_once $engine_file;
			}
		}

		$this->admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin_id );

		$controller_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-assistant-portability-controller.php';
		if ( file_exists( $controller_file ) ) {
			require_once $controller_file;
		}

		$controller = new WP_MCP_AI_REST_Assistant_Portability_Controller();

		// Register routes during rest_api_init to avoid "doing it wrong" notices.
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init' );
	}

	/**
	 * Reset the current user.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create an assistant fixture.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_assistant_fixture() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'REST Portability Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'gpt-4.1' );
		update_post_meta( $assistant_id, '_wp_mcp_ai_tools', array( 'web_search' ) );

		return $assistant_id;
	}

	/**
	 * Both routes are registered.
	 */
	public function test_routes_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		$this->assertArrayHasKey( '/mcp-ai/v1/assistants/export', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/assistants/import', $routes );
	}

	/**
	 * Non-admin users are rejected with 403.
	 */
	public function test_permission_requires_manage_options() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/export' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'json' => '{}' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Export returns the canonical bundle for a given assistant.
	 */
	public function test_export_returns_bundle() {
		$assistant_id = $this->create_assistant_fixture();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/export' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'ids'         => array( $assistant_id ),
					'include_a2a' => false,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'nvoos-assistant', $data['data']['format'] );
		$this->assertSame( 'REST Portability Assistant', $data['data']['assistants'][0]['title'] );
		$this->assertArrayNotHasKey( 'a2a', $data['data']['assistants'][0] );
	}

	/**
	 * A2A format requires exactly one assistant.
	 */
	public function test_export_a2a_requires_single_id() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/export' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'format' => 'a2a' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Import applies a payload and reports created counts.
	 */
	public function test_import_creates_assistant() {
		$payload = wp_json_encode(
			array(
				'format'     => 'nvoos-assistant',
				'assistants' => array(
					array(
						'title' => 'REST Imported Assistant',
						'meta'  => array( '_wp_mcp_ai_model' => 'gemini-pro' ),
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'json' => $payload ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['data']['created'] );

		$new_id = $data['data']['items'][0]['assistant_id'];
		$this->assertSame( 'gemini-pro', get_post_meta( $new_id, '_wp_mcp_ai_model', true ) );
	}

	/**
	 * Import dry-run reports without writing.
	 */
	public function test_import_dry_run_via_rest() {
		$payload = wp_json_encode(
			array(
				'name' => 'REST Dry Run Assistant',
				'meta' => array( 'instructions' => 'Never written.' ),
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'json'    => $payload,
					'dry_run' => true,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'dry_run', $data['data']['items'][0]['status'] );

		$found = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'title'          => 'REST Dry Run Assistant',
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		$this->assertEmpty( $found );
	}

	/**
	 * Import without a payload errors with 400.
	 */
	public function test_import_requires_payload() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Malformed JSON payloads error with 400.
	 */
	public function test_import_invalid_json_errors() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants/import' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'json' => '{broken' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
