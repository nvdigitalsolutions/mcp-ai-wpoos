<?php
/**
 * Image Production Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image Production MCP server.
 */
class WP_MCP_AI_Image_Production_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'image-production';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Image Production', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'AI-powered image generation, enhancement, and optimization. Owns the Image Template research surface.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-image-template',
				'entity_type'        => 'mcp_ai_image_tpl',
				'class_ref'          => 'WP_MCP_AI_Image_Template_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Image Templates', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Image Production MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_image_production_candidate_tools',
			array(
				'generate_image_ai',
				'generate_image_variations',
				'text_to_image_prompt_optimizer',
				'upscale_image_ai',
				'enhance_image_quality',
				'image_inpainting',
				'apply_artistic_style',
				'colorize_image',
				'remove_image_background',
				'resize_image_smart',
				'compress_image',
				'convert_image_format',
				'generate_responsive_images',
				'optimize_for_web',
				'batch_process_images',
				'optimize_image_sharp',
			)
		);
	}
}
