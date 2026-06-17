<?php
/**
 * Tests for the Architectural Design code registry.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$path = dirname( __DIR__ ) . '/includes/tools/architectural-design/class-wp-mcp-ai-architectural-codes.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

/**
 * Test case for WP_MCP_AI_Architectural_Codes.
 */
class Test_Architectural_Codes extends WP_UnitTestCase {

	/**
	 * Class is loadable.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Architectural_Codes' ) );
	}

	/**
	 * Code packs return all required jurisdictions.
	 */
	public function test_supported_countries() {
		$countries = WP_MCP_AI_Architectural_Codes::get_supported_countries();
		$this->assertContains( 'LK', $countries );
		$this->assertContains( 'JM', $countries );
		$this->assertContains( 'US', $countries );
	}

	/**
	 * Default packs are sensibly mapped.
	 */
	public function test_default_pack_for_country() {
		$this->assertSame( 'lk_uda_2021', WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( 'LK' ) );
		$this->assertSame( 'jm_jnbc_2018', WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( 'JM' ) );
		$this->assertSame( 'us_ibc_2024', WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( 'US' ) );
		$this->assertSame( '', WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( 'XX' ) );
	}

	/**
	 * Per-country pack listing.
	 */
	public function test_packs_for_country() {
		$lk = WP_MCP_AI_Architectural_Codes::get_packs_for_country( 'LK' );
		$this->assertGreaterThanOrEqual( 4, count( $lk ) );
		$this->assertArrayHasKey( 'lk_uda_2021', $lk );

		$us = WP_MCP_AI_Architectural_Codes::get_packs_for_country( 'US' );
		$this->assertArrayHasKey( 'us_ibc_2024', $us );
		$this->assertArrayHasKey( 'us_ada_2010', $us );
	}

	/**
	 * Single pack lookup returns structured rules.
	 */
	public function test_get_pack_returns_rules() {
		$pack = WP_MCP_AI_Architectural_Codes::get_pack( 'jm_jnbc_2018' );
		$this->assertIsArray( $pack );
		$this->assertSame( 'JM', $pack['country'] );
		$this->assertArrayHasKey( 'rules', $pack );
		$this->assertArrayHasKey( 'egress', $pack['rules'] );
		$this->assertArrayHasKey( 'structural', $pack['rules'] );
		$this->assertTrue( ! empty( $pack['rules']['structural']['opening_protection_required'] ) );

		$missing = WP_MCP_AI_Architectural_Codes::get_pack( 'no_such_pack' );
		$this->assertNull( $missing );
	}

	/**
	 * Merging multiple packs combines their categories.
	 */
	public function test_merge_rules() {
		$merged = WP_MCP_AI_Architectural_Codes::merge_rules( array( 'us_ibc_2024', 'us_ada_2010' ) );
		// IBC contributes egress rules.
		$this->assertArrayHasKey( 'egress', $merged );
		$this->assertGreaterThan( 0, $merged['egress']['min_corridor_width_m'] );
		// ADA contributes accessibility rules.
		$this->assertArrayHasKey( 'accessibility', $merged );
		$this->assertSame( 815.0, $merged['accessibility']['min_door_clear_width_mm'] );
	}

	/**
	 * Filter `wp_mcp_ai_arch_code_packs` allows partner registration.
	 */
	public function test_pack_filter() {
		$cb = function ( $packs ) {
			$packs['xx_partner_v1'] = array(
				'country'   => 'XX',
				'title'     => 'Partner Pack',
				'authority' => 'Partner',
				'reference' => 'Partner Doc 1',
				'rules'     => array(
					'egress' => array( 'min_exits' => 3 ),
				),
			);
			return $packs;
		};
		add_filter( 'wp_mcp_ai_arch_code_packs', $cb );

		$pack = WP_MCP_AI_Architectural_Codes::get_pack( 'xx_partner_v1' );
		$this->assertNotNull( $pack );
		$this->assertSame( 'XX', $pack['country'] );

		remove_filter( 'wp_mcp_ai_arch_code_packs', $cb );
	}
}
