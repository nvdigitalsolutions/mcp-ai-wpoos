<?php
/**
 * NV oOS Chat SPA — Gutenberg Block
 *
 * @package NV_oOS_Chat_Spa
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
class NV_oOS_Chat_Spa_Block {

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
	 * Render the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$atts = array(
			'toolkit' => isset( $attributes['toolkit'] ) ? sanitize_key( $attributes['toolkit'] ) : '',
			'theme'   => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'auto',
			'view'    => isset( $attributes['view'] ) ? sanitize_key( $attributes['view'] ) : '',
			'height'  => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
		);
		return NV_oOS_Chat_Spa_Shortcode::render( $atts );
	}
}
