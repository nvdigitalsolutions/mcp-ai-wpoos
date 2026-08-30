<?php
/**
 * CRM Data Store Tenant Isolation Tests
 *
 * Verifies that the CRM Data Store migration (get_store → get_tenant_store)
 * correctly isolates deal and lead data across tenant boundaries.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test CRM data store tenant isolation.
 */
class Test_CRM_Data_Store_Isolation extends WP_UnitTestCase {

	/**
	 * Tenant A ID.
	 *
	 * @var int
	 */
	private $tenant_a_id;

	/**
	 * Tenant B ID.
	 *
	 * @var int
	 */
	private $tenant_b_id;

	/**
	 * User A ID (Tenant A).
	 *
	 * @var int
	 */
	private $user_a_id;

	/**
	 * User B ID (Tenant B).
	 *
	 * @var int
	 */
	private $user_b_id;

	/**
	 * Set up test tenants and users.
	 */
	public function set_up() {
		parent::set_up();

		// Load tenant infrastructure classes when this suite runs in
		// isolation. In a full run they are loaded by the plugin bootstrap,
		// so these requires are no-ops there.
		if ( ! class_exists( 'WP_MCP_AI_Tenant_Database' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tenant/class-wp-mcp-ai-tenant-database.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tenant_Context' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tenant/class-wp-mcp-ai-tenant-context.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-toolkit-data-store-factory.php';
		}

		// Create the tenant tables if they are missing. Production wires
		// this via admin_init and the activation hook, neither of which
		// fire under WP_UnitTestCase.
		if ( ! WP_MCP_AI_Tenant_Database::tables_installed() ) {
			WP_MCP_AI_Tenant_Database::create_tables();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenants';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'company',
				'tenant_name' => 'Test Company A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'company',
				'tenant_name' => 'Test Company B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable

		$this->user_a_id = self::factory()->user->create();
		$this->user_b_id = self::factory()->user->create();

		WP_MCP_AI_Tenant_Database::assign_user( $this->user_a_id, 'company', $this->tenant_a_id, true );
		WP_MCP_AI_Tenant_Database::assign_user( $this->user_b_id, 'company', $this->tenant_b_id, true );

		// Ensure tenant isolation feature flag is off for these tests
		// (the store handles scoping independently via get_tenant_store).
		delete_option( 'wp_mcp_ai_tenant_isolation_enabled' );
	}

	/**
	 * Tear down test data.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		wp_set_current_user( 0 );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Test Company%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenant_user_map" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Helper: get tenant-aware CRM deal store.
	 *
	 * @param string $tenant_type Tenant type.
	 * @param int    $tenant_id   Tenant ID.
	 * @return WP_MCP_AI_Toolkit_Data_Store
	 */
	private function get_deal_store( $tenant_type, $tenant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->markTestSkipped( 'Data Store Factory not available.' );
		}
		$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'deals' );
		$store->set_tenant_context( $tenant_type, $tenant_id );
		return $store;
	}

	/**
	 * Helper: get tenant-aware CRM lead store.
	 *
	 * @param string $tenant_type Tenant type.
	 * @param int    $tenant_id   Tenant ID.
	 * @return WP_MCP_AI_Toolkit_Data_Store
	 */
	private function get_lead_store( $tenant_type, $tenant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->markTestSkipped( 'Data Store Factory not available.' );
		}
		$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
		$store->set_tenant_context( $tenant_type, $tenant_id );
		return $store;
	}

	/**
	 * Test: Create deal as Tenant A, Tenant B cannot see it.
	 */
	public function test_cross_tenant_deal_visibility() {
		$store_a = $this->get_deal_store( 'company', $this->tenant_a_id );
		$store_b = $this->get_deal_store( 'company', $this->tenant_b_id );

		// Create deal as Tenant A.
		$deal_id = $store_a->create_item(
			array(
				'title'      => 'Tenant A Secret Deal',
				'deal_name'  => 'Secret Deal A',
				'amount'     => 100000,
				'created_at' => current_time( 'mysql' ),
			)
		);
		$this->assertIsInt( $deal_id, 'Deal creation should return a post ID.' );
		$this->assertGreaterThan( 0, $deal_id );

		// Tenant B tries to get it — should fail.
		$result_b = $store_b->get_item( $deal_id );
		$this->assertWPError( $result_b, 'Tenant B should not see Tenant A\'s deal.' );
		$this->assertEquals( 'item_not_found', $result_b->get_error_code() );

		// Tenant A can see it.
		$result_a = $store_a->get_item( $deal_id );
		$this->assertIsArray( $result_a );
		$this->assertEquals( 'Tenant A Secret Deal', $result_a['title'] );
	}

	/**
	 * Test: Create lead as Tenant A, Tenant B cannot update or delete it.
	 */
	public function test_cross_tenant_lead_mutation_denied() {
		$store_a = $this->get_lead_store( 'company', $this->tenant_a_id );
		$store_b = $this->get_lead_store( 'company', $this->tenant_b_id );

		// Create lead as Tenant A.
		$lead_id = $store_a->create_item(
			array(
				'title'      => 'Tenant A Lead',
				'first_name' => 'Alice',
				'last_name'  => 'Anderson',
				'email'      => 'alice@tenanta.test',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$this->assertGreaterThan( 0, $lead_id );

		// Tenant B attempts update — should fail.
		$update_b = $store_b->update_item(
			$lead_id,
			array( 'first_name' => 'Hacked' )
		);
		$this->assertWPError( $update_b, 'Tenant B should not update Tenant A\'s lead.' );

		// Tenant B attempts delete — should fail.
		$delete_b = $store_b->delete_item( $lead_id );
		$this->assertWPError( $delete_b, 'Tenant B should not delete Tenant A\'s lead.' );

		// Tenant A can update it.
		$update_a = $store_a->update_item(
			$lead_id,
			array( 'first_name' => 'Alicia' )
		);
		$this->assertTrue( $update_a );

		// Verify Tenant A's update persisted.
		$result = $store_a->get_item( $lead_id );
		$this->assertEquals( 'Alicia', $result['first_name'] );
	}

	/**
	 * Test: List deals returns only current tenant's data.
	 */
	public function test_list_deals_returns_only_tenant_data() {
		$store_a = $this->get_deal_store( 'company', $this->tenant_a_id );
		$store_b = $this->get_deal_store( 'company', $this->tenant_b_id );

		// Create deals for both tenants.
		$store_a->create_item(
			array(
				'title'      => 'Deal A1',
				'deal_name'  => 'A1',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$store_a->create_item(
			array(
				'title'      => 'Deal A2',
				'deal_name'  => 'A2',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$store_b->create_item(
			array(
				'title'      => 'Deal B1',
				'deal_name'  => 'B1',
				'created_at' => current_time( 'mysql' ),
			)
		);

		// Tenant A lists — should see only A1 and A2.
		$deals_a  = $store_a->query_items( array( 'per_page' => 50 ) );
		$titles_a = wp_list_pluck( $deals_a, 'title' );
		$this->assertContains( 'Deal A1', $titles_a );
		$this->assertContains( 'Deal A2', $titles_a );
		$this->assertNotContains( 'Deal B1', $titles_a );

		// Tenant B lists — should see only B1.
		$deals_b  = $store_b->query_items( array( 'per_page' => 50 ) );
		$titles_b = wp_list_pluck( $deals_b, 'title' );
		$this->assertContains( 'Deal B1', $titles_b );
		$this->assertNotContains( 'Deal A1', $titles_b );
		$this->assertNotContains( 'Deal A2', $titles_b );
	}

	/**
	 * Test: Move deal stage fails for cross-tenant deal.
	 */
	public function test_cross_tenant_deal_stage_change_denied() {
		$store_a = $this->get_deal_store( 'company', $this->tenant_a_id );
		$store_b = $this->get_deal_store( 'company', $this->tenant_b_id );

		$deal_id = $store_a->create_item(
			array(
				'title'          => 'Stage Test Deal',
				'deal_name'      => 'Stage Deal',
				'pipeline_stage' => 'qualification',
				'created_at'     => current_time( 'mysql' ),
			)
		);

		// Tenant B tries to change the stage — should fail.
		$result = $store_b->update_item(
			$deal_id,
			array( 'pipeline_stage' => 'closed_won' )
		);
		$this->assertWPError( $result, 'Tenant B should not change Tenant A\'s deal stage.' );
	}

	/**
	 * Test: Convert lead to customer preserves tenant context.
	 *
	 * The convert_lead_to_customer tool uses get_tenant_store() for both
	 * lead and deal stores.  This test verifies that a cross-tenant mutation
	 * is denied at the store level.
	 */
	public function test_lead_conversion_preserves_tenant_context() {
		$lead_store_a = $this->get_lead_store( 'company', $this->tenant_a_id );
		$deal_store_a = $this->get_deal_store( 'company', $this->tenant_a_id );
		$lead_store_b = $this->get_lead_store( 'company', $this->tenant_b_id );

		// Create lead as Tenant A.
		$lead_id = $lead_store_a->create_item(
			array(
				'title'           => 'Lead To Convert',
				'first_name'      => 'Bob',
				'last_name'       => 'Builder',
				'email'           => 'bob@tenanta.test',
				'lifecycle_stage' => 'lead',
				'created_at'      => current_time( 'mysql' ),
			)
		);

		// Tenant B cannot access this lead.
		$result_b = $lead_store_b->get_item( $lead_id );
		$this->assertWPError( $result_b );

		// Tenant A updates lifecycle stage to 'customer'.
		$update = $lead_store_a->update_item(
			$lead_id,
			array(
				'lifecycle_stage' => 'customer',
				'updated_at'      => current_time( 'mysql' ),
			)
		);
		$this->assertTrue( $update );

		// Tenant A can still read the updated lead.
		$converted = $lead_store_a->get_item( $lead_id );
		$this->assertEquals( 'customer', $converted['lifecycle_stage'] );

		// Tenant B still cannot read it (even after conversion).
		$result_b2 = $lead_store_b->get_item( $lead_id );
		$this->assertWPError( $result_b2 );
	}
}
