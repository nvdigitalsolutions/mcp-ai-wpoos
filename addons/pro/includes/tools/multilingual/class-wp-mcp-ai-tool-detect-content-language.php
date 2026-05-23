<?php
/**
 * Detect Content Language Tool
 *
 * Auto-detect content language using AI-powered language detection algorithms.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Detect_Content_Language tool.
 */
class WP_MCP_AI_Tool_Detect_Content_Language implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_multilingual_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return __( 'Multi-language Content toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Detect Content Language tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'detect_content_language';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Detect Content Language', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Auto-detect content language using AI-powered language detection algorithms.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'text'    => array(
					'type'        => 'string',
					'description' => 'Text to analyze for language detection',
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post ID to detect language',
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'content'     => true,
			'translation' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$text    = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		// Resolve text from post if no direct text provided.
		if ( '' === $text && $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: post ID */
					sprintf( __( 'Post %d not found.', 'mcp-ai-wpoos-pro' ), $post_id )
				);
			}
			$text = wp_strip_all_tags( $post->post_content . ' ' . $post->post_title );
		}

		if ( '' === $text ) {
			return new WP_Error(
				'no_text',
				__( 'Provide text or a post_id to detect language.', 'mcp-ai-wpoos-pro' )
			);
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-language-detection-service.php';
		$service = new WP_MCP_AI_Language_Detection_Service();
		$result  = $service->detect_language( $text );

		return array(
			'success'       => true,
			'language_code' => $result['code'],
			'language_name' => $result['name'],
			'confidence'    => $result['confidence'],
			'alternatives'  => $result['alternatives'],
			'source'        => $result['source'],
			'message'       => sprintf(
				/* translators: 1: language name, 2: confidence percentage */
				__( 'Detected language: %1$s (%.0f%% confidence).', 'mcp-ai-wpoos-pro' ),
				$result['name'],
				$result['confidence'] * 100
			),
		);
	}
}
