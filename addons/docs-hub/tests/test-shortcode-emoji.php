<?php
/**
 * Tests that the shortcode disables WordPress emoji processing.
 *
 * The core emoji loader installs a MutationObserver that replaces emoji
 * text nodes with <img> elements inside the React-managed SPA, which makes
 * React throw "Failed to execute 'removeChild'/'insertBefore' on 'Node'"
 * on the next commit and kills navigation.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.4.2
 */

/**
 * Shortcode emoji-processing tests.
 */
class Test_Docs_Hub_Shortcode_Emoji extends WP_UnitTestCase {

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

		// Re-register the core emoji hooks (wp-phpunit may or may not have
		// loaded default-filters with them intact — register explicitly so
		// the test asserts against a known starting state). Guard each add
		// so duplicate hook entries don't leak into other suites.
		if ( ! has_action( 'wp_head', 'print_emoji_detection_script' ) ) {
			add_action( 'wp_head', 'print_emoji_detection_script', 7 );
		}
		if ( ! has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ) ) {
			add_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' );
		}
		if ( ! has_action( 'wp_print_styles', 'print_emoji_styles' ) ) {
			add_action( 'wp_print_styles', 'print_emoji_styles' );
		}
		if ( ! has_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) ) {
			add_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		}
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Restore the core emoji hooks the production code removed during
		// render(), so later suites see the same state core registered.
		// The footer loader action is dynamic — always remove it.
		if ( ! has_action( 'wp_head', 'print_emoji_detection_script' ) ) {
			add_action( 'wp_head', 'print_emoji_detection_script', 7 );
		}
		if ( ! has_action( 'wp_print_styles', 'print_emoji_styles' ) ) {
			add_action( 'wp_print_styles', 'print_emoji_styles' );
		}
		if ( ! has_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) ) {
			add_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		}
		remove_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' );
	}

	/**
	 * Rendering the shortcode removes the emoji loader footer action.
	 *
	 * @return void
	 */
	public function test_shortcode_render_disables_emoji_loader() {
		$this->assertNotFalse(
			has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'Fixture: the emoji loader footer action must exist before rendering.'
		);

		NV_oOS_Docs_Hub_Shortcode::render( array() );

		$this->assertFalse(
			has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'The emoji loader must be removed so its MutationObserver never touches the SPA DOM.'
		);
	}

	/**
	 * Rendering the shortcode still returns a mount div.
	 *
	 * @return void
	 */
	public function test_shortcode_render_still_outputs_mount_div() {
		$output = NV_oOS_Docs_Hub_Shortcode::render( array() );

		$this->assertStringContainsString( 'nvoos-docs-hub-root', $output );
		$this->assertStringContainsString( 'data-config', $output );
	}
}
