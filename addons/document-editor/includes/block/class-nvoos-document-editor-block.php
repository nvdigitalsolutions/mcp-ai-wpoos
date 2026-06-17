<?php
/**
 * NV oOS Document Editor — Gutenberg Block
 *
 * @package NV_oOS_Document_Editor
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
class NV_oOS_Document_Editor_Block {

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
			'toolkit'     => isset( $attributes['toolkit'] ) ? sanitize_key( $attributes['toolkit'] ) : '',
			'theme'       => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'auto',
			'view'        => isset( $attributes['view'] ) ? sanitize_key( $attributes['view'] ) : '',
			'height'      => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
			'mode'        => isset( $attributes['mode'] ) ? sanitize_key( $attributes['mode'] ) : 'editor',
			'document_id' => isset( $attributes['document_id'] ) ? absint( $attributes['document_id'] ) : 0,
		);
		return NV_oOS_Document_Editor_Shortcode::render( $atts );
	}
}
