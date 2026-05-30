<?php
/**
 * Tests for Remote Connection Tool - Product Variations Support
 *
 * Tests the enhanced product variation support in the remote connection tool.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Remote Connection Tool Variations.
 */
class Test_Remote_Connection_Tool_Variations extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/remote-connections/class-wp-mcp-ai-tool-remote-wp-connection.php';

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

		// Enable connection for assistant.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $this->connection_id ) );
	}

	/**
	 * Create a test connection with WooCommerce enabled.
	 *
	 * @return string Connection ID.
	 */
	protected function create_test_connection() {
		$connection_data = array(
			'name'            => 'Test WooCommerce Connection',
			'url'             => 'https://example.com',
			'auth_type'       => 'none',
			'enabled'         => true,
			'has_woocommerce' => true,
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::add_connection( $connection_data );

		return $result['id'];
	}

	/**
	 * Test that get_wc_product_variations action is in the schema.
	 */
	public function test_schema_includes_variations_action() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['action'] );
		$this->assertContains( 'get_wc_product_variations', $schema['properties']['action']['enum'] );
	}

	/**
	 * Test that include_variations parameter exists in schema.
	 */
	public function test_schema_includes_variations_parameter() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'include_variations', $schema['properties'] );
		$this->assertEquals( 'boolean', $schema['properties']['include_variations']['type'] );
		$this->assertTrue( $schema['properties']['include_variations']['default'] );
	}

	/**
	 * Test that category parameter exists in schema.
	 */
	public function test_schema_includes_category_parameter() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'category', $schema['properties'] );
		$this->assertEquals( 'string', $schema['properties']['category']['type'] );
	}

	/**
	 * Test that type parameter exists in schema.
	 */
	public function test_schema_includes_type_parameter() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'type', $schema['properties'] );
		$this->assertEquals( 'string', $schema['properties']['type']['type'] );
	}

	/**
	 * Test that stock_status parameter exists in schema.
	 */
	public function test_schema_includes_stock_status_parameter() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'stock_status', $schema['properties'] );
		$this->assertEquals( 'string', $schema['properties']['stock_status']['type'] );
	}

	/**
	 * Test stock_status parameter description mentions variation filtering.
	 */
	public function test_stock_status_parameter_description() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'stock_status', $schema['properties'] );
		$description = $schema['properties']['stock_status']['description'];

		$this->assertStringContainsString( 'stock', strtolower( $description ) );
		$this->assertStringContainsString( 'variation', strtolower( $description ) );
		$this->assertStringContainsString( 'filter', strtolower( $description ) );
	}

	/**
	 * Test get_wc_product_variations requires product_id.
	 */
	public function test_get_variations_requires_product_id() {
		$arguments = array(
			'action'        => 'get_wc_product_variations',
			'connection_id' => $this->connection_id,
			// Missing post_id.
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_missing_product_id', $result->get_error_code() );
		$this->assertStringContainsString( 'required', $result->get_error_message() );
	}

	/**
	 * Test get_wc_product_variations requires WooCommerce.
	 */
	public function test_get_variations_requires_woocommerce() {
		// Create connection without WooCommerce.
		$connection_data = array(
			'name'            => 'Test Connection No WC',
			'url'             => 'https://example2.com',
			'auth_type'       => 'none',
			'enabled'         => true,
			'has_woocommerce' => false,
		);

		$result              = WP_MCP_AI_Pro_Remote_Site_Manager::add_connection( $connection_data );
		$no_wc_connection_id = $result['id'];

		// Enable for assistant.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $no_wc_connection_id ) );

		$arguments = array(
			'action'        => 'get_wc_product_variations',
			'connection_id' => $no_wc_connection_id,
			'post_id'       => 123,
		);

		$context = array(
			'assistant_id' => $this->assistant_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_no_woocommerce', $result->get_error_code() );

		// Cleanup.
		WP_MCP_AI_Pro_Remote_Site_Manager::delete_connection( $no_wc_connection_id );
	}

	/**
	 * Test tool description mentions variations.
	 */
	public function test_tool_description_mentions_variations() {
		$description = $this->tool->get_description();

		$this->assertStringContainsString( 'variations', strtolower( $description ) );
	}

	/**
	 * Test include_variations parameter description.
	 */
	public function test_include_variations_parameter_description() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'include_variations', $schema['properties'] );
		$description = $schema['properties']['include_variations']['description'];

		$this->assertStringContainsString( 'variation', strtolower( $description ) );
		$this->assertStringContainsString( 'get_wc_products', $description );
	}

	/**
	 * Test category parameter description.
	 */
	public function test_category_parameter_description() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'category', $schema['properties'] );
		$description = $schema['properties']['category']['description'];

		$this->assertStringContainsString( 'category', strtolower( $description ) );
		$this->assertStringContainsString( 'product', strtolower( $description ) );
	}

	/**
	 * Test type parameter description.
	 */
	public function test_type_parameter_description() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'type', $schema['properties'] );
		$description = $schema['properties']['type']['description'];

		$this->assertStringContainsString( 'type', strtolower( $description ) );
		$this->assertStringContainsString( 'product', strtolower( $description ) );
	}

	/**
	 * Test that tool description mentions stock sorting and optimization.
	 */
	public function test_tool_description_mentions_stock_sorting() {
		$description = $this->tool->get_description();

		$this->assertStringContainsString( 'in-stock', strtolower( $description ) );
		$this->assertStringContainsString( 'token', strtolower( $description ) );
	}

	/**
	 * Test per_page parameter description mentions updated default.
	 */
	public function test_per_page_parameter_description_updated() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'per_page', $schema['properties'] );
		$description = $schema['properties']['per_page']['description'];

		$this->assertStringContainsString( '25', $description );
		$this->assertStringContainsString( 'in-stock', strtolower( $description ) );
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
