<?php
/**
 * NV oOS Docs Hub — Gutenberg Block
 *
 * Registers the nvoos/docs-hub block which renders the documentation
 * SPA via the shortcode render function.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block handler for the Docs Hub.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Block {

	/**
	 * Register the Gutenberg block.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			__DIR__ . '/block.json',
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Render the block on the frontend.
	 *
	 * @since 1.0.0
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$atts = array(
			'section' => isset( $attributes['section'] ) ? sanitize_text_field( $attributes['section'] ) : 'all',
			'theme'   => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'auto',
			'search'  => isset( $attributes['search'] ) ? sanitize_text_field( $attributes['search'] ) : 'true',
			'sidebar' => isset( $attributes['sidebar'] ) ? sanitize_text_field( $attributes['sidebar'] ) : 'true',
			'home'    => isset( $attributes['home'] ) ? sanitize_text_field( $attributes['home'] ) : '',
		);

		return NV_oOS_Docs_Hub_Shortcode::render( $atts );
	}
}
