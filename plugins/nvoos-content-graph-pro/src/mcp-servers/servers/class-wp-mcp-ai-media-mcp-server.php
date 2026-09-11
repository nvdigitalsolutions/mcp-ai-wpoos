<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-media-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


// phpcs:ignore WordPress.Files.FileName -- Class name does not match filename; file included explicitly.


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Toolkit MCP server.
 */
class WP_MCP_AI_Media_Toolkit_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'media';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Media Toolkit', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cross-medium asset management, capture, collections, and template-driven media production.',
			'nvoos-content-graph-pro'
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
				'type'               => 'consolidate_add',
				'page_slug'          => 'design-media',
				'entity_type'        => 'attachment',
				'class_ref'          => 'WP_MCP_AI_Media_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate & Design Media', 'nvoos-content-graph-pro' ),
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
		 * Filter the candidate tool slugs the Media Toolkit MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_media_candidate_tools',
			array(
				'create_media_collection',
				'process_collection',
				'apply_collection_template',
				'create_media_template',
				'apply_media_template',
				'list_media_templates',
				'capture_webpage_screenshot',
			)
		);
	}
}
