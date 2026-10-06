<?php
/**
 * Fashion Studio — Virtual try-on tool (Pro, Phase 5).
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
 * Render a garment on a customer photo (consent-gated face transform).
 */
class WP_MCP_AI_Tool_Fashion_Virtual_Tryon extends WP_MCP_AI_Fashion_Transform_Tool {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fashion_virtual_tryon';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_slug() {
		return 'try-on';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_name() {
		return __( 'Fashion Virtual Try-On', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_transform_description() {
		return __( 'Shows a person in a photo wearing a described garment, preserving the person, pose, and lighting with natural fit and fabric behavior.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_extra_schema_properties() {
		return array(
			'identity_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Optional fashion model identity post ID; required only when the person is a managed identity.', 'mcp-ai-wpoos-pro' ),
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
		return __( 'Face transforms always require the one-time disclosure acknowledgment (acknowledged=true on first run) and carry a visible AI watermark on output.', 'mcp-ai-wpoos-pro' );
	}
}
