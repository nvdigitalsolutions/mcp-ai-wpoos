<?php
/**
 * Tests for QuickBooks Desktop Sync Tool
 *
 * Tests the QuickBooks Desktop sync tool parameter validation,
 * error handling, and schema definitions.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for QuickBooks Desktop Sync Tool.
 */
class Test_QuickBooks_Desktop_Sync extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync
	 */
	protected $tool;

	/**
	 * Test admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user;

	/**
	 * Test subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_user;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php';
		} else {
			require_once dirname( __DIR__ ) . '/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-quickbooks-desktop-sync.php';
		}

		$this->tool = new WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync();

		// Create test users.
		$this->admin_user = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);

		$this->subscriber_user = $this->factory->user->create(
			array( 'role' => 'subscriber' )
		);
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'quickbooks_desktop_sync', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$name = $this->tool->get_name();
		$this->assertNotEmpty( $name );
		$this->assertStringContainsString( 'QuickBooks Desktop', $name );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$description = $this->tool->get_description();
		$this->assertNotEmpty( $description );
		$this->assertStringContainsString( 'QODBC', $description );
		$this->assertStringContainsString( 'quickbooks_desktop', $description );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertFalse( $schema['additionalProperties'] );

		// Check required parameters.
		$this->assertContains( 'connection_id', $schema['required'] );
		$this->assertContains( 'action', $schema['required'] );

		// Check that key properties exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'connection_id', $properties );
		$this->assertArrayHasKey( 'action', $properties );
		$this->assertArrayHasKey( 'entity', $properties );
		$this->assertArrayHasKey( 'fields', $properties );
		$this->assertArrayHasKey( 'where', $properties );
		$this->assertArrayHasKey( 'order_by', $properties );
		$this->assertArrayHasKey( 'limit', $properties );
		$this->assertArrayHasKey( 'record_data', $properties );
		$this->assertArrayHasKey( 'record_id', $properties );
	}

	/**
	 * Test schema action enum values.
	 */
	public function test_schema_action_enum() {
		$schema  = $this->tool->get_parameters_schema();
		$actions = $schema['properties']['action']['enum'];

		$expected_actions = array(
			'query',
			'list_tables',
			'get_customers',
			'get_invoices',
			'get_items',
			'get_vendors',
			'get_employees',
			'get_accounts',
			'create_record',
			'update_record',
			'sync_status',
		);

		foreach ( $expected_actions as $action ) {
			$this->assertContains( $action, $actions, "Action '{$action}' should be in the enum list." );
		}
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * Test that execute fails without permission.
	 */
	public function test_execute_requires_manage_options() {
		// Use user_id = 0 (no user) to reliably trigger the permission check.
		$result = $this->tool->execute(
			array(
				'connection_id' => 'test_conn',
				'action'        => 'list_tables',
			),
			array( 'user_id' => 0 )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_qbd_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that execute requires connection_id.
	 */
	public function test_execute_requires_connection_id() {
		wp_set_current_user( $this->admin_user );

		$result = $this->tool->execute(
			array(
				'action' => 'list_tables',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_qbd_missing_connection', $result->get_error_code() );
	}

	/**
	 * Test that execute returns error for non-existent connection.
	 */
	public function test_execute_nonexistent_connection() {
		wp_set_current_user( $this->admin_user );

		// If the Remote Site Manager is available, this should return not_found.
		// If not available, it will return no_manager error.
		$result = $this->tool->execute(
			array(
				'connection_id' => 'nonexistent_id',
				'action'        => 'list_tables',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'wp_mcp_ai_qbd_connection_not_found', 'wp_mcp_ai_qbd_no_manager' )
		);
	}

	/**
	 * Test that allowed entities constant contains key QuickBooks tables.
	 */
	public function test_allowed_entities_includes_key_tables() {
		$entities = WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync::ALLOWED_ENTITIES;

		$this->assertContains( 'Customer', $entities );
		$this->assertContains( 'Invoice', $entities );
		$this->assertContains( 'Vendor', $entities );
		$this->assertContains( 'Employee', $entities );
		$this->assertContains( 'ItemInventory', $entities );
		$this->assertContains( 'Account', $entities );
		$this->assertContains( 'Bill', $entities );
		$this->assertContains( 'SalesReceipt', $entities );
		$this->assertContains( 'PurchaseOrder', $entities );
		$this->assertContains( 'Check', $entities );
		$this->assertContains( 'JournalEntry', $entities );
		$this->assertContains( 'Company', $entities );
	}

	/**
	 * Test that writable entities are a subset of allowed entities.
	 */
	public function test_writable_entities_are_subset_of_allowed() {
		$allowed  = WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync::ALLOWED_ENTITIES;
		$writable = WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync::WRITABLE_ENTITIES;

		foreach ( $writable as $entity ) {
			$this->assertContains(
				$entity,
				$allowed,
				"Writable entity '{$entity}' should also be in the allowed entities list."
			);
		}
	}

	/**
	 * Test schema limit property has correct bounds.
	 */
	public function test_schema_limit_bounds() {
		$schema = $this->tool->get_parameters_schema();
		$limit  = $schema['properties']['limit'];

		$this->assertEquals( 1, $limit['minimum'] );
		$this->assertEquals( 500, $limit['maximum'] );
		$this->assertEquals( 50, $limit['default'] );
	}

	/**
	 * Test MAX_ROWS constant.
	 */
	public function test_max_rows_constant() {
		$this->assertEquals( 500, WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync::MAX_ROWS );
	}

	/**
	 * Test that the tool implements the required interfaces.
	 */
	public function test_implements_required_interfaces() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $this->tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}
}
