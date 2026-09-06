<?php
/**
 * Tenant context port tests (Wave E4, sub-cluster 1).
 *
 * Characterization suite for the ported `TenantContext`: the
 * four-source resolution order (header → user meta → assistant meta →
 * multisite), per-source error codes, the fail-closed
 * `tenant_not_resolved` envelope, request-lifetime caching, and the
 * set/accessor contract. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Tenant\TenantContext;

/**
 * Tenant context characterization.
 */
class Test_Tenant_Context extends \WP_UnitTestCase {

	/**
	 * Reset the singleton between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		TenantContext::reset();
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_X_WP_MCP_AI_TENANT'], $_REQUEST['assistant_id'] );
		parent::tearDown();
	}

	public function test_resolve_returns_error_when_no_source(): void {
		wp_set_current_user( 0 );

		$result = TenantContext::instance()->resolve();
		$this->assertWPError( $result );
		$this->assertEquals( 'tenant_not_resolved', $result->get_error_code() );
	}

	public function test_resolve_from_header(): void {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'school:42';

		$result = TenantContext::instance()->resolve();

		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 42, $result['id'] );
	}

	public function test_resolve_from_invalid_header_fails_closed(): void {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'invalid-format';

		$result = TenantContext::instance()->resolve();

		$this->assertWPError( $result );
		$this->assertEquals( 'tenant_not_resolved', $result->get_error_code() );
	}

	public function test_resolve_from_header_zero_id_errors(): void {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = 'school:0';

		$result = TenantContext::instance()->resolve();

		$this->assertWPError( $result );
	}

	public function test_resolve_from_user_meta(): void {
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

		$result = TenantContext::instance()->resolve();

		$this->assertIsArray( $result );
		$this->assertEquals( 'company', $result['type'] );
		$this->assertEquals( 99, $result['id'] );
	}

	public function test_resolve_user_without_meta_falls_through(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$result = TenantContext::instance()->resolve();
		$this->assertWPError( $result );
	}

	public function test_resolve_from_assistant_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta(
			$post_id,
			'_wp_mcp_ai_bound_tenant',
			array(
				'type' => 'eca',
				'id'   => 7,
			)
		);
		$_REQUEST['assistant_id'] = (string) $post_id;

		$result = TenantContext::instance()->resolve();

		$this->assertIsArray( $result );
		$this->assertEquals( 'eca', $result['type'] );
		$this->assertEquals( 7, $result['id'] );
	}

	public function test_set_and_get(): void {
		$result = TenantContext::instance()->set( 'school', 1 );

		$this->assertIsArray( $result );
		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 1, $result['id'] );

		$this->assertEquals( 'school', TenantContext::instance()->get_type() );
		$this->assertEquals( 1, TenantContext::instance()->get_id() );
		$this->assertTrue( TenantContext::instance()->is_resolved() );
	}

	public function test_context_is_cached_after_resolution(): void {
		TenantContext::instance()->set( 'school', 1 );

		$result = TenantContext::instance()->resolve();

		$this->assertEquals( 'school', $result['type'] );
		$this->assertEquals( 1, $result['id'] );
	}

	public function test_reset_clears_singleton(): void {
		TenantContext::instance()->set( 'school', 1 );
		TenantContext::reset();

		$result = TenantContext::instance()->resolve();
		$this->assertWPError( $result );
	}

	public function test_empty_header_fails(): void {
		$_SERVER['HTTP_X_WP_MCP_AI_TENANT'] = '';

		$result = TenantContext::instance()->resolve();

		$this->assertWPError( $result );
	}

	public function test_is_resolved_false_before_resolution(): void {
		$this->assertFalse( TenantContext::instance()->is_resolved() );
		$this->assertEquals( '', TenantContext::instance()->get_type() );
		$this->assertEquals( 0, TenantContext::instance()->get_id() );
	}
}
