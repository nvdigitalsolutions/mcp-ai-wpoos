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
		$atts = shortcode_atts(
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

		self::enqueue_assets();

		$config = array(
			'section' => $section,
			'theme'   => $theme,
			'search'  => $search,
			'sidebar' => $sidebar,
			'home'    => $home,
		);

		wp_localize_script(
			'nvoos-docs-hub',
			'NVOOS_DOCS_HUB',
			array(
				'apiUrl' => esc_url_raw( rest_url( 'nvoos-docs/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
			)
		);

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div id="nvoos-docs-hub-root" class="nvoos-docs-hub-root" data-config="%s"></div>',
			esc_attr( $config_json )
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
