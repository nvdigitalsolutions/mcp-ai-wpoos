<?php
/**
 * NV oOS Cloudways Dashboard — Shortcode
 *
 * Registers `[nvoos_cloudways_dashboard]` and renders the SPA root container.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler.
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_Shortcode {

	const SHORTCODE = 'nvoos_cloudways_dashboard';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'view'   => '',
				'theme'  => 'auto',
				'height' => '',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_cloudways_dashboard_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$config = array(
			'view'   => sanitize_key( $atts['view'] ),
			'theme'  => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'height' => sanitize_text_field( $atts['height'] ),
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-cloudways-dashboard-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'NV oOS Cloudways Dashboard', 'nvoos-cloudways-dashboard' ) ),
			esc_attr( $config_json )
		);
	}

	/**
	 * Enqueue the SPA bundle and localize bootstrap data.
	 *
	 * @param array $config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( $config = array() ) {
		$handle  = 'nvoos-cloudways-dashboard';
		$src     = NVOOS_CLOUDWAYS_DASHBOARD_URL . 'assets/dist/cloudways-dashboard.js';
		$css     = NVOOS_CLOUDWAYS_DASHBOARD_URL . 'assets/dist/cloudways-dashboard.css';
		$js_path = NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'assets/dist/cloudways-dashboard.js';
		$css_path= NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'assets/dist/cloudways-dashboard.css';
		$js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : NVOOS_CLOUDWAYS_DASHBOARD_VERSION;
		$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : NVOOS_CLOUDWAYS_DASHBOARD_VERSION;

		wp_register_style( $handle, $css, array(), $css_ver );
		wp_register_script( $handle, $src, array(), $js_ver, true );

		wp_localize_script(
			$handle,
			'NVOOS_CLOUDWAYS_DASHBOARD',
			array(
				'apiUrl'  => esc_url_raw( rest_url( NV_oOS_CloudwaysDashboard_REST::REST_NAMESPACE ) ),
				'proApi'  => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'baseApi' => esc_url_raw( rest_url( 'mcp-ai/v1' ) ),
				'tkApi'   => esc_url_raw( rest_url( 'nvoos-toolkit-shell/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'config'  => $config,
				'locale'  => get_locale(),
			)
		);

		wp_enqueue_style( $handle );
		wp_enqueue_script( $handle );
	}
}
