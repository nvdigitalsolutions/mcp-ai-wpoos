<?php
/**
 * NV oOS Cloudways Dashboard — Gutenberg Block
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block.
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_Block {

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
	 * Render the block on the frontend.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$atts = array(
			'view'   => isset( $attributes['view'] ) ? sanitize_key( $attributes['view'] ) : '',
			'theme'  => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'auto',
			'height' => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
		);
		return NV_oOS_CloudwaysDashboard_Shortcode::render( $atts );
	}
}
