<?php
/**
 * Tests for the Docs Hub shortcode.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

/**
 * Docs Hub shortcode tests.
 */
class Test_Docs_Hub_Shortcode extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '1.0.0' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/shortcode/class-nvoos-docs-hub-shortcode.php';
	}

	/**
	 * Test that the shortcode returns a container div.
	 *
	 * @return void
	 */
	public function test_shortcode_returns_container_div() {
		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array() );

		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );
		$this->assertStringContainsString( '<div', $output );
	}

	/**
	 * Test that the shortcode outputs a data-config attribute.
	 *
	 * @return void
	 */
	public function test_shortcode_has_data_config() {
		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array( 'section' => 'base' ) );

		$this->assertStringContainsString( 'data-config', $output );
	}

	/**
	 * Test that the shortcode accepts the theme attribute.
	 *
	 * @return void
	 */
	public function test_shortcode_theme_attribute() {
		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array( 'theme' => 'dark' ) );

		$this->assertStringContainsString( 'dark', $output );
	}

	/**
	 * Test that the shortcode ignores unknown attributes.
	 *
	 * Unknown attributes should not produce any PHP notices or errors.
	 *
	 * @return void
	 */
	public function test_shortcode_ignores_unknown_attrs() {
		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array( 'unknown_attr' => 'value', 'another' => 'test' ) );

		// Should still produce valid output.
		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );
		// Unknown attributes should not appear in the data-config.
		$this->assertStringNotContainsString( 'unknown_attr', $output );
	}

	/**
	 * Test shortcode registration.
	 *
	 * @return void
	 */
	public function test_shortcode_is_registered() {
		NV_oOS_Docs_Hub_Shortcode::init();
		$this->assertTrue( shortcode_exists( 'nvoos_docs' ) );
	}

	/**
	 * Test that the shortcode renders the full widget for a logged-out (guest) visitor.
	 *
	 * Guest / public-facing usage is the primary supported mode for the Docs Hub
	 * shortcode. Logged-out users must receive the mount div so the React SPA can
	 * bootstrap and fetch documentation via the public REST endpoints.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_for_guest_user() {
		// Ensure user is logged out.
		wp_set_current_user( 0 );

		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array() );

		// The mount div must be present for the SPA to bootstrap.
		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );
		$this->assertStringContainsString( 'data-config', $output );
	}

	/**
	 * Test that the shortcode is safe to call when the addon is disabled.
	 *
	 * When `nvoos_docs_hub_can_render` filter returns false, the shortcode
	 * should return an empty string or a fallback, not a broken widget.
	 *
	 * @return void
	 */
	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_docs_hub_can_render', '__return_false' );

		$shortcode = new NV_oOS_Docs_Hub_Shortcode();
		$output    = $shortcode->render( array() );

		// Output should be empty or a comment, not a full widget.
		$this->assertStringNotContainsString( 'nvoos-docs-hub-root', $output );

		remove_filter( 'nvoos_docs_hub_can_render', '__return_false' );
	}
}
