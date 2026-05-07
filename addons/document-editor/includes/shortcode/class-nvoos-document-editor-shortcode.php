<?php
/**
 * NV oOS Document Editor — Shortcode
 *
 * @package NV_oOS_Document_Editor
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
class NV_oOS_Document_Editor_Shortcode {

	const SHORTCODE = 'nvoos_document_editor_app';

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
				'toolkit'     => '',
				'theme'       => 'auto',
				'view'        => '',
				'height'      => '',
				'mode'        => 'editor',
				'document_id' => 0,
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_document_editor_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$allowed_modes = array( 'editor', 'site-creator' );
		$mode          = sanitize_key( $atts['mode'] );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'editor';
		}

		$config = array(
			'toolkit'     => sanitize_key( $atts['toolkit'] ),
			'theme'       => in_array( $atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? $atts['theme'] : 'auto',
			'view'        => sanitize_key( $atts['view'] ),
			'height'      => sanitize_text_field( $atts['height'] ),
			'mode'        => $mode,
			'document_id' => absint( $atts['document_id'] ),
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-document-editor-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'NV oOS Document Editor', 'nvoos-document-editor' ) ),
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
		wp_register_style(
			'nvoos-document-editor',
			NVOOS_DOCUMENT_EDITOR_URL . 'assets/dist/document-editor.css',
			array(),
			NVOOS_DOCUMENT_EDITOR_VERSION
		);
		wp_register_script(
			'nvoos-document-editor',
			NVOOS_DOCUMENT_EDITOR_URL . 'assets/dist/document-editor.js',
			array( 'wp-i18n' ),
			NVOOS_DOCUMENT_EDITOR_VERSION,
			true
		);
		wp_set_script_translations(
			'nvoos-document-editor',
			'nvoos-document-editor',
			NVOOS_DOCUMENT_EDITOR_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-document-editor',
			'NVOOS_DOCUMENT_EDITOR',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_Document_Editor_REST::REST_NAMESPACE ) ),
				'proApi' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
			)
		);
		wp_enqueue_style( 'nvoos-document-editor' );
		wp_enqueue_script( 'nvoos-document-editor' );
	}
}
