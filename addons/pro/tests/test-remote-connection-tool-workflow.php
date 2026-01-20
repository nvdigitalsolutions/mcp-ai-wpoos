<?php
/**
 * Tests for Remote Connection Tool Workflow
 *
 * Tests the proper workflow of calling list_connections first,
 * then using discovered connection IDs in subsequent calls.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Remote Connection Tool Workflow.
 */
class Test_Remote_Connection_Tool_Workflow extends WP_UnitTestCase {

	/**
	 * Remote site manager instance.
	 *
	 * @var WP_MCP_AI_Pro_Remote_Site_Manager
	 */
	protected $manager;

	/**
	 * Remote connection tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Remote_WP_Connection
	 */
	protected $tool;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Test connection ID.
	 *
	 * @var string
	 */
	protected $connection_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php';

		$this->manager = new WP_MCP_AI_Pro_Remote_Site_Manager();
		$this->tool    = new WP_MCP_AI_Tool_Remote_WP_Connection();

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create test connection.
		$this->connection_id = $this->create_test_connection();
	}

	/**
	 * Create a test connection.
	 *
	 * @return string Connection ID.
	 */
	protected function create_test_connection() {
		$connection_data = array(
			'name'            => 'Test Connection',
			'url'             => 'https://example.com',
			'auth_type'       => 'none',
			'enabled'         => true,
			'has_woocommerce' => false,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::add_connection( $connection_data );

		return $result['id'];
	}

	/**
	 * Test that list_connections works without connection_id.
	 */
	public function test_list_connections_without_connection_id() {
		$arguments = array(
			'action' => 'list_connections',
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		// Should not be an error.
		$this->assertNotInstanceOf( 'WP_Error', $result, 'list_connections should work without connection_id' );

		// Should return connections array.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'connections', $result );
	}

	/**
	 * Test that other actions require connection_id.
	 */
	public function test_get_posts_requires_connection_id() {
		$arguments = array(
			'action' => 'get_posts',
			// Intentionally omitting connection_id.
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		// Should be an error.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_connection', $result->get_error_code() );

		// Error message should instruct to call list_connections first.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'list_connections', $error_message );
		$this->assertStringContainsString( 'FIRST', $error_message );
	}

	/**
	 * Test proper workflow: list_connections then use connection_id.
	 */
	public function test_proper_workflow() {
		// Enable connection for assistant.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $this->connection_id ) );

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		// Step 1: Call list_connections.
		$list_result = $this->tool->execute(
			array( 'action' => 'list_connections' ),
			$context
		);

		$this->assertNotInstanceOf( 'WP_Error', $list_result );
		$this->assertIsArray( $list_result );
		$this->assertArrayHasKey( 'connections', $list_result );
		$this->assertNotEmpty( $list_result['connections'] );

		// Extract first connection ID.
		$discovered_connection_id = $list_result['connections'][0]['id'];
		$this->assertEquals( $this->connection_id, $discovered_connection_id );

		// Step 2: Use discovered connection_id (simulating test_connection).
		$test_result = $this->tool->execute(
			array(
				'action'        => 'test_connection',
				'connection_id' => $discovered_connection_id,
			),
			$context
		);

		// This will likely fail with actual connection test, but should not fail with missing connection_id error.
		if ( is_wp_error( $test_result ) ) {
			$this->assertNotEquals(
				'wp_mcp_ai_pro_missing_connection',
				$test_result->get_error_code(),
				'Should not fail with missing connection error when connection_id is provided'
			);
		}
	}

	/**
	 * Test invalid connection_id error message.
	 */
	public function test_invalid_connection_id_error_message() {
		$arguments = array(
			'action'        => 'get_posts',
			'connection_id' => 'conn_invalid123',
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_invalid_connection', $result->get_error_code() );

		// Error message should include the invalid ID and suggest list_connections.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'conn_invalid123', $error_message );
		$this->assertStringContainsString( 'list_connections', $error_message );
	}

	/**
	 * Test disabled connection error message.
	 */
	public function test_disabled_connection_error_message() {
		// Disable the connection.
		$connections = get_option( 'wp_mcp_ai_pro_remote_sites', array() );
		if ( isset( $connections[ $this->connection_id ] ) ) {
			$connections[ $this->connection_id ]['enabled'] = false;
			update_option( 'wp_mcp_ai_pro_remote_sites', $connections );
		}

		$arguments = array(
			'action'        => 'get_posts',
			'connection_id' => $this->connection_id,
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_disabled_connection', $result->get_error_code() );

		// Error message should mention the connection is disabled and how to enable it.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'disabled', $error_message );
		$this->assertStringContainsString( 'NV oOS', $error_message );
	}

	/**
	 * Test connection not enabled for assistant error message.
	 */
	public function test_connection_not_enabled_for_assistant_error_message() {
		// Do NOT enable connection for assistant (no meta set).

		$arguments = array(
			'action'        => 'get_posts',
			'connection_id' => $this->connection_id,
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		// Enable connection for assistant with different connection.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( 'conn_other' ) );

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_connection_not_enabled', $result->get_error_code() );

		// Error message should mention the connection is not enabled for this assistant.
		$error_message = $result->get_error_message();
		$this->assertStringContainsString( 'not enabled for this assistant', $error_message );
		$this->assertStringContainsString( 'Remote Site Connections', $error_message );
	}

	/**
	 * Test that action parameter is required.
	 */
	public function test_action_parameter_required() {
		$arguments = array(
			'connection_id' => $this->connection_id,
			// Intentionally omitting action.
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		// With no action, it should default to list_connections.
		$result = $this->tool->execute( $arguments, $context );

		// Should execute list_connections (the default).
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'connections', $result );
	}

	/**
	 * Test schema validation: basic structure.
	 *
	 * Note: The oneOf constraint was removed because OpenAI's function calling API
	 * doesn't support oneOf/anyOf/allOf at the root level of the schema.
	 * Conditional requirement is validated in the execute() method instead.
	 */
	public function test_schema_basic_structure() {
		$schema = $this->tool->get_parameters_schema();

		// Should NOT have oneOf at root level (causes OpenAI validation errors).
		$this->assertArrayNotHasKey( 'oneOf', $schema );

		// Should have proper basic structure.
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'action', $schema['required'] );

		// Action and connection_id properties should exist.
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'connection_id', $schema['properties'] );
	}

	/**
	 * Test that parameter descriptions mention workflow.
	 */
	public function test_parameter_descriptions_mention_workflow() {
		$schema = $this->tool->get_parameters_schema();

		// Action description should mention calling list_connections first.
		$this->assertStringContainsString( 'FIRST', $schema['properties']['action']['description'] );
		$this->assertStringContainsString( 'list_connections', $schema['properties']['action']['description'] );

		// Connection_id description should mention it's required except for list_connections.
		$this->assertStringContainsString( 'REQUIRED', $schema['properties']['connection_id']['description'] );
		$this->assertStringContainsString( 'list_connections', $schema['properties']['connection_id']['description'] );
	}

	/**
	 * Test tool description mentions workflow.
	 */
	public function test_tool_description_mentions_workflow() {
		$description = $this->tool->get_description();

		$this->assertStringContainsString( 'WORKFLOW', $description );
		$this->assertStringContainsString( 'list_connections', $description );
		$this->assertStringContainsString( 'FIRST', $description );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		// Delete test connection.
		if ( $this->connection_id ) {
			WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $this->connection_id );
		}

		// Delete test assistant.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		parent::tearDown();
	}
}
