<?php
/**
 * Fashion Studio — Model swap tool (Pro, Phase 5).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Fashion_Transform_Tool' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-fashion-transform-tool.php';
}

/**
 * Swap the model in a garment photo for a managed identity model.
 */
class WP_MCP_AI_Tool_Fashion_Model_Swap extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_model_swap';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'model-swap';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion Model Swap', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Replaces the model in a garment photo with a managed identity model while preserving the garment, lighting, and composition.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
			'identity_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Fashion model identity post ID (the managed identity library).', 'mcp-ai-wpoos-pro' ),
			),
			'aspect_ratio' => array(
				'type'        => 'string',
				'enum'        => array( 'auto', 'square', 'portrait', 'landscape', 'story', 'widescreen' ),
				'default'     => 'auto',
				'description' => __( 'Output aspect ratio.', 'mcp-ai-wpoos-pro' ),
			),
			'mime_type'    => array(
				'type'        => 'string',
				'enum'        => array( 'png', 'jpeg', 'webp' ),
				'default'     => 'png',
				'description' => __( 'Output file format.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_sanitizers() {
		return array(
			'identity_id'  => 'absint',
			'aspect_ratio' => 'sanitize_key',
			'mime_type'    => 'sanitize_key',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_usage_notes() {
		return __( 'The identity must exist in the fashion model library with granted consent; otherwise the base consent gate rejects the run.', 'mcp-ai-wpoos-pro' );
	}
}
