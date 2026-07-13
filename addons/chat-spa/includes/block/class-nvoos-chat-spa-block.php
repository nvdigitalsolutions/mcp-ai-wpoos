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

		// Register the editor script with explicit dependencies so the
		// block appears in the Gutenberg inserter with a proper preview.
		$editor_script_handle = 'nvoos-chat-spa-block-editor';
		$editor_script_url    = NVOOS_CHAT_SPA_URL . 'assets/js/block-editor.js';
		wp_register_script(
			$editor_script_handle,
			$editor_script_url,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			NVOOS_CHAT_SPA_VERSION,
			true
		);

		$block_metadata = wp_json_file_decode( __DIR__ . '/block.json', array( 'associative' => true ) );
		if ( is_array( $block_metadata ) ) {
			$block_metadata['editorScript'] = $editor_script_handle;
		}

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
			'assistant_id'          => isset( $attributes['assistant_id'] ) ? absint( $attributes['assistant_id'] ) : 0,
			'theme'                 => isset( $attributes['theme'] ) ? sanitize_text_field( $attributes['theme'] ) : 'auto',
			'height'                => isset( $attributes['height'] ) ? sanitize_text_field( $attributes['height'] ) : '',
			'guest'                 => ! empty( $attributes['guest'] ) ? '1' : '0',
			'allow_sensitive_tools' => ! empty( $attributes['allowSensitiveTools'] ) ? '1' : '0',
		);
		return NV_oOS_Chat_Spa_Shortcode::render( $atts );
	}
}
