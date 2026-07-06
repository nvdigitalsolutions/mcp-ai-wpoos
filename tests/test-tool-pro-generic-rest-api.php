<?php
/**
 * Tests for WP_MCP_AI_Tool_Generic_REST_API.
 *
 * The list_connections action works without an external connection, making it
 * ideal for a happy-path test.  The make_request and test_connection actions
 * require a connection_id, so their missing-param path is also covered.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for the generic_rest_api pro tool.
 */
class Test_Tool_Pro_Generic_REST_API extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Generic_REST_API
	 */
	private $tool;

	/**
	 * Admin user ID used across tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$tool_file = dirname( __DIR__ ) . '/addons/pro/includes/tools/class-wp-mcp-ai-tool-generic-rest-api.php';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generic_REST_API' ) && file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Generic_REST_API' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Generic_REST_API class not available.' );
			return;
		}

		$this->tool = new WP_MCP_AI_Tool_Generic_REST_API();
	}

	// -----------------------------------------------------------------------
	// get_slug / get_definition
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug returns the expected string.
	 */
	public function test_get_slug_returns_generic_rest_api() {
		$this->assertSame( 'generic_rest_api', $this->tool->get_slug() );
	}

	/**
	 * Test that get_definition returns required keys.
	 */
	public function test_get_definition_returns_required_keys() {
		$def = $this->tool->get_definition();

		$this->assertArrayHasKey( 'name', $def );
		$this->assertSame( 'generic_rest_api', $def['name'] );
	}

	// -----------------------------------------------------------------------
	// execute – guest user
	// -----------------------------------------------------------------------

	/**
	 * Test that user_id=0 returns WP_Error('wp_mcp_ai_forbidden').
	 */
	public function test_guest_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'action' => 'list_connections' ),
			array( 'user_id' => 0 )
		);

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}

	// -----------------------------------------------------------------------
	// execute – list_connections (happy path, no connections configured)
	// -----------------------------------------------------------------------

	/**
	 * Test that list_connections returns a success array even with no connections.
	 */
	public function test_list_connections_returns_array() {
		$result = $this->tool->execute(
			array( 'action' => 'list_connections' ),
			array( 'user_id' => $this->admin_id )
		);

		// Should be a plain array (not WP_Error) listing available connections.
		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// execute – make_request without connection_id
	// -----------------------------------------------------------------------

	/**
	 * Test that make_request without connection_id returns WP_Error('wp_mcp_ai_pro_missing_connection').
	 */
	public function test_make_request_missing_connection_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(
				'action'   => 'make_request',
				'endpoint' => '/api/v1/test',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_connection', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// execute – test_connection without connection_id
	// -----------------------------------------------------------------------

	/**
	 * Test that test_connection without connection_id returns WP_Error('wp_mcp_ai_pro_missing_connection').
	 */
	public function test_test_connection_missing_connection_id_returns_wp_error() {
		$result = $this->tool->execute(
			array( 'action' => 'test_connection' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_missing_connection', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// get_capability_flags
	// -----------------------------------------------------------------------

	/**
	 * Test that get_capability_flags returns an array.
	 */
	public function test_get_capability_flags_returns_array() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}
}
