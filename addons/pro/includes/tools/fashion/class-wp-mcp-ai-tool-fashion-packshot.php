<?php
/**
 * Fashion Studio — Packshot tool (Pro, Phase 5).
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
 * Convert a lifestyle photo into a marketplace-ready packshot.
 */
class WP_MCP_AI_Tool_Fashion_Packshot extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_packshot';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'packshot';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion Packshot', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Converts a product photo into a clean e-commerce packshot: garment centered, ghost-mannequin or flat presentation, pure white background, evenly lit.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
			'aspect_ratio' => array(
				'type'        => 'string',
				'enum'        => array( 'auto', 'square', 'portrait', 'landscape', 'story', 'widescreen' ),
				'default'     => 'square',
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

	/**
	 * {@inheritdoc}
	 */
	protected function get_usage_notes() {
		return __( 'Packshot outputs are validated against a white-background check in the envelope; run the marketplace pipeline for store-ready dimensions.', 'mcp-ai-wpoos-pro' );
	}
}
