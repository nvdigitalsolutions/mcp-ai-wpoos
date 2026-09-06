<?php
/**
 * Test the Pro SPA v2 shortcode ([nvoos_pro_spa]).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test class for WP_MCP_AI_Pro_SPA_Shortcode.
 *
 * @since 2.1.0
 */
class Test_WP_MCP_AI_Pro_SPA_Shortcode extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Shortcode' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-shortcode.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Config' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-config.php';
		}

		WP_MCP_AI_Pro_SPA_Shortcode::register();
	}

	/**
	 * Test the shortcode is registered.
	 */
	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( 'nvoos_pro_spa' ) );
	}

	/**
	 * Test the render returns the root container with data-config.
	 */
	public function test_render_returns_root_container() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array() );

		$this->assertIsString( $out );
		$this->assertStringContainsString( 'nvoos-pro-spa-root', $out );
		$this->assertStringContainsString( 'nvoos-pro-spa-embedded', $out );
		$this->assertStringContainsString( 'data-config', $out );
	}

	/**
	 * Test the assistant_id attribute is sanitized with absint.
	 */
	public function test_render_sanitizes_assistant_id() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'assistant_id' => '42abc' ) );

		// absint() strips non-digits; 42 should appear in the JSON config.
		$this->assertStringContainsString( '&quot;assistantId&quot;:42', $out );
	}

	/**
	 * Test an unknown theme is clamped to auto.
	 */
	public function test_render_clamps_unknown_theme_to_auto() {
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'theme' => 'rainbow' ) );

		$this->assertStringContainsString( '&quot;theme&quot;:&quot;auto&quot;', $out );
	}

	/**
	 * Test the can_render filter short-circuits the render.
	 */
	public function test_render_respects_can_render_filter() {
		add_filter( 'nvoos_pro_spa_can_render', '__return_false' );
		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array() );
		remove_filter( 'nvoos_pro_spa_can_render', '__return_false' );

		$this->assertSame( '', $out );
	}

	/**
	 * Test guest mode returns empty when no token can be minted.
	 *
	 * Logged-out visitor + guest=1 + no valid assistant => no renderable
	 * surface (and nothing sensitive leaked).
	 */
	public function test_render_guest_without_token_returns_empty() {
		wp_set_current_user( 0 );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render(
			array(
				'guest'        => '1',
				'assistant_id' => '0',
			)
		);

		$this->assertSame( '', $out );
	}

	/**
	 * Test admin mode is downgraded to embedded for non-admins.
	 */
	public function test_render_admin_mode_downgraded_for_non_admin() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'mode' => 'admin' ) );

		$this->assertStringContainsString( '&quot;mode&quot;:&quot;embedded&quot;', $out );
	}

	/**
	 * Test guest mode is ignored for logged-in users.
	 */
	public function test_render_guest_ignored_when_logged_in() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$out = WP_MCP_AI_Pro_SPA_Shortcode::render( array( 'guest' => '1' ) );

		// The authenticated surface has no guestToken in its config.
		$this->assertStringNotContainsString( 'guestToken', $out );
	}
}
