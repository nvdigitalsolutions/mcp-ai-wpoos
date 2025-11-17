<?php
/**
 * Video File Manager.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-file-resource-manager.php';

/**
 * Manages video file resources with caching and tracking.
 */
class WP_MCP_AI_Video_File_Manager extends WP_MCP_AI_File_Resource_Manager {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->file_type = 'video';
	}
}
