<?php
/**
 * Shortcode tests.
 *
 * @package NV_oOS_Canvas_Toolkit
 */
class Test_Canvas_Toolkit_Shortcode extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CANVAS_TOOLKIT_VERSION' ) ) {
			define( 'NVOOS_CANVAS_TOOLKIT_VERSION', '0.1.0' );
		}
		if ( ! defined( 'NVOOS_CANVAS_TOOLKIT_PATH' ) ) {
			define( 'NVOOS_CANVAS_TOOLKIT_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_CANVAS_TOOLKIT_URL' ) ) {
			define( 'NVOOS_CANVAS_TOOLKIT_URL', 'http://example.com/wp-content/plugins/nvoos-canvas-toolkit/' );
		}
		require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/rest/class-nvoos-canvas-toolkit-rest.php';
		require_once NVOOS_CANVAS_TOOLKIT_PATH . 'includes/shortcode/class-nvoos-canvas-toolkit-shortcode.php';
	}

	/**
	 * Test that shortcode returns root container div.
	 */
	public function test_shortcode_returns_root_container() {
		$out = NV_oOS_Canvas_Toolkit_Shortcode::render( array() );
		$this->assertStringContainsString( 'nvoos-canvas-toolkit-root', $out );
		$this->assertStringContainsString( 'data-config', $out );
		// Default mode is "flow".
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;flow&quot;', $out );
	}

	/**
	 * Test that shortcode accepts a known mode.
	 */
	public function test_shortcode_accepts_known_mode() {
		$out = NV_oOS_Canvas_Toolkit_Shortcode::render( array( 'mode' => 'whiteboard' ) );
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;whiteboard&quot;', $out );
	}

	/**
	 * Test that shortcode falls back to default mode for unknown mode.
	 */
	public function test_shortcode_falls_back_for_unknown_mode() {
		$out = NV_oOS_Canvas_Toolkit_Shortcode::render( array( 'mode' => 'evil-mode' ) );
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;flow&quot;', $out );
	}

	/**
	 * Test that shortcode respects the can_render filter.
	 */
	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_canvas_toolkit_can_render', '__return_false' );
		$out = NV_oOS_Canvas_Toolkit_Shortcode::render( array() );
		$this->assertSame( '', $out );
		remove_filter( 'nvoos_canvas_toolkit_can_render', '__return_false' );
	}

	/**
	 * Test that shortcode is registered.
	 */
	public function test_shortcode_is_registered() {
		NV_oOS_Canvas_Toolkit_Shortcode::register();
		$this->assertTrue( shortcode_exists( 'nvoos_canvas_toolkit_app' ) );
		remove_shortcode( 'nvoos_canvas_toolkit_app' );
	}
}
