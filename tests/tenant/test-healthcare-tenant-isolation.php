<?php
/**
 * Healthcare Tenant Isolation Tests
 *
 * Verifies that the centralized save_post hook correctly stamps tenant
 * meta on CPT-based healthcare posts (imaging studies, vital logs) and
 * that cross-tenant access is denied.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test healthcare tenant isolation via centralized save_post hook.
 */
class Test_Healthcare_Tenant_Isolation extends WP_UnitTestCase {

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
	 * Install tenant tables before per-test transactions begin.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		WP_MCP_AI_Tenant_Database::create_tables();
	}

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
				'tenant_type' => 'school',
				'tenant_name' => 'Test Clinic A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'school',
				'tenant_name' => 'Test Clinic B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable

		// Enable tenant isolation so save_post hook fires.
		update_option( 'wp_mcp_ai_tenant_isolation_enabled', true );

		// Register healthcare CPTs as tenant-scoped for this test.
		add_filter(
			'wp_mcp_ai_tenant_scoped_post_types',
			function ( $types ) {
				$types[] = 'mcp_ai_imaging_study';
				$types[] = 'mcp_ai_hc_vital_log';
				return array_unique( $types );
			}
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		delete_option( 'wp_mcp_ai_tenant_isolation_enabled' );
		remove_all_filters( 'wp_mcp_ai_tenant_scoped_post_types' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Test Clinic%'" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Test: Imaging study created as Tenant A is invisible to Tenant B.
	 */
	public function test_cross_tenant_imaging_study_isolation() {
		// Set Tenant A context and create an imaging study.
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_imaging_study',
				'post_title'  => 'CT Scan - Tenant A Patient',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		// save_post hook should have stamped tenant meta.
		$tenant_id = get_post_meta( $post_id, '_tenant_id', true );
		$this->assertEquals( $this->tenant_a_id, (int) $tenant_id );
		$this->assertEquals( 'school', get_post_meta( $post_id, '_tenant_type', true ) );

		WP_MCP_AI_Tenant_Context::reset();

		// Tenant B queries — should not see Tenant A's study.
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );
		$query_b = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_imaging_study',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query_b->posts );

		// Tenant A queries — should see it.
		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );
		$query_a = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_imaging_study',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertContains( $post_id, $query_a->posts );
	}

	/**
	 * Test: Vital log created as Tenant A is invisible to Tenant B.
	 */
	public function test_cross_tenant_vital_log_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_hc_vital_log',
				'post_title'  => 'Blood Pressure Reading - Tenant A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $post_id, '_tenant_id', true ) );

		WP_MCP_AI_Tenant_Context::reset();

		// Tenant B tries to read — denied.
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_b_id );
		$query_b = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_hc_vital_log',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query_b->posts );
	}

	/**
	 * Test: Non-tenant-scoped post type is unaffected.
	 */
	public function test_non_scoped_post_type_unaffected() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		// Create a regular post — should NOT get tenant meta stamped.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEmpty( get_post_meta( $post_id, '_tenant_id', true ) );
	}
}
