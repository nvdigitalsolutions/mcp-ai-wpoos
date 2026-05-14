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
		$output    = $shortcode->render(
			array(
				'unknown_attr' => 'value',
				'another'      => 'test',
			)
		);

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

	/**
	 * Test that the shortcode embeds api_url in the data-config attribute.
	 *
	 * The React bundle reads api_url from data-config as a fallback when
	 * wp_localize_script is unavailable (e.g. async script loading).
	 *
	 * @return void
	 */
	public function test_shortcode_data_config_contains_api_url() {
		$output = NV_oOS_Docs_Hub_Shortcode::render( array() );

		// Extract the data-config JSON from the output.
		preg_match( '/data-config="([^"]+)"/', $output, $matches );
		$this->assertNotEmpty( $matches[1], 'data-config attribute should be present' );

		$config = json_decode( html_entity_decode( $matches[1] ), true );
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'api_url', $config, 'data-config must include api_url key' );
		$this->assertNotEmpty( $config['api_url'], 'api_url must not be empty' );
	}

	/**
	 * Test that the shortcode renders the mount div for a guest when public
	 * access is enabled (the default).
	 *
	 * @return void
	 */
	public function test_shortcode_renders_for_guest_when_public_access_enabled() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Ensure public_access is on (default).
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => true ) )
		);

		$output = NV_oOS_Docs_Hub_Shortcode::render( array() );

		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that the shortcode still renders the mount div when public access
	 * is disabled — the React SPA itself will receive a 401 from the REST API
	 * and can display a login prompt. The shortcode must not suppress the
	 * mount div because that would break the SPA's error-state rendering.
	 *
	 * @return void
	 */
	public function test_shortcode_still_renders_mount_div_when_public_access_disabled() {
		wp_set_current_user( 0 );

		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array_merge( NV_oOS_Docs_Hub_Plugin::get_settings(), array( 'public_access' => false ) )
		);

		$output = NV_oOS_Docs_Hub_Shortcode::render( array() );

		// The mount div must still be present so the SPA can render its
		// "login required" error state.
		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );

		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Test that the shortcode does not enqueue assets when can_render is false.
	 *
	 * Ensures no JS/CSS is loaded for suppressed shortcodes.
	 *
	 * @return void
	 */
	public function test_no_assets_enqueued_when_can_render_false() {
		add_filter( 'nvoos_docs_hub_can_render', '__return_false' );

		NV_oOS_Docs_Hub_Shortcode::render( array() );

		$this->assertFalse(
			wp_script_is( 'nvoos-docs-hub', 'enqueued' ),
			'Script must not be enqueued when can_render filter returns false'
		);

		remove_filter( 'nvoos_docs_hub_can_render', '__return_false' );
	}
}
