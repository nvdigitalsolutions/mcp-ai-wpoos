<?php
/**
 * Tool for AI-powered background removal.
 *
 * Removes backgrounds from images using AI, creating transparent PNGs.
 * Supports both free (rembg) and paid (remove.bg API) methods.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Remove image backgrounds using AI.
 */
class WP_MCP_AI_Tool_Remove_Image_Background extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'remove_image_background';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Remove Image Background', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Remove the background from an image using AI, creating a transparent PNG. Supports both free (rembg) and paid (remove.bg) methods.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'method'     => array(
						'type'        => 'string',
						'description' => __( 'Method: "auto" (tries free first), "free" (rembg), "paid" (remove.bg).', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'auto', 'free', 'paid' ),
						'default'     => 'auto',
					),
					'use_remote' => array(
						'type'        => 'boolean',
						'description' => __( 'Use remote processing for faster results.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'gpu-accelerated',
			'performance-impact',
			'idempotent',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to edit images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Delegate to existing remove_background tool.
		$tool = wp_mcp_ai_get_tool_instance( 'remove_background' );
		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				__( 'Background removal tool not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $tool->execute( $arguments, $context );
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
