<?php
/**
 * Tenant Context Tests
 *
 * @package WP_MCP_AI
 */

/**
 * Test tenant context resolution from multiple sources.
 */
class Test_Tenant_Context extends WP_UnitTestCase {

	/**
	 * Reset tenant context between tests.
	 */
	public function tear_down() {
		WP_MCP_AI_Tenant_Context::reset();
		parent::tear_down();
	}

	/**
	 * Context should be resolvable when no source is available.
	 */
	public function test_resolve_returns_error_when_no_source() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();
		$this->assertWPError( $result );
		$this->assertEquals( 'tenant_not_resolved', $result->get_error_code() );
	}

	/**
	 * Context should resolve from HTTP header: "school:42".
	 */
	public function test_resolve_from_header() {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'school:42';

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );

		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 42, $result['id'] );
	}

	/**
	 * Invalid header format should return error.
	 */
	public function test_resolve_from_invalid_header_returns_error() {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'invalid-format';

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );

		$this->assertWPError( $result );
		$this->assertEquals( 'tenant_invalid_header', $result->get_error_code() );
	}

	/**
	 * Header with zero ID should return error.
	 */
	public function test_resolve_from_header_zero_id_returns_error() {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'school:0';

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );

		$this->assertWPError( $result );
	}

	/**
	 * Context should resolve from logged-in user meta.
	 */
	public function test_resolve_from_user_meta() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tenant',
			array(
				'type' => 'company',
				'id'   => 99,
			)
		);

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		$this->assertIsArray( $result );
		$this->assertEquals( 'company', $result['type'] );
		$this->assertEquals( 99, $result['id'] );
	}

	/**
	 * User with no tenant meta should fall through.
	 */
	public function test_resolve_user_without_meta_falls_through() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		// No _wp_mcp_ai_tenant meta set.

		// With no header and no multisite, this should fail.
		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();
		$this->assertWPError( $result );
	}

	/**
	 * Explicit set() should work and be retrievable.
	 */
	public function test_set_and_get() {
		$result = WP_MCP_AI_Tenant_Context::instance()->set( 'school', 1 );

		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 1, $result['id'] );

		$this->assertEquals( 'school', WP_MCP_AI_Tenant_Context::instance()->get_type() );
		$this->assertEquals( 1, WP_MCP_AI_Tenant_Context::instance()->get_id() );
		$this->assertTrue( WP_MCP_AI_Tenant_Context::instance()->is_resolved() );
	}

	/**
	 * Context once resolved should be cached.
	 */
	public function test_context_is_cached_after_resolution() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', 1 );

		// Resolve again — should return cached value.
		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 1, $result['id'] );
	}

	/**
	 * Reset should clear the singleton.
	 */
	public function test_reset_clears_singleton() {
		WP_MCP_AI_Tenant_Context::instance()->set( 'school', 1 );
		WP_MCP_AI_Tenant_Context::reset();

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();
		$this->assertWPError( $result );
	}

	/**
	 * Empty string in header should fail.
	 */
	public function test_empty_header_fails() {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = '';

		$result = WP_MCP_AI_Tenant_Context::instance()->resolve();

		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'] );

		$this->assertWPError( $result );
	}
}
