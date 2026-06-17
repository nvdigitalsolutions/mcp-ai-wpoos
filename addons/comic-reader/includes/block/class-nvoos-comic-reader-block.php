<?php
/**
 * NV oOS Comic Reader — Gutenberg Block
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block registration for the comic reader.
 *
 * @since 0.1.0
 */
class NV_oOS_Comic_Reader_Block {

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			__DIR__ . '/block.json',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Render the block — delegates to the shortcode.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public static function render( $attributes ) {
		$atts = array(
			'id'        => isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0,
			'mode'      => isset( $attributes['mode'] ) ? sanitize_text_field( $attributes['mode'] ) : 'library',
			'height'    => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
			'direction' => isset( $attributes['direction'] ) ? sanitize_text_field( $attributes['direction'] ) : 'ltr',
		);
		return NV_oOS_Comic_Reader_Shortcode::render( $atts );
	}
}
