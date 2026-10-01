<?php
/**
 * Fashion Studio — Background generation tool (Pro, Phase 5).
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
 * Replace the background of a garment photo with a styled scene.
 */
class WP_MCP_AI_Tool_Fashion_Background_Generate extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_background_generate';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'background';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion Background Generate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Replaces the background of a product photo with a styled fashion scene while keeping the garment and subject untouched.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
			'background_style' => array(
				'type'        => 'string',
				'enum'        => array( 'studio', 'lifestyle', 'gradient', 'editorial' ),
				'default'     => 'studio',
				'description' => __( 'Background style to render.', 'mcp-ai-wpoos-pro' ),
			),
			'aspect_ratio'     => array(
				'type'        => 'string',
				'enum'        => array( 'auto', 'square', 'portrait', 'landscape', 'story', 'widescreen' ),
				'default'     => 'auto',
				'description' => __( 'Output aspect ratio.', 'mcp-ai-wpoos-pro' ),
			),
			'mime_type'        => array(
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
			'background_style' => 'sanitize_key',
			'aspect_ratio'     => 'sanitize_key',
			'mime_type'        => 'sanitize_key',
		);
	}
}
