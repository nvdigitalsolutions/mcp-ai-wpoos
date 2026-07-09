<?php
/**
 * NV oOS Media Studio — Shortcode
 *
 * @package NV_oOS_Media_Studio
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
class NV_oOS_Media_Studio_Shortcode {

	const SHORTCODE = 'nvoos_media_studio_app';

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
				'height'  => '',
				'mode'    => 'image-editor',
				'src'     => '',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_media_studio_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$allowed_modes = array( 'image-editor', 'media-player', 'audio-waveform' );
		$mode          = sanitize_key( $atts['mode'] );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'image-editor';
		}

		$config = array(
			'toolkit' => sanitize_key( $atts['toolkit'] ),
			'theme'   => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'height'  => sanitize_text_field( $atts['height'] ),
			'mode'    => $mode,
			'src'     => esc_url_raw( $atts['src'] ),
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-media-studio-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'NV oOS Media Studio', 'nvoos-media-studio' ) ),
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
		$js_path  = NVOOS_MEDIA_STUDIO_PATH . 'assets/dist/media-studio.js';
		$css_path = NVOOS_MEDIA_STUDIO_PATH . 'assets/dist/media-studio.css';
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : NVOOS_MEDIA_STUDIO_VERSION;
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : NVOOS_MEDIA_STUDIO_VERSION;

		wp_register_style(
			'nvoos-media-studio',
			NVOOS_MEDIA_STUDIO_URL . 'assets/dist/media-studio.css',
			array(),
			$css_ver
		);
		wp_register_script(
			'nvoos-media-studio',
			NVOOS_MEDIA_STUDIO_URL . 'assets/dist/media-studio.js',
			array( 'wp-i18n' ),
			$js_ver,
			true
		);
		wp_set_script_translations(
			'nvoos-media-studio',
			'nvoos-media-studio',
			NVOOS_MEDIA_STUDIO_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-media-studio',
			'NVOOS_MEDIA_STUDIO',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_Media_Studio_REST::REST_NAMESPACE ) ),
				'proApi' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
			)
		);
		wp_enqueue_style( 'nvoos-media-studio' );
		wp_enqueue_script( 'nvoos-media-studio' );
	}
}
