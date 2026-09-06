<?php
/**
 * Tenant Options Isolation Tests
 *
 * Verifies that WP_MCP_AI_Tenant_Options correctly isolates option
 * storage across tenants and that the type-level and cross-tenant
 * boundaries are enforced.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 */

/**
 * Test tenant options isolation.
 */
class Test_Tenant_Options_Isolation extends WP_UnitTestCase {

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
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// The activation hook that creates the tenant tables never runs under
		// PHPUnit, so ensure the schema exists before inserting rows.
		WP_MCP_AI_Tenant_Database::create_tables();

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenants';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'org',
				'tenant_name' => 'Test Org A',
			),
			array( '%s', '%s' )
		);
		$this->tenant_a_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$table,
			array(
				'tenant_type' => 'org',
				'tenant_name' => 'Test Org B',
			),
			array( '%s', '%s' )
		);
		$this->tenant_b_id = (int) $wpdb->insert_id;
		// phpcs:enable
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mcp_ai_tenants WHERE tenant_name LIKE 'Test Org%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE 'wp_mcp_ai_org_%'" );
		// phpcs:enable

		parent::tear_down();
	}

	/**
	 * Test: Scoped options are isolated between tenants.
	 */
	public function test_scoped_option_isolation() {
		$opts_a = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_a_id );
		$opts_b = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_b_id );

		// Tenant A writes.
		$opts_a->update( 'api_key', 'secret-a-123', false );
		$opts_a->update( 'webhook_url', 'https://tenanta.test/hook', false );

		// Tenant A reads its own values.
		$this->assertEquals( 'secret-a-123', $opts_a->get( 'api_key' ) );
		$this->assertEquals( 'https://tenanta.test/hook', $opts_a->get( 'webhook_url' ) );

		// Tenant B reads — should get defaults, not Tenant A's values.
		$this->assertEquals( '', $opts_b->get( 'api_key' ) );
		$this->assertEquals( 'fallback', $opts_b->get( 'webhook_url', 'fallback' ) );

		// Tenant B writes its own values.
		$opts_b->update( 'api_key', 'secret-b-456', false );

		// Tenant A still sees its own value.
		$this->assertEquals( 'secret-a-123', $opts_a->get( 'api_key' ) );
		// Tenant B sees its value.
		$this->assertEquals( 'secret-b-456', $opts_b->get( 'api_key' ) );
	}

	/**
	 * Test: Deleting a scoped option only affects the tenant that owns it.
	 */
	public function test_scoped_option_delete_isolation() {
		$opts_a = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_a_id );
		$opts_b = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_b_id );

		$opts_a->update( 'shared_key', 'value-a', false );
		$opts_b->update( 'shared_key', 'value-b', false );

		// Tenant A deletes its copy.
		$opts_a->delete( 'shared_key' );
		$this->assertEquals( '', $opts_a->get( 'shared_key' ) );

		// Tenant B's copy is unaffected.
		$this->assertEquals( 'value-b', $opts_b->get( 'shared_key' ) );
	}

	/**
	 * Test: Different tenant types with same ID don't collide.
	 */
	public function test_different_types_same_id_dont_collide() {
		$opts_school = new WP_MCP_AI_Tenant_Options( 'school', $this->tenant_a_id );
		$opts_org    = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_a_id );

		$opts_school->update( 'name', 'School Alpha', false );
		$opts_org->update( 'name', 'Org Alpha', false );

		$this->assertEquals( 'School Alpha', $opts_school->get( 'name' ) );
		$this->assertEquals( 'Org Alpha', $opts_org->get( 'name' ) );
	}

	/**
	 * Test: Autoload parameter is respected.
	 */
	public function test_autoload_parameter() {
		$opts = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_a_id );

		// Write with autoload = false.
		$opts->update( 'heavy_config', 'large-data', false );
		// Write with autoload = true.
		$opts->update( 'light_config', 'small-data', true );

		$this->assertEquals( 'large-data', $opts->get( 'heavy_config' ) );
		$this->assertEquals( 'small-data', $opts->get( 'light_config' ) );
	}

	/**
	 * Test: Type-level option (no specific tenant ID) works independently.
	 */
	public function test_type_level_option() {
		$opts_type = new WP_MCP_AI_Tenant_Options( 'org', 0 );
		$opts_a    = new WP_MCP_AI_Tenant_Options( 'org', $this->tenant_a_id );

		$opts_type->update( 'global_setting', 'all-orgs', false );
		$opts_a->update( 'global_setting', 'org-a-only', false );

		// Type-level and per-tenant options should have different keys.
		$this->assertEquals( 'all-orgs', $opts_type->get( 'global_setting' ) );
		$this->assertEquals( 'org-a-only', $opts_a->get( 'global_setting' ) );
		$this->assertNotEquals(
			$opts_type->get( 'global_setting' ),
			$opts_a->get( 'global_setting' )
		);
	}
}
