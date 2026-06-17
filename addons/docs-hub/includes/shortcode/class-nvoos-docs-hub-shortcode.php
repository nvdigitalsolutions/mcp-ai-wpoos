<?php
/**
 * NV oOS Docs Hub — Shortcode
 *
 * Registers and renders the [nvoos_docs] shortcode, enqueueing
 * the React SPA assets and localizing configuration data.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler for the Docs Hub SPA.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Shortcode {

	/**
	 * Register the shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'nvoos_docs', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the [nvoos_docs] shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( $atts ) {
		// Multiple shortcode / block instances per page must not re-localize
		// identical data — track which instance we're on so the static guards
		// in enqueue_assets() / localize_once() can no-op cleanly. The counter
		// is only incremented once we're actually about to emit a mount div
		// (just before the sprintf below) so any early return added in the
		// future does not skew instance numbering.
		static $instance_count = 0;
		$atts                  = shortcode_atts(
			array(
				'section' => 'all',
				'theme'   => 'auto',
				'search'  => 'true',
				'sidebar' => 'true',
				'home'    => '',
			),
			$atts,
			'nvoos_docs'
		);

		// Sanitize all attributes.
		$section = sanitize_text_field( $atts['section'] );
		$theme   = sanitize_text_field( $atts['theme'] );
		$search  = 'false' !== strtolower( sanitize_text_field( $atts['search'] ) ) ? 'true' : 'false';
		$sidebar = 'false' !== strtolower( sanitize_text_field( $atts['sidebar'] ) ) ? 'true' : 'false';
		$home    = sanitize_text_field( $atts['home'] );

		// Validate theme value.
		if ( ! in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ) {
			$theme = 'auto';
		}

		// Use plugin settings as fallback for home page.
		if ( '' === $home ) {
			$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
			$home     = sanitize_text_field( $settings['default_home'] );
		}

		/**
		 * Filter whether the shortcode should render at all.
		 *
		 * Return false to suppress output entirely (e.g. when the addon is
		 * disabled or the current user lacks access).
		 *
		 * @since 0.4.0
		 *
		 * @param bool $can_render Whether to render the shortcode.
		 */
		if ( ! apply_filters( 'nvoos_docs_hub_can_render', true ) ) {
			return '';
		}

		// When an admin views a page with the shortcode while public access is
		// disabled, surface a dismissible notice so they know guests will be
		// blocked. The notice is injected into the page via an admin_notices
		// hook rather than inline so it renders in the standard WP notice area.
		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		if ( is_admin() && current_user_can( 'manage_options' ) && empty( $settings['public_access'] ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					printf(
						/* translators: %s: settings page link */
						esc_html__( 'Docs Hub: Public (guest) access is currently disabled. Visitors who are not logged in will see a login prompt instead of the documentation browser. %s', 'nvoos-docs-hub' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=nvoos-docs-hub' ) ) . '">' . esc_html__( 'Update settings', 'nvoos-docs-hub' ) . '</a>'
					);
					echo '</p></div>';
				}
			);
		}

		self::enqueue_assets();

		$config = array(
			'section' => $section,
			'theme'   => $theme,
			'search'  => $search,
			'sidebar' => $sidebar,
			'home'    => $home,
			// Include the REST base URL in the per-instance data attribute so the
			// React bundle can bootstrap without relying solely on the
			// wp_localize_script global (which may be absent when the script is
			// loaded async or the global is overwritten by another instance).
			'api_url' => esc_url_raw( rest_url( 'nvoos-docs/v1' ) ),
		);

		// Localize once per page-load. wp_localize_script is idempotent in
		// principle, but emitting the same script tag multiple times bloats
		// the page and means later instances overwrite the earlier global.
		self::localize_once( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		// A11y: the root mount point declares its role + label up-front so
		// screen readers and a11y test runners (axe, Lighthouse) can describe
		// the region even before React hydrates. The `dir` class lets RTL
		// locales mirror the layout via CSS without relying on `<html dir>`.
		$root_classes = 'nvoos-docs-hub-root';
		if ( function_exists( 'is_rtl' ) && is_rtl() ) {
			$root_classes .= ' nvoos-docs-hub-rtl';
		}

		// A unique id per instance so multiple mounts on the same page keep
		// their `aria-labelledby` / skip-link targets distinct. Increment is
		// deferred to here so any early return above leaves the counter alone.
		++$instance_count;
		$instance_id = 'nvoos-docs-hub-root-' . (int) $instance_count;

		return sprintf(
			'<div id="%1$s" class="%2$s" role="application" aria-label="%3$s" data-config="%4$s"></div>',
			esc_attr( $instance_id ),
			esc_attr( $root_classes ),
			esc_attr__( 'Documentation browser', 'nvoos-docs-hub' ),
			esc_attr( $config_json )
		);
	}

	/**
	 * Localize the runtime config exactly once per page-load.
	 *
	 * @since 0.3.7
	 *
	 * @param array $config Per-instance shortcode config (theme/section/etc.).
	 * @return void
	 */
	protected static function localize_once( $config ) {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_localize_script(
			'nvoos-docs-hub',
			'NVOOS_DOCS_HUB',
			array(
				'apiUrl'        => esc_url_raw( rest_url( 'nvoos-docs/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'config'        => $config,
				'isRtl'         => function_exists( 'is_rtl' ) ? is_rtl() : false,
				'githubRepoUrl' => esc_url_raw( NV_oOS_Docs_Hub_Plugin::get_settings()['github_repo_url'] ?? '' ),
			)
		);
	}

	/**
	 * Enqueue the SPA JavaScript and CSS bundles.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		static $registered = false;
		if ( $registered ) {
			// wp_enqueue_* are idempotent, but skipping the second pair of
			// register calls saves a few microseconds and silences potential
			// "already registered" notices on hosts that flip them on.
			wp_enqueue_style( 'nvoos-docs-hub' );
			wp_enqueue_script( 'nvoos-docs-hub' );
			return;
		}
		$registered = true;

		wp_register_style(
			'nvoos-docs-hub',
			NVOOS_DOCS_HUB_URL . 'assets/dist/docs-hub.css',
			array(),
			NVOOS_DOCS_HUB_VERSION
		);

		wp_register_script(
			'nvoos-docs-hub',
			NVOOS_DOCS_HUB_URL . 'assets/dist/docs-hub.js',
			array(),
			NVOOS_DOCS_HUB_VERSION,
			true
		);

		wp_enqueue_style( 'nvoos-docs-hub' );
		wp_enqueue_script( 'nvoos-docs-hub' );
	}
}
