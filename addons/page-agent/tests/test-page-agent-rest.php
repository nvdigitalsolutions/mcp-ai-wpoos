<?php
/**
 * Page Agent REST API Tests
 *
 * Tests for REST endpoint permission checks, tool dispatch,
 * error responses, and configuration retrieval.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Page_Agent_REST
 *
 * @since 0.1.0
 */
class Test_Page_Agent_REST extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @since 0.1.0
	 * @var WP_MCP_AI_Page_Agent_REST
	 */
	protected $controller;

	/**
	 * Test user with edit_posts capability.
	 *
	 * @since 0.1.0
	 * @var WP_User
	 */
	protected $editor_user;

	/**
	 * Set up test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Page_Agent_REST' ) ) {
			require_once NVOOS_PAGE_AGENT_PATH . 'includes/class-wp-mcp-ai-page-agent-rest.php';
		}

		$this->controller = new WP_MCP_AI_Page_Agent_REST();

		// Create an editor user for testing.
		$this->editor_user = self::factory()->user->create_and_get(
			array( 'role' => 'editor' )
		);
	}

	/**
	 * Tear down test environment.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( $this->editor_user ) {
			wp_delete_user( $this->editor_user->ID );
		}
		parent::tearDown();
	}

	// ── Permission Tests ─────────────────────────────────────

	/**
	 * Test that execute permission denies requests without a nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_permission_denies_missing_nonce() {
		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/execute-tool' );

		$result = $this->controller->check_execute_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}

	/**
	 * Test that execute permission denies invalid nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_permission_denies_invalid_nonce() {
		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/execute-tool' );
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce' );

		$result = $this->controller->check_execute_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}

	/**
	 * Test that execute permission passes with a valid nonce for authorized users.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_permission_allows_valid_nonce() {
		wp_set_current_user( $this->editor_user->ID );

		$nonce   = wp_create_nonce( 'wp_rest' );
		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/execute-tool' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$result = $this->controller->check_execute_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test that config permission denies requests without a nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_config_permission_denies_missing_nonce() {
		$request = new WP_REST_Request( 'GET', '/nvoos-page-agent/v1/config' );

		$result = $this->controller->check_config_permission( $request );

		$this->assertWPError( $result );
	}

	// ── Execute Tool Tests ────────────────────────────────────

	/**
	 * Test that execute_tool returns error when registry is unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_tool_registry_unavailable() {
		// Simulate no tool registry.
		add_filter( 'wp_mcp_ai_get_tool_registry', '__return_null' );

		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/execute-tool' );
		$request->set_param( 'tool', 'some_tool' );
		$request->set_param( 'arguments', array() );

		$result = $this->controller->handle_execute_tool( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_registry_unavailable', $result->get_error_code() );

		remove_filter( 'wp_mcp_ai_get_tool_registry', '__return_null' );
	}

	/**
	 * Test that execute_tool returns error for nonexistent tool.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_execute_tool_not_found() {
		// Mock the tool registry.
		$mock_registry = $this->getMockBuilder( 'WP_MCP_AI_Tool_Registry' )
			->disableOriginalConstructor()
			->getMock();

		$mock_registry->method( 'get_tool' )
			->with( 'nonexistent_tool' )
			->willReturn( null );

		add_filter(
			'wp_mcp_ai_get_tool_registry',
			function () use ( $mock_registry ) {
				return $mock_registry;
			}
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/execute-tool' );
		$request->set_param( 'tool', 'nonexistent_tool' );
		$request->set_param( 'arguments', array() );

		$result = $this->controller->handle_execute_tool( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_not_found', $result->get_error_code() );

		remove_all_filters( 'wp_mcp_ai_get_tool_registry' );
	}

	// ── Config Tests ──────────────────────────────────────────

	/**
	 * Test that get_config returns expected fields.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_get_config_returns_expected_fields() {
		$request = new WP_REST_Request( 'GET', '/nvoos-page-agent/v1/config' );
		$response = $this->controller->handle_get_config( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'enabled', $data );
		$this->assertArrayHasKey( 'model', $data );
		$this->assertArrayHasKey( 'language', $data );
		$this->assertArrayHasKey( 'max_steps', $data );
		$this->assertArrayHasKey( 'rest_url', $data );
	}

	// ── DOM Snapshot Tests ────────────────────────────────────

	/**
	 * Test that dom_snapshot stores a transient.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_dom_snapshot_stores_transient() {
		$request = new WP_REST_Request( 'POST', '/nvoos-page-agent/v1/dom-snapshot' );
		$request->set_param( 'url', 'https://example.com/page' );
		$request->set_param(
			'interactive',
			array(
				array(
					'tag'  => 'button',
					'text' => 'Click Me',
					'id'   => 'btn-1',
				),
			)
		);
		$request->set_param( 'timestamp', '2026-07-10T12:00:00Z' );

		$response = $this->controller->handle_dom_snapshot( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'key', $data['data'] );

		// Verify transient was stored.
		$transient = get_transient( $data['data']['key'] );
		$this->assertIsArray( $transient );
		$this->assertEquals( 'https://example.com/page', $transient['url'] );
		$this->assertCount( 1, $transient['interactive'] );
	}

	// ── Sanitization Tests ────────────────────────────────────

	/**
	 * Test that sanitize_tool_arguments handles nested arrays.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_tool_arguments_nested() {
		$input = array(
			'key1'  => 'value1 <script>alert("xss")</script>',
			'key2'  => array(
				'nested' => '<b>bold</b>',
			),
			'key3'  => 42,
			'key4'  => true,
		);

		$result = $this->controller->sanitize_tool_arguments( $input );

		$this->assertEquals( 'value1', $result['key1'] );
		$this->assertIsArray( $result['key2'] );
		$this->assertEquals( '', $result['key2']['nested'] );
		$this->assertEquals( 42, $result['key3'] );
		$this->assertTrue( $result['key4'] );
	}

	/**
	 * Test that sanitize_interactive_elements filters correctly.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_interactive_elements() {
		$input = array(
			array(
				'tag'       => 'button',
				'text'      => 'Delete <script>alert("hi")</script>',
				'id'        => 'btn-delete',
				'class'     => 'danger btn',
				'href'      => 'javascript:alert("xss")',
				'type'      => 'submit',
				'name'      => 'action',
				'value'     => '<iframe src="evil.com">',
				'role'      => 'button',
				'ariaLabel' => 'Delete item',
			),
		);

		$result = $this->controller->sanitize_interactive_elements( $input );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'button', $result[0]['tag'] );
		$this->assertEquals( 'Delete', $result[0]['text'] );
		$this->assertNotContains( '<script>', $result[0]['text'] );
		$this->assertNotContains( 'javascript:', $result[0]['href'] );
		$this->assertNotContains( '<iframe', $result[0]['value'] );
	}
}
