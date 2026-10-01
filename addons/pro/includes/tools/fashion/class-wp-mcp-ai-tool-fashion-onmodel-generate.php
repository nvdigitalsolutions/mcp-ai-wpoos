<?php
/**
 * Fashion Studio — On-model generation tool (Pro, Phase 5).
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
 * Place a flat-lay or ghost-mannequin garment on an AI model.
 */
class WP_MCP_AI_Tool_Fashion_Onmodel_Generate extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_onmodel_generate';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'on-model';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion On-Model Generate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Renders a product photo of a person wearing the garment from the given source image, preserving garment colors, fabric, and fit.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
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
			'aspect_ratio' => 'sanitize_key',
			'mime_type'    => 'sanitize_key',
		);
	}
}
