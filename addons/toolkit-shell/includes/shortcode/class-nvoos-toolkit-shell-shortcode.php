<?php
/**
 * NV oOS Toolkit Shell — Shortcode
 *
 * Registers `[nvoos_toolkit_app]` and renders the SPA root container.
 *
 * @package NV_oOS_Toolkit_Shell
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
class NV_oOS_Toolkit_Shell_Shortcode {

	const SHORTCODE = 'nvoos_toolkit_app';

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
				'toolkit' => '',
				'theme'   => 'auto',
				'view'    => '',
				'height'  => '',
			),
			$atts,
			self::SHORTCODE
		);

		/**
		 * Filter whether the shortcode should render at all.
		 *
		 * @param bool  $can_render Default true.
		 * @param array $atts       Shortcode attributes.
		 */
		$can_render = apply_filters( 'nvoos_toolkit_shell_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$config = array(
			'toolkit' => sanitize_key( $atts['toolkit'] ),
			'theme'   => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'view'    => sanitize_key( $atts['view'] ),
			'height'  => sanitize_text_field( $atts['height'] ),
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-toolkit-shell-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'NV oOS Toolkit Shell', 'nvoos-toolkit-shell' ) ),
			esc_attr( $config_json )
		);
	}

	/**
	 * Enqueue the SPA bundle and localize bootstrap data.
	 *
	 * @param array $config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( $config ) {
		wp_register_style(
			'nvoos-toolkit-shell',
			NVOOS_TOOLKIT_SHELL_URL . 'assets/dist/toolkit-shell.css',
			array(),
			NVOOS_TOOLKIT_SHELL_VERSION
		);
		wp_register_script(
			'nvoos-toolkit-shell',
			NVOOS_TOOLKIT_SHELL_URL . 'assets/dist/toolkit-shell.js',
			array( 'wp-i18n' ),
			NVOOS_TOOLKIT_SHELL_VERSION,
			true
		);
		wp_set_script_translations(
			'nvoos-toolkit-shell',
			'nvoos-toolkit-shell',
			NVOOS_TOOLKIT_SHELL_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-toolkit-shell',
			'NVOOS_TOOLKIT_SHELL',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_Toolkit_Shell_REST::REST_NAMESPACE ) ),
				'proApi' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'baseApi' => esc_url_raw( rest_url( 'mcp-ai/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
			)
		);
		wp_enqueue_style( 'nvoos-toolkit-shell' );
		wp_enqueue_script( 'nvoos-toolkit-shell' );
	}
}
