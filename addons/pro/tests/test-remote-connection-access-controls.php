<?php
/**
 * Tests for WordPress/WooCommerce connection granular access controls.
 *
 * Verifies that post_type_access and wc_resource_access configuration is
 * correctly stored, validated, and enforced by the tool.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php';

/**
 * Tests for access control helpers on the Remote Site Manager.
 */
class Test_Remote_Connection_Access_Controls extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Tool_Remote_WP_Connection
	 */
	protected $tool;

	/**
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		$this->tool = new WP_MCP_AI_Tool_Remote_WP_Connection();

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// is_post_type_operation_allowed()
	// -------------------------------------------------------------------------

	/**
	 * When no post_type_access is configured, read is allowed (backward compatible).
	 */
	public function test_is_post_type_operation_allowed_no_config_allows_read() {
		$connection = array( 'post_type_access' => array() );

		$this->assertTrue(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'read' )
		);
	}

	/**
	 * When no post_type_access is configured, write is denied (backward compatible).
	 */
	public function test_is_post_type_operation_allowed_no_config_denies_write() {
		$connection = array( 'post_type_access' => array() );

		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'create' )
		);
		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'update' )
		);
		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'delete' )
		);
	}

	/**
	 * When post_type_access is configured with read, read is allowed.
	 */
	public function test_is_post_type_operation_allowed_configured_read() {
		$connection = array(
			'post_type_access' => array(
				'post' => array( 'read' ),
			),
		);

		$this->assertTrue(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'read' )
		);
	}

	/**
	 * When post_type_access is configured but create is not listed, create is denied.
	 */
	public function test_is_post_type_operation_allowed_create_denied() {
		$connection = array(
			'post_type_access' => array(
				'post' => array( 'read' ),
			),
		);

		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', 'create' )
		);
	}

	/**
	 * When post_type_access is configured with all ops, all are allowed.
	 */
	public function test_is_post_type_operation_allowed_full_crud() {
		$connection = array(
			'post_type_access' => array(
				'post' => array( 'read', 'create', 'update', 'delete' ),
			),
		);

		foreach ( array( 'read', 'create', 'update', 'delete' ) as $op ) {
			$this->assertTrue(
				WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'post', $op ),
				"Expected '$op' to be allowed"
			);
		}
	}

	/**
	 * A post type not in the allowlist is denied even if another type is allowed.
	 */
	public function test_is_post_type_operation_allowed_type_not_in_allowlist() {
		$connection = array(
			'post_type_access' => array(
				'post' => array( 'read' ),
			),
		);

		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'page', 'read' )
		);
	}

	// -------------------------------------------------------------------------
	// is_wc_resource_operation_allowed()
	// -------------------------------------------------------------------------

	/**
	 * When no wc_resource_access is configured, read is allowed (backward compatible).
	 */
	public function test_is_wc_resource_operation_allowed_no_config_allows_read() {
		$connection = array( 'wc_resource_access' => array() );

		$this->assertTrue(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'read' )
		);
	}

	/**
	 * When no wc_resource_access is configured, write is denied.
	 */
	public function test_is_wc_resource_operation_allowed_no_config_denies_write() {
		$connection = array( 'wc_resource_access' => array() );

		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'create' )
		);
	}

	/**
	 * When wc_resource_access is configured with update on orders, update is allowed.
	 */
	public function test_is_wc_resource_operation_allowed_update_orders() {
		$connection = array(
			'wc_resource_access' => array(
				'orders' => array( 'read', 'update' ),
			),
		);

		$this->assertTrue(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'update' )
		);
		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'delete' )
		);
	}

	/**
	 * A resource not in the allowlist is denied.
	 */
	public function test_is_wc_resource_operation_allowed_resource_not_in_allowlist() {
		$connection = array(
			'wc_resource_access' => array(
				'products' => array( 'read' ),
			),
		);

		$this->assertFalse(
			WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'read' )
		);
	}

	// -------------------------------------------------------------------------
	// sanitize_access_controls()
	// -------------------------------------------------------------------------

	/**
	 * sanitize_access_controls strips invalid operation values.
	 */
	public function test_sanitize_access_controls_strips_invalid_operations() {
		// Call the protected method via save_connection (which calls sanitize_access_controls internally).
		$connection_data = array(
			'name'            => 'Test Site',
			'url'             => 'https://example.com',
			'auth_type'       => 'none',
			'enabled'         => true,
			'post_type_access' => array(
				'post' => array( 'read', 'exec', 'invalid_op', 'delete' ),
			),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );
		$this->assertNotWPError( $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotWPError( $saved );
		$this->assertIsArray( $saved['post_type_access']['post'] );

		// 'exec' and 'invalid_op' should have been stripped.
		$this->assertNotContains( 'exec', $saved['post_type_access']['post'] );
		$this->assertNotContains( 'invalid_op', $saved['post_type_access']['post'] );

		// Valid values 'read' and 'delete' should remain.
		$this->assertContains( 'read', $saved['post_type_access']['post'] );
		$this->assertContains( 'delete', $saved['post_type_access']['post'] );
	}

	// -------------------------------------------------------------------------
	// Tool access control enforcement
	// -------------------------------------------------------------------------

	/**
	 * get_posts denies access when the post type is not in the allowlist.
	 */
	public function test_tool_get_posts_denied_when_post_type_not_in_allowlist() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Restricted Connection',
				'url'             => 'https://example.com',
				'auth_type'       => 'none',
				'enabled'         => true,
				'post_type_access' => array(
					'page' => array( 'read' ),
					// 'post' is intentionally absent.
				),
			)
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $connection_id ) );

		$result = $this->tool->execute(
			array(
				'action'        => 'get_posts',
				'connection_id' => $connection_id,
				'post_type'     => 'post',
			),
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_access_denied', $result->get_error_code() );
	}

	/**
	 * create_post is denied when create is not in the allowlist.
	 */
	public function test_tool_create_post_denied_when_create_not_in_allowlist() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Read Only Connection',
				'url'             => 'https://example.com',
				'auth_type'       => 'none',
				'enabled'         => true,
				'post_type_access' => array(
					'post' => array( 'read' ), // read only, no create.
				),
			)
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $connection_id ) );

		$result = $this->tool->execute(
			array(
				'action'        => 'create_post',
				'connection_id' => $connection_id,
				'title'         => 'Test Post',
			),
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_access_denied', $result->get_error_code() );
	}

	/**
	 * create_post returns missing title error before the access check when no config is set.
	 * (When access controls are not configured, create is denied – not a missing title error.)
	 */
	public function test_tool_create_post_denied_with_no_access_config() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'      => 'Default Connection',
				'url'       => 'https://example.com',
				'auth_type' => 'none',
				'enabled'   => true,
				// No post_type_access – defaults to read-only.
			)
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $connection_id ) );

		$result = $this->tool->execute(
			array(
				'action'        => 'create_post',
				'connection_id' => $connection_id,
				'title'         => 'Test Post',
			),
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_access_denied', $result->get_error_code() );
	}

	/**
	 * WC products are denied when the products resource is not in wc_resource_access.
	 */
	public function test_tool_get_wc_products_denied_when_resource_not_in_allowlist() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'               => 'WC Restricted',
				'url'                => 'https://example.com',
				'auth_type'          => 'none',
				'enabled'            => true,
				'has_woocommerce'    => true,
				'wc_resource_access' => array(
					'orders' => array( 'read' ),
					// 'products' is intentionally absent.
				),
			)
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $connection_id ) );

		$result = $this->tool->execute(
			array(
				'action'        => 'get_wc_products',
				'connection_id' => $connection_id,
			),
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_access_denied', $result->get_error_code() );
	}

	/**
	 * update_wc_order is denied when the orders resource does not include 'update'.
	 */
	public function test_tool_update_wc_order_denied_when_update_not_in_allowlist() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'               => 'WC Read Only',
				'url'                => 'https://example.com',
				'auth_type'          => 'none',
				'enabled'            => true,
				'has_woocommerce'    => true,
				'wc_resource_access' => array(
					'orders' => array( 'read' ), // read only – no update.
				),
			)
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_pro_remote_connections', array( $connection_id ) );

		$result = $this->tool->execute(
			array(
				'action'        => 'update_wc_order',
				'connection_id' => $connection_id,
				'order_id'      => 42,
				'status'        => 'completed',
			),
			array( 'assistant_id' => $this->assistant_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_pro_access_denied', $result->get_error_code() );
	}

	/**
	 * The connection data correctly stores and retrieves post_type_access and wc_resource_access.
	 */
	public function test_access_controls_stored_and_retrieved() {
		$pt_access = array(
			'post' => array( 'read', 'create' ),
			'page' => array( 'read' ),
		);

		$wc_access = array(
			'products' => array( 'read', 'create', 'update', 'delete' ),
			'orders'   => array( 'read', 'update' ),
		);

		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'               => 'Access Control Test',
				'url'                => 'https://example.com',
				'auth_type'          => 'none',
				'enabled'            => true,
				'post_type_access'   => $pt_access,
				'wc_resource_access' => $wc_access,
				'custom_post_types'  => 'event,team',
			)
		);

		$this->assertNotWPError( $connection_id );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		$this->assertNotWPError( $saved );
		$this->assertIsArray( $saved );

		// Verify post_type_access.
		$this->assertArrayHasKey( 'post_type_access', $saved );
		$this->assertEquals( array( 'read', 'create' ), $saved['post_type_access']['post'] );
		$this->assertEquals( array( 'read' ), $saved['post_type_access']['page'] );

		// Verify wc_resource_access.
		$this->assertArrayHasKey( 'wc_resource_access', $saved );
		$this->assertEquals( array( 'read', 'create', 'update', 'delete' ), $saved['wc_resource_access']['products'] );
		$this->assertEquals( array( 'read', 'update' ), $saved['wc_resource_access']['orders'] );

		// Verify custom_post_types.
		$this->assertEquals( 'event,team', $saved['custom_post_types'] );
	}

	/**
	 * Access control fields are preserved when updating other connection properties.
	 */
	public function test_access_controls_preserved_on_update() {
		$connection_id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'             => 'Preserve Test',
				'url'              => 'https://example.com',
				'auth_type'        => 'none',
				'enabled'          => true,
				'post_type_access' => array( 'post' => array( 'read', 'create' ) ),
			)
		);

		$this->assertNotWPError( $connection_id );

		// Update only the name; access controls should be preserved.
		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'id'        => $connection_id,
				'name'      => 'Preserve Test Updated',
				'url'       => 'https://example.com',
				'auth_type' => 'none',
				'enabled'   => true,
				// post_type_access deliberately omitted to test preservation.
			)
		);

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$this->assertArrayHasKey( 'post_type_access', $saved );
		$this->assertEquals( array( 'read', 'create' ), $saved['post_type_access']['post'] );
		$this->assertEquals( 'Preserve Test Updated', $saved['name'] );
	}

	/**
	 * New write actions appear in the tool parameter schema enum.
	 */
	public function test_tool_schema_includes_write_actions() {
		$schema     = $this->tool->get_parameters_schema();
		$action_ops = isset( $schema['properties']['action']['enum'] ) ? $schema['properties']['action']['enum'] : array();

		$expected_write_actions = array(
			'create_post',
			'update_post',
			'delete_post',
			'create_wc_product',
			'update_wc_product',
			'delete_wc_product',
			'update_wc_order',
		);

		foreach ( $expected_write_actions as $action ) {
			$this->assertContains( $action, $action_ops, "Expected '$action' in tool schema enum" );
		}
	}

	/**
	 * The tool schema includes the new write-related parameters.
	 */
	public function test_tool_schema_includes_write_parameters() {
		$schema     = $this->tool->get_parameters_schema();
		$properties = isset( $schema['properties'] ) ? array_keys( $schema['properties'] ) : array();

		$this->assertContains( 'title', $properties );
		$this->assertContains( 'content', $properties );
		$this->assertContains( 'excerpt', $properties );
		$this->assertContains( 'fields', $properties );
		$this->assertContains( 'force', $properties );
	}
}
