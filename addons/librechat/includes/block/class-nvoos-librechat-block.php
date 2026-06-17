<?php
/**
 * NV oOS LibreChat — Gutenberg Block
 *
 * @package NV_oOS_LibreChat
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
class NV_oOS_LibreChat_Block {

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$editor_script_handle = 'nvoos-librechat-block-editor';
		$editor_script_url    = NVOOS_LIBRECHAT_URL . 'assets/js/block-editor.js';
		wp_register_script(
			$editor_script_handle,
			$editor_script_url,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			NVOOS_LIBRECHAT_VERSION,
			true
		);

		register_block_type(
			__DIR__ . '/block.json',
			array(
				'render_callback' => array( __CLASS__, 'render' ),
				'editor_script'   => $editor_script_handle,
			)
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
			'assistant_id' => isset( $attributes['assistant_id'] ) ? absint( $attributes['assistant_id'] ) : 0,
			'theme'        => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'dark',
			'height'       => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
			'guest'        => ! empty( $attributes['guest'] ) ? '1' : '0',
		);
		return NV_oOS_LibreChat_Shortcode::render( $atts );
	}
}
