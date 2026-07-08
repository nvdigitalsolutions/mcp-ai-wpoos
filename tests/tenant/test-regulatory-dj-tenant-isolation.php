<?php
/**
 * Regulatory Registration and DJ Management Tenant Isolation Tests
 *
 * Verifies centralized save_post hook correctly stamps tenant meta on
 * dynamically registered CPTs, preserves existing tenant assignments on
 * update, and does not interfere with non-tenant-scoped post types.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test regulatory and DJ management tenant isolation via centralized hook.
 */
class Test_Regulatory_DJ_Tenant_Isolation extends WP_UnitTestCase {

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
	 * Set up test tenants.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenants';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'company',
				'tenant_name' => 'Firm A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'company',
				'tenant_name' => 'Firm B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable
		update_option( 'wp_mcp_ai_tenant_isolation_enabled', true );
	}

	/**
	 * Tear down test data.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		delete_option( 'wp_mcp_ai_tenant_isolation_enabled' );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Firm %'" );
		// phpcs:enable
		parent::tear_down();
	}

	/**
	 * Test: Centralized save_post stamps any dynamically registered CPT.
	 */
	public function test_centralized_save_post_stamps_any_registered_cpt() {
		register_post_type(
			'mcp_test_reg',
			array(
				'public' => false,
				'label'  => 'Test Regulatory',
			)
		);

		add_filter(
			'wp_mcp_ai_tenant_scoped_post_types',
			function ( $types ) {
				$types[] = 'mcp_test_reg';
				return $types;
			}
		);

		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_test_reg',
				'post_title'  => 'Regulatory Filing A',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $post_id, '_tenant_id', true ) );
		$this->assertEquals( 'company', get_post_meta( $post_id, '_tenant_type', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_test_reg',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query->posts );

		remove_all_filters( 'wp_mcp_ai_tenant_scoped_post_types' );
		unregister_post_type( 'mcp_test_reg' );
	}

	/**
	 * Test: Non-tenant-scoped post type is unaffected by tenant context.
	 */
	public function test_non_scoped_cpt_unaffected_by_tenant_context() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Unscoped Blog Post',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );
		$this->assertEmpty(
			get_post_meta( $post_id, '_tenant_id', true ),
			'Non-tenant-scoped post type should not receive tenant meta'
		);
	}

	/**
	 * Test: Existing tenant assignment is preserved on update.
	 */
	public function test_existing_tenant_assignment_preserved() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		add_filter(
			'wp_mcp_ai_tenant_scoped_post_types',
			function ( $types ) {
				$types[] = 'mcp_ai_event';
				return $types;
			}
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_event',
				'post_title'  => 'Firm A Annual Gala',
				'post_status' => 'publish',
			)
		);

		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $post_id, '_tenant_id', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Firm A Annual Gala (Updated)',
			)
		);

		$this->assertEquals(
			$this->tenant_a_id,
			(int) get_post_meta( $post_id, '_tenant_id', true ),
			'Existing tenant assignment should be preserved on update'
		);

		remove_all_filters( 'wp_mcp_ai_tenant_scoped_post_types' );
	}
}
