<?php
/**
 * Fashion Studio — Recolor tool (Pro, Phase 5).
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
 * Recolor a garment to a target color while keeping fabric texture.
 */
class WP_MCP_AI_Tool_Fashion_Recolor extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_recolor';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'recolor';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion Recolor', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Recolors the garment in a product photo to a target color, preserving fabric texture, lighting, and composition.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
			'color'        => array(
				'type'        => 'string',
				'pattern'     => '^#[0-9a-fA-F]{6}$',
				'description' => __( 'Target garment color as a hex value, e.g. #c0392b.', 'mcp-ai-wpoos-pro' ),
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
			'color'        => 'sanitize_hex_color',
			'aspect_ratio' => 'sanitize_key',
			'mime_type'    => 'sanitize_key',
		);
	}
}
