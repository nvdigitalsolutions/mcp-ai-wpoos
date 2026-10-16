<?php
/**
 * F&B Tenant Profile Tests — currency/locale/venue formatting + token rendering.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B tenant profile.
 */
class Test_Fnb_Tenant_Profile extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-settings.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-tenant-profile.php';
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Test: currency_decimals maps zero-decimal currencies correctly.
	 */
	public function test_currency_decimals() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			$this->markTestSkipped( 'F&B tenant profile not available' );
		}

		foreach ( array( 'LKR', 'JPY', 'KRW', 'VND' ) as $code ) {
			update_option( 'wp_mcp_ai_fnb_settings', array( 'currency' => $code ) );
			$this->assertSame( 0, WP_MCP_AI_Fnb_Tenant_Profile::currency_decimals(), "$code must be zero-decimal" );
		}

		foreach ( array( 'USD', 'EUR', 'GBP' ) as $code ) {
			update_option( 'wp_mcp_ai_fnb_settings', array( 'currency' => $code ) );
			$this->assertSame( 2, WP_MCP_AI_Fnb_Tenant_Profile::currency_decimals(), "$code must be 2-decimal" );
		}

		// Unset → 2 (default).
		update_option( 'wp_mcp_ai_fnb_settings', array() );
		$this->assertSame( 2, WP_MCP_AI_Fnb_Tenant_Profile::currency_decimals() );
	}

	/**
	 * Test: currency_symbol and money_suffix resolve (or stay empty when unset).
	 */
	public function test_currency_symbol_and_suffix() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			$this->markTestSkipped( 'F&B tenant profile not available' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'currency' => 'USD' ) );
		$this->assertSame( '$', WP_MCP_AI_Fnb_Tenant_Profile::currency_symbol() );
		$this->assertSame( ' (USD $)', WP_MCP_AI_Fnb_Tenant_Profile::money_suffix() );

		update_option( 'wp_mcp_ai_fnb_settings', array( 'currency' => 'EUR' ) );
		$this->assertSame( '€', WP_MCP_AI_Fnb_Tenant_Profile::currency_symbol() );

		// Unset → empty symbol and no suffix (never assert a wrong currency).
		update_option( 'wp_mcp_ai_fnb_settings', array() );
		$this->assertSame( '', WP_MCP_AI_Fnb_Tenant_Profile::currency_symbol() );
		$this->assertSame( '', WP_MCP_AI_Fnb_Tenant_Profile::money_suffix() );
	}

	/**
	 * Test: render() substitutes venue/currency tokens and falls back neutrally.
	 */
	public function test_render_tokens() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			$this->markTestSkipped( 'F&B tenant profile not available' );
		}

		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'venue_name' => 'Example Kitchen',
				'venue_type' => 'restaurant',
				'currency'   => 'USD',
				'owner_role' => 'the owner',
			)
		);

		$in  = 'You serve {{venue_name}} ({{venue_type}}) — amounts in {{currency}} for {{owner_role}}.';
		$out = WP_MCP_AI_Fnb_Tenant_Profile::render( $in );

		$this->assertStringContainsString( 'Example Kitchen', $out );
		$this->assertStringContainsString( 'restaurant', $out );
		$this->assertStringContainsString( 'USD', $out );
		$this->assertStringNotContainsString( '{{venue_name}}', $out );
		$this->assertStringNotContainsString( '{{currency}}', $out );

		// Fallback neutrals when unset.
		update_option( 'wp_mcp_ai_fnb_settings', array() );
		$fallback = WP_MCP_AI_Fnb_Tenant_Profile::render( 'You serve {{venue_name}}.' );
		$this->assertSame( 'You serve the venue.', $fallback );
	}
}
