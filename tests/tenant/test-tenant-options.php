<?php
/**
 * Tenant Options Tests
 *
 * @package WP_MCP_AI
 */

/**
 * Test tenant-scoped options helper.
 */
class Test_Tenant_Options extends WP_UnitTestCase {

	/**
	 * Clean up test options after each test.
	 */
	public function tear_down() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_mcp_ai_%_test_%'" );
		// phpcs:enable
		parent::tear_down();
	}

	/**
	 * Scoped option should be prefixed with tenant type and ID.
	 */
	public function test_scoped_key_is_prefixed() {
		$opts = new WP_MCP_AI_Tenant_Options( 'school', 42 );

		$opts->update( 'test_setting', 'hello', false );

		$value = get_option( 'wp_mcp_ai_school_42_test_setting' );
		$this->assertEquals( 'hello', $value );
	}

	/**
	 * Get() should retrieve scoped option value.
	 */
	public function test_get_scoped_option() {
		$opts = new WP_MCP_AI_Tenant_Options( 'school', 1 );
		$opts->update( 'test_get', 'value_a', false );

		$this->assertEquals( 'value_a', $opts->get( 'test_get' ) );
	}

	/**
	 * Default value should be returned when option does not exist.
	 */
	public function test_get_returns_default() {
		$opts = new WP_MCP_AI_Tenant_Options( 'school', 99 );
		$this->assertEquals( 'fallback', $opts->get( 'nonexistent', 'fallback' ) );
	}

	/**
	 * Different tenants should have independent option values.
	 */
	public function test_tenant_isolation() {
		$opts_a = new WP_MCP_AI_Tenant_Options( 'school', 1 );
		$opts_b = new WP_MCP_AI_Tenant_Options( 'school', 2 );

		$opts_a->update( 'test_isolation', 'tenant_a_value', false );
		$opts_b->update( 'test_isolation', 'tenant_b_value', false );

		$this->assertEquals( 'tenant_a_value', $opts_a->get( 'test_isolation' ) );
		$this->assertEquals( 'tenant_b_value', $opts_b->get( 'test_isolation' ) );
	}

	/**
	 * Delete() should remove scoped option from database.
	 */
	public function test_delete_scoped_option() {
		$opts = new WP_MCP_AI_Tenant_Options( 'school', 1 );
		$opts->update( 'test_delete', 'value', false );

		$opts->delete( 'test_delete' );
		$this->assertFalse( get_option( 'wp_mcp_ai_school_1_test_delete' ) );
	}

	/**
	 * Type-level options should be shared across tenant IDs.
	 */
	public function test_type_level_options() {
		$opts = new WP_MCP_AI_Tenant_Options( 'school', 1 );
		$opts->update_type_option( 'global_setting', 'shared', false );

		// A different tenant ID of the same type should see the same value.
		$opts2 = new WP_MCP_AI_Tenant_Options( 'school', 999 );
		$this->assertEquals( 'shared', $opts2->get_type_option( 'global_setting' ) );
	}

	/**
	 * From_context() should return null when no tenant is resolved.
	 */
	public function test_from_context_returns_null_without_tenant() {
		wp_set_current_user( 0 );
		$opts = WP_MCP_AI_Tenant_Options::from_context();
		$this->assertNull( $opts );
	}

	/**
	 * From_context() should return instance when tenant is resolved.
	 */
	public function test_from_context_returns_instance_with_tenant() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', 42 );
		$opts = WP_MCP_AI_Tenant_Options::from_context();

		$this->assertInstanceOf( WP_MCP_AI_Tenant_Options::class, $opts );
		WP_MCP_AI_Tenant_Context::reset();
	}
}
