<?php
/**
 * Validated wrapper for Sora video generation tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-sora-video.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-validated-wrapper.php';

/**
 * Validated wrapper for generate_sora_video tool.
 */
class WP_MCP_AI_Tool_Generate_Sora_Video_Validated extends WP_MCP_AI_Tool_Generate_Sora_Video {
	use WP_MCP_AI_Tool_Validated_Wrapper;

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
		return __( 'Generate Sora Video (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * Get the validator class name.
	 *
	 * @return string|null Validator class name or null if no validator.
	 */
	protected function get_validator_class() {
		// Return null for now - validator can be added later if needed.
		return null;
	}

	/**
	 * Get the base tool instance.
	 *
	 * @return WP_MCP_AI_Tool_Interface
	 */
	protected function get_base_tool() {
		return new WP_MCP_AI_Tool_Generate_Sora_Video();
	}
}
