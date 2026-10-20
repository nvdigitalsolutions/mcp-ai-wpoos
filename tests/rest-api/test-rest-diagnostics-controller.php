<?php
/**
 * Tests the diagnostics REST surface (2.4.0, Proposal 084 Phase 3):
 * GET /diagnostics/overview.
 *
 * Covers: manage_options gating and the consolidated section envelope the
 * Complete Console Diagnostics page consumes (health, system,
 * settings_dashboard, mcp_server, providers, rest_context, pro_dashboard).
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Diagnostics_Controller_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Diagnostics_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * An administrator receives every consolidated section.
	 */
	public function test_overview_envelope_as_admin() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Diagnostics_Controller() )->get_overview();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'generated_at', $data['data'] );
		foreach ( array( 'health', 'system', 'settings_dashboard', 'mcp_server', 'providers', 'rest_context', 'pro_dashboard' ) as $section ) {
			$this->assertArrayHasKey( $section, $data['data'], "Missing section: {$section}" );
		}
	}

	/**
	 * The health section reuses the rest-health checker (status + checks).
	 */
	public function test_health_section_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Diagnostics_Controller() )->get_overview();
		$data     = $response->get_data();

		$health = $data['data']['health'];
		$this->assertIsArray( $health );
		$this->assertArrayHasKey( 'status', $health );
		$this->assertContains( $health['status'], array( 'ok', 'degraded', 'unavailable' ), true );
	}

	/**
	 * The providers section maps enabled providers onto the SPA shape.
	 */
	public function test_providers_section_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Diagnostics_Controller() )->get_overview();
		$data     = $response->get_data();

		$this->assertIsArray( $data['data']['providers'] );
		foreach ( $data['data']['providers'] as $provider ) {
			$this->assertArrayHasKey( 'slug', $provider );
			$this->assertArrayHasKey( 'label', $provider );
			$this->assertArrayHasKey( 'has_credentials', $provider );
			$this->assertArrayHasKey( 'key_source', $provider );
			$this->assertIsBool( $provider['has_credentials'] );
		}
	}

	/**
	 * The system section never leaks credentials — version facts only.
	 */
	public function test_system_section_shape() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$response = ( new WP_MCP_AI_REST_Diagnostics_Controller() )->get_overview();
		$data     = $response->get_data();

		$system = $data['data']['system'];
		$this->assertArrayHasKey( 'wp_version', $system );
		$this->assertArrayHasKey( 'php_version', $system );
		$this->assertArrayHasKey( 'plugin_version', $system );
		$this->assertIsBool( $system['is_multisite'] );
	}
}
