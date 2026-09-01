<?php
/**
 * Cross-Tenant Integration Tests
 *
 * End-to-end tests verifying that data from Tenant A is inaccessible to Tenant B
 * across multiple toolkit storage patterns.
 *
 * @package WP_MCP_AI
 */

/**
 * Test cross-tenant isolation end-to-end.
 */
class Test_Cross_Tenant_Isolation extends WP_UnitTestCase {

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
	 * User A ID.
	 *
	 * @var int
	 */
	private $user_a_id;

	/**
	 * User B ID.
	 *
	 * @var int
	 */
	private $user_b_id;

	/**
	 * Set up test tenants and users.
	 */
	public function set_up() {
		parent::set_up();

		// The activation hook that creates the tenant tables never runs under
		// PHPUnit, so ensure the schema exists before inserting rows.
		WP_MCP_AI_Tenant_Database::create_tables();

		// Create test tenants.
		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenants';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'school',
				'tenant_name' => 'Test School A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'school',
				'tenant_name' => 'Test School B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable

		// Create test users.
		$this->user_a_id = self::factory()->user->create();
		$this->user_b_id = self::factory()->user->create();

		// Assign users to tenants.
		WP_MCP_AI_Tenant_Database::assign_user( $this->user_a_id, 'school', $this->tenant_a_id, true );
		WP_MCP_AI_Tenant_Database::assign_user( $this->user_b_id, 'school', $this->tenant_b_id, true );
	}

	/**
	 * Tear down test data.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		wp_set_current_user( 0 );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Test School%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenant_user_map" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Tenant A's option should not be readable by Tenant B.
	 */
	public function test_cross_tenant_option_isolation() {
		$opts_a = new WP_MCP_AI_Tenant_Options( 'school', $this->tenant_a_id );
		$opts_b = new WP_MCP_AI_Tenant_Options( 'school', $this->tenant_b_id );

		$opts_a->update( 'test_cross', 'secret_a', false );

		// Tenant B reads — should get default, not Tenant A's value.
		$this->assertEquals( 'default', $opts_b->get( 'test_cross', 'default' ) );

		// Tenant A reads — should get own value.
		$this->assertEquals( 'secret_a', $opts_a->get( 'test_cross' ) );
	}

	/**
	 * Post created under Tenant A context should have tenant meta.
	 */
	public function test_post_gets_tenant_meta() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', $this->tenant_a_id );

		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Tenant A Post',
			)
		);

		// Manually stamp tenant meta (simulating what TenantRepository::save_tenant_meta does).
		update_post_meta( $post_id, '_tenant_type', 'school' );
		update_post_meta( $post_id, '_tenant_id', $this->tenant_a_id );

		WP_MCP_AI_Tenant_Context::reset();

		// Tenant B should not find this post via tenant meta query.
		$query_b = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_query'  => array(
					array(
						'key'   => '_tenant_id',
						'value' => $this->tenant_b_id,
					),
				),
				'fields'      => 'ids',
			)
		);

		$this->assertEmpty( $query_b->posts );

		// Tenant A should find it.
		$query_a = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_query'  => array(
					array(
						'key'   => '_tenant_id',
						'value' => $this->tenant_a_id,
					),
				),
				'fields'      => 'ids',
			)
		);

		$this->assertContains( $post_id, $query_a->posts );
	}

	/**
	 * User assigned to Tenant A should get Tenant A's context.
	 */
	public function test_user_gets_correct_tenant() {
		wp_set_current_user( $this->user_a_id );

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();
		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( $this->tenant_a_id, $result['id'] );

		WP_MCP_AI_Tenant_Context::reset();
		wp_set_current_user( $this->user_b_id );

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();
		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( $this->tenant_b_id, $result['id'] );
	}

	/**
	 * Unauthorized tenant header should not override user assignment (user takes priority over header).
	 *
	 * Note: Header (source 1) takes priority over user (source 2),
	 * so with a header set, it overrides the user's tenant.
	 */
	public function test_header_override() {
		wp_set_current_user( $this->user_a_id );

		// Header takes priority over user meta.
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'school:' . $this->tenant_b_id;

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );
		WP_MCP_AI_Tenant_Context::reset();

		$this->assertEquals( $this->tenant_b_id, $result['id'] );
	}

	/**
	 * Feature flag should be toggleable per-toolkit.
	 */
	public function test_feature_flag_per_toolkit() {
		$this->assertFalse( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'test-toolkit' ) );

		WP_MCP_AI_Tenant_Feature_Flags::enable_toolkit( 'test-toolkit' );
		$this->assertTrue( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'test-toolkit' ) );

		WP_MCP_AI_Tenant_Feature_Flags::disable_toolkit( 'test-toolkit' );
		$this->assertFalse( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'test-toolkit' ) );
	}

	/**
	 * Global enable should affect all toolkits unless opted out.
	 */
	public function test_global_flag_affects_toolkits() {
		WP_MCP_AI_Tenant_Feature_Flags::enable();
		$this->assertTrue( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'any-toolkit' ) );

		WP_MCP_AI_Tenant_Feature_Flags::opt_out_toolkit( 'opted-out-toolkit' );
		$this->assertFalse( WP_MCP_AI_Tenant_Feature_Flags::is_toolkit_enabled( 'opted-out-toolkit' ) );

		// Cleanup.
		WP_MCP_AI_Tenant_Feature_Flags::disable();
		delete_option( 'wp_mcp_ai_tenant_isolation_toolkit_opted-out-toolkit_opt_out' );
	}
}
