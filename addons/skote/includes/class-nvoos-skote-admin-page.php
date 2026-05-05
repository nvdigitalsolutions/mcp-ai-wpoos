<?php
/**
 * NV oOS Skote — Admin Page
 *
 * Registers the top-level `NV oOS Skote` admin menu and renders the React
 * mount point. The page itself contains zero business logic — it exists
 * solely to host `<div id="nvoos-skote-root"></div>` and let the SPA take
 * over via the HashRouter.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu registration and page renderer.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_Admin_Page {

	/**
	 * Menu / page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-skote';

	/**
	 * Register the top-level menu entry.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_menu() {
		$capability = NV_oOS_Skote::get_admin_capability();

		add_menu_page(
			esc_html__( 'NV oOS Skote', 'nvoos-skote' ),
			esc_html__( 'NV oOS Skote', 'nvoos-skote' ),
			$capability,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-layout',
			59
		);
	}

	/**
	 * Render the React mount point.
	 *
	 * Outputs ONLY escaped chrome plus the empty root div. All UI is delegated
	 * to the React bundle; if the bundle is missing we surface a friendly
	 * "build first" notice rather than a blank screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( NV_oOS_Skote::get_admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nvoos-skote' ) );
		}

		$bundle_exists = file_exists( NVOOS_SKOTE_DIST . 'index.js' );

		echo '<div class="wrap nvoos-skote-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'NV oOS Skote', 'nvoos-skote' ) . '</h1>';

		if ( ! $bundle_exists ) {
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses(
				sprintf(
					/* translators: 1: addon directory path, 2: build command. */
					__( 'The Skote React bundle has not been built yet. From the %1$s directory, run the import script and then build via %2$s.', 'nvoos-skote' ),
					'<code>addons/skote</code>',
					'<code>npm run import:skote -- /path/to/skote-react &amp;&amp; npm run build</code>'
				),
				array( 'code' => array() )
			);
			echo '</p></div>';
		}

		echo '<div id="' . esc_attr( 'nvoos-skote-root' ) . '" class="nvoos-skote-root" data-surface="admin"></div>';
		echo '</div>';
	}
}
