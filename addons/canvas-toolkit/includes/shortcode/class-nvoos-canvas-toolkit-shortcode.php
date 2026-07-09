<?php
/**
 * NV oOS Canvas Toolkit — Shortcode
 *
 * @package NV_oOS_Canvas_Toolkit
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
class NV_oOS_Canvas_Toolkit_Shortcode {

	const SHORTCODE = 'nvoos_canvas_toolkit_app';

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
	 * @return string HTML output.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'toolkit' => '',
				'theme'   => 'auto',
				'view'    => '',
				'height'  => '',
				'mode'    => 'flow',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_canvas_toolkit_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$allowed_modes = array( 'flow', 'whiteboard', 'bpmn', 'mermaid' );
		$mode          = sanitize_key( $atts['mode'] );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'flow';
		}

		$config = array(
			'toolkit' => sanitize_key( $atts['toolkit'] ),
			'theme'   => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'view'    => sanitize_key( $atts['view'] ),
			'height'  => sanitize_text_field( $atts['height'] ),
			'mode'    => $mode,
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-canvas-toolkit-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'NV oOS Canvas Toolkit', 'nvoos-canvas-toolkit' ) ),
			esc_attr( $config_json )
		);
	}

	/**
	 * Enqueue the SPA bundle.
	 *
	 * @param array $config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( $config ) {
		$js_path  = NVOOS_CANVAS_TOOLKIT_PATH . 'assets/dist/canvas-toolkit.js';
		$css_path = NVOOS_CANVAS_TOOLKIT_PATH . 'assets/dist/canvas-toolkit.css';
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : NVOOS_CANVAS_TOOLKIT_VERSION;
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : NVOOS_CANVAS_TOOLKIT_VERSION;

		wp_register_style(
			'nvoos-canvas-toolkit',
			NVOOS_CANVAS_TOOLKIT_URL . 'assets/dist/canvas-toolkit.css',
			array(),
			$css_ver
		);
		wp_register_script(
			'nvoos-canvas-toolkit',
			NVOOS_CANVAS_TOOLKIT_URL . 'assets/dist/canvas-toolkit.js',
			array( 'wp-i18n' ),
			$js_ver,
			true
		);
		wp_set_script_translations(
			'nvoos-canvas-toolkit',
			'nvoos-canvas-toolkit',
			NVOOS_CANVAS_TOOLKIT_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-canvas-toolkit',
			'NVOOS_CANVAS_TOOLKIT',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_Canvas_Toolkit_REST::REST_NAMESPACE ) ),
				'proApi' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
			)
		);
		wp_enqueue_style( 'nvoos-canvas-toolkit' );
		wp_enqueue_script( 'nvoos-canvas-toolkit' );
	}
}
