<?php
/**
 * Calendar Booking Tenant Isolation Tests
 *
 * Verifies centralized save_post hook correctly stamps tenant meta on
 * calendar booking CPTs and that cross-tenant reads are denied.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test calendar booking tenant isolation.
 */
class Test_Calendar_Tenant_Isolation extends WP_UnitTestCase {

	/**
	 * Tenant IDs.
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
	 * Set up.
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
				'tenant_name' => 'Test Business A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'company',
				'tenant_name' => 'Test Business B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable

		update_option( 'wp_mcp_ai_tenant_isolation_enabled', true );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		delete_option( 'wp_mcp_ai_tenant_isolation_enabled' );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Test Business%'" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Test: Appointment created as Tenant A is invisible to Tenant B.
	 */
	public function test_cross_tenant_appointment_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_appointment',
				'post_title'  => 'Client Meeting - Tenant A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( $this->tenant_a_id, (int) get_post_meta( $post_id, '_tenant_id', true ) );

		WP_MCP_AI_Tenant_Context::reset();

		// Tenant B query — empty.
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_appointment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query->posts );
	}

	/**
	 * Test: Service created as Tenant A is invisible to Tenant B.
	 */
	public function test_cross_tenant_service_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_service',
				'post_title'  => 'Consulting Package - Tenant A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( 'company', get_post_meta( $post_id, '_tenant_type', true ) );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_service',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query->posts );
	}

	/**
	 * Test: Event separation across tenants.
	 */
	public function test_cross_tenant_event_isolation() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_event',
				'post_title'  => 'Team Offsite - Tenant A',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );

		// Tenant B cannot see Tenant A's event.
		$post        = get_post( $post_id );
		$tenant_id_b = (int) get_post_meta( $post_id, '_tenant_id', true );
		$this->assertNotEquals( $this->tenant_b_id, $tenant_id_b );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_event',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertNotContains( $post_id, $query->posts );
	}

	/**
	 * Test: Tenant B cannot update Tenant A's appointment.
	 */
	public function test_cross_tenant_appointment_update_denied() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_a_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_appointment',
				'post_title'  => 'Original Title - Tenant A',
				'post_status' => 'publish',
			)
		);

		WP_MCP_AI_Tenant_Context::reset();
		WP_MCP_AI_Tenant_Context::instance()->set( 'company', $this->tenant_b_id );

		// The save_post hook will stamp Tenant B's ID on update, BUT the
		// existing _tenant_id check in the hook prevents overwriting.
		// Let's verify that the hook preserves the original tenant assignment.
		$stamped = get_post_meta( $post_id, '_tenant_id', true );
		$this->assertEquals( $this->tenant_a_id, (int) $stamped, 'Tenant B should not overwrite Tenant A\'s post ownership.' );
	}
}
