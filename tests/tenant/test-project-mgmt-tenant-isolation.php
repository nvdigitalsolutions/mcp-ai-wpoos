<?php
/**
 * Project Management Tenant Isolation Tests
 *
 * Verifies centralized save_post hook correctly stamps tenant meta on
 * project management CPTs and cross-tenant access is denied.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test project management tenant isolation.
 */
class Test_Project_Mgmt_Tenant_Isolation extends WP_UnitTestCase {

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
				'tenant_type' => 'team',
				'tenant_name' => 'Team Alpha',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'team',
				'tenant_name' => 'Team Beta',
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Team %'" );
		// phpcs:enable
		parent::tear_down();
	}

	/**
	 * Test: Project created as Tenant A is invisible to Tenant B.
	 */
	public function test_project_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'team', $this->tenant_a_id );

		$project_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'Secret Project Alpha',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $project_id );
		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $project_id, '_tenant_id', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'team', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_project',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $project_id, $query->posts );
	}

	/**
	 * Test: Task created as Tenant A is invisible to Tenant B.
	 */
	public function test_task_cross_tenant_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'team', $this->tenant_a_id );

		$task_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Confidential Task',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $task_id );
		$this->assertEquals( 'team', get_post_meta( $task_id, '_tenant_type', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'team', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $task_id, $query->posts );
	}
}
