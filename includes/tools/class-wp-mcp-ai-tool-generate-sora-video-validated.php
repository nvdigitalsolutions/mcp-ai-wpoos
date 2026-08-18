<?php
/**
 * Validated wrapper for Sora video generation tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';

/**
 * Validated wrapper for generate_sora_video tool.
 *
 * Mirrors the lib/core variant: a plain subclass of the base tool with its
 * own slug/name. Symfony Validator arguments can be layered on later via
 * the WP_MCP_AI_Validated_Tool pattern once a Sora arguments class exists.
 */
class WP_MCP_AI_Tool_Generate_Sora_Video_Validated extends WP_MCP_AI_Tool_Generate_Sora_Video {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_sora_video_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Sora Video (Validated)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'video_producer', 'content_creator' ),
			'risk_level'            => 'standard',
		);
	}
}
