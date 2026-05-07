<?php
/**
 * Toolkit Shell — Shortcode tests.
 *
 * @package NV_oOS_Toolkit_Shell
 */

/**
 * Tests for NV_oOS_Toolkit_Shell_Shortcode.
 */
class Test_Toolkit_Shell_Shortcode extends WP_UnitTestCase {

	/**
	 * Bootstrap addon constants and require its classes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_VERSION' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_VERSION', '0.2.0' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_PATH' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_URL' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_URL', 'http://example.com/wp-content/plugins/nvoos-toolkit-shell/' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_FILE' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_FILE', NVOOS_TOOLKIT_SHELL_PATH . 'nvoos-toolkit-shell.php' );
		}
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/rest/class-nvoos-toolkit-shell-rest.php';
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/shortcode/class-nvoos-toolkit-shell-shortcode.php';
	}

	/**
	 * Shortcode emits the SPA root container.
	 *
	 * @return void
	 */
	public function test_shortcode_returns_root_container() {
		$out = NV_oOS_Toolkit_Shell_Shortcode::render( array() );
		$this->assertStringContainsString( 'nvoos-toolkit-shell-root', $out );
		$this->assertStringContainsString( 'data-config', $out );
	}

	/**
	 * Toolkit attribute is propagated into data-config (sanitized).
	 *
	 * @return void
	 */
	public function test_shortcode_propagates_toolkit_attribute() {
		$out = NV_oOS_Toolkit_Shell_Shortcode::render( array( 'toolkit' => 'crm' ) );
		$this->assertStringContainsString( '&quot;toolkit&quot;:&quot;crm&quot;', $out );
	}

	/**
	 * Toolkit attribute is sanitized to a slug (XSS payload becomes empty).
	 *
	 * @return void
	 */
	public function test_shortcode_sanitizes_toolkit_attribute() {
		$out = NV_oOS_Toolkit_Shell_Shortcode::render( array( 'toolkit' => '<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringNotContainsString( 'alert(1)', $out );
	}

	/**
	 * Theme attribute outside the allowed list falls back to "auto".
	 *
	 * @return void
	 */
	public function test_shortcode_clamps_theme() {
		$out = NV_oOS_Toolkit_Shell_Shortcode::render( array( 'theme' => 'rainbow' ) );
		$this->assertStringContainsString( '&quot;theme&quot;:&quot;auto&quot;', $out );
	}

	/**
	 * `nvoos_toolkit_shell_can_render` filter suppresses output when false.
	 *
	 * @return void
	 */
	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_toolkit_shell_can_render', '__return_false' );
		$out = NV_oOS_Toolkit_Shell_Shortcode::render( array( 'toolkit' => 'crm' ) );
		$this->assertSame( '', $out );
		remove_filter( 'nvoos_toolkit_shell_can_render', '__return_false' );
	}

	/**
	 * Shortcode is registered when the plugin's `init` hook fires.
	 *
	 * @return void
	 */
	public function test_shortcode_is_registered() {
		NV_oOS_Toolkit_Shell_Shortcode::register();
		$this->assertTrue( shortcode_exists( 'nvoos_toolkit_app' ) );
	}
}
