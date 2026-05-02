<?php
/**
 * Tests for the unified Healthcare Toolkit role-to-capability map.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Healthcare_Capabilities' ) ) {
	$caps_path = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/class-wp-mcp-ai-healthcare-capabilities.php';
	if ( file_exists( $caps_path ) ) {
		require_once $caps_path;
	}
}

/**
 * Test case for WP_MCP_AI_Healthcare_Capabilities.
 */
class Test_Healthcare_Capabilities extends WP_UnitTestCase {

	/**
	 * Drop filters between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_healthcare_capabilities' );
		parent::tearDown();
	}

	/**
	 * Default map exposes the expected logical slugs.
	 */
	public function test_default_map() {
		$map = WP_MCP_AI_Healthcare_Capabilities::get_map();
		$this->assertArrayHasKey( 'view_member', $map );
		$this->assertArrayHasKey( 'log_vital_signs', $map );
		$this->assertArrayHasKey( 'view_medical_imaging', $map );
		$this->assertArrayHasKey( 'export_phi', $map );
	}

	/**
	 * Unknown logical slugs fall back to `manage_options`.
	 */
	public function test_unknown_slug_falls_back_safely() {
		$this->assertSame(
			'manage_options',
			WP_MCP_AI_Healthcare_Capabilities::resolve( 'no_such_logical_cap' )
		);
	}

	/**
	 * Resolve produces the right WordPress capability.
	 */
	public function test_resolve() {
		$this->assertSame( 'edit_posts', WP_MCP_AI_Healthcare_Capabilities::resolve( 'view_member' ) );
		$this->assertSame( 'manage_options', WP_MCP_AI_Healthcare_Capabilities::resolve( 'export_phi' ) );
		$this->assertSame( 'view_medical_imaging', WP_MCP_AI_Healthcare_Capabilities::resolve( 'view_medical_imaging' ) );
	}

	/**
	 * Filter can override the mapping.
	 */
	public function test_filter_override() {
		add_filter(
			'wp_mcp_ai_healthcare_capabilities',
			static function ( $map ) {
				$map['view_member'] = 'read';
				return $map;
			}
		);
		$this->assertSame( 'read', WP_MCP_AI_Healthcare_Capabilities::resolve( 'view_member' ) );
	}

	/**
	 * `current_user_can()` reflects the resolved capability.
	 */
	public function test_current_user_can() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( WP_MCP_AI_Healthcare_Capabilities::current_user_can( 'edit_member' ) );

		$sub_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );
		$this->assertFalse( WP_MCP_AI_Healthcare_Capabilities::current_user_can( 'edit_member' ) );
	}
}
