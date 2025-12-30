<?php
/**
 * Tests for WooCommerce-powered tools.
 */
class WP_MCP_AI_Woo_Tool_Availability_Test extends WP_UnitTestCase {
	/**
	 * Ensure the WooCommerce products tool reports missing dependencies.
	 */
	public function test_products_tool_requires_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is already loaded for subsequent integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
	}

	/**
	 * Ensure the WooCommerce orders tool reports missing dependencies.
	 */
	public function test_orders_tool_requires_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is already loaded for subsequent integration tests.' );
		}

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
	}
}

/**
 * Execution tests with stubbed WooCommerce helpers.
 */
class WP_MCP_AI_Woo_Tool_Execution_Test extends WP_UnitTestCase {
	/**
	 * Prepare WooCommerce stubs.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/helpers/woocommerce-stubs.php';

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_woocommerce' );
			$role->add_cap( 'view_woocommerce_reports' );
		}
	}

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
	}

	/**
	 * Ensure unauthenticated users cannot query WooCommerce orders.
	 */
	public function test_orders_tool_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure unauthenticated users cannot query WooCommerce products.
	 */
	public function test_products_tool_requires_login() {
		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure the orders tool enforces capability checks.
	 */
	public function test_orders_tool_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure the products tool enforces capability checks.
	 */
	public function test_products_tool_requires_permissions() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure orders return structured data for administrators.
	 */
	public function test_orders_tool_returns_recent_orders() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
		$result = $tool->execute(
			array(
				'limit'  => 10,
				'status' => 'completed',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'completed', $result[0]['status'] );

		global $wp_mcp_ai_wc_orders_args;
		$this->assertSame( 10, $wp_mcp_ai_wc_orders_args['limit'] );
		$this->assertSame( 'completed', $wp_mcp_ai_wc_orders_args['status'] );
	}

	/**
	 * Ensure the products tool honours filters and returns structured payloads.
	 */
	public function test_products_tool_returns_filtered_products() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
		$result = $tool->execute(
			array(
				'limit'        => 25,
				'sku'          => ' SKU-ONE ',
				'status'       => 'Publish',
				'stock_status' => 'InStock',
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'SKU-ONE', $result[0]['sku'] );
		$this->assertSame( 'instock', $result[0]['stock_status'] );

		global $wp_mcp_ai_wc_products_args;
		$this->assertSame( 20, $wp_mcp_ai_wc_products_args['limit'] );
		$this->assertSame( 'SKU-ONE', $wp_mcp_ai_wc_products_args['sku'] );
		$this->assertSame( 'publish', $wp_mcp_ai_wc_products_args['status'] );
		$this->assertSame( 'instock', $wp_mcp_ai_wc_products_args['stock_status'] );
	}
}
