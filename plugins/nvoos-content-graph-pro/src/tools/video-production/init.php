<?php
/**
 * Video Production Toolkit Initialization (ecosystem port — Wave F2,
 * video-production toolkit).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/video-production/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*`.
 * 3. Slimmed wiring — the admin settings page and the research-add page
 *    requires are file-gated (they land with the video-production admin
 *    slice; the research-add instantiation is file-gated too).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_video_production_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter carrying the ported
 *    video-production tool subset (the base main file registers these
 *    inline behind the `enable_video_production_toolkit` gate) plus
	 * `wp_mcp_ai_pro_register_video_production_ecosystem_tools()` registering
	 * them into the ecosystem graph ToolRegistry and the nvoos/core registry
	 * via `WP_MCP_AI_Pro_Tool_Adapter`. The tree-only import-blueprint tool
	 * (the base registers it nowhere) is carried here too, as are the four
	 * always-on exec-service tools (transcode/extract-frames/get-metadata/
	 * create-remotion — the base registers them in the unconditional
	 * `$pro_tools` map; they load the D8-compat interface/trait copies and
	 * the namespaced Process-service copy through per-file seams).
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Monolith guard (deviation 4): full-body runtime wrap — see the header.
if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Check if Video Production toolkit is enabled.
	$nvoos_content_graph_pro_settings   = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled = ! empty( $nvoos_content_graph_pro_settings['enable_video_production_toolkit'] );
	$nvoos_content_graph_pro_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

	// Only load if enabled and not in base version.
	if ( $nvoos_content_graph_pro_is_enabled && ! $nvoos_content_graph_pro_is_base ) {

		// Load Video Production admin pages (deferred — file-gated until the
		// video-production admin slice lands).
		if ( is_admin() ) {
			$nvoos_content_graph_pro_video_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-video-production-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_video_settings ) ) {
				require_once $nvoos_content_graph_pro_video_settings;
			}
		}

		// Load Research & Add for CCT/CPT integration (deferred — file-gated
		// until the video-production admin slice lands).
		$nvoos_content_graph_pro_video_research_add = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-video-production-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_video_research_add ) ) {
			require_once $nvoos_content_graph_pro_video_research_add;
			new WP_MCP_AI_Video_Production_Research_Add();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/video-production/.
	}

	/**
	 * Enqueue video production toolkit admin styles.
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_video_production_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_video_production_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-video-production-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-video-production-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-video-production-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_video_production_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported video-production tool
	 * subset (inert standalone, consumed by the base plugin monolith), plus
	 * the tree-only import-blueprint tool. The four always-on exec-service
	 * tools stay deferred until the D8 + video-services slice lands.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_video_production_tools( $tools ) {
		$nvoos_content_graph_pro_video_tools = array(
			'WP_MCP_AI_Tool_Create_Video_From_Images'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-create-video-from-images.php',
			'WP_MCP_AI_Tool_Add_Watermark_To_Video'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-add-watermark-to-video.php',
			'WP_MCP_AI_Tool_Generate_Video_Captions'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-generate-video-captions.php',
			'WP_MCP_AI_Tool_Merge_Videos'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-merge-videos.php',
			'WP_MCP_AI_Tool_Trim_Video'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-trim-video.php',
			'WP_MCP_AI_Tool_Resize_Video_Resolution'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-resize-video-resolution.php',
			'WP_MCP_AI_Tool_Adjust_Video_Speed'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-adjust-video-speed.php',
			'WP_MCP_AI_Tool_Compress_Video'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-compress-video.php',
			'WP_MCP_AI_Tool_Convert_Video_Format'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-convert-video-format.php',
			'WP_MCP_AI_Tool_Optimize_For_Platform'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-optimize-for-platform.php',
			'WP_MCP_AI_Tool_Extract_Video_Metadata'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-extract-video-metadata.php',
			'WP_MCP_AI_Tool_Generate_Video_Thumbnails'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-generate-video-thumbnails.php',
			'WP_MCP_AI_Tool_Get_Queued_Videos'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-get-queued-videos.php',
			'WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-get-videos-without-thumbnails.php',
			'WP_MCP_AI_Tool_Get_Videos_Without_Transcripts' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-get-videos-without-transcripts.php',
			'WP_MCP_AI_Tool_Upload_Video_Batch'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-upload-video-batch.php',
			'WP_MCP_AI_Tool_Transcribe_Video'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-transcribe-video.php',
			// Tree-only tool (the base registers it nowhere — the standalone
			// filter/ecosystem additions are its registration, CRM CC-extras
			// precedent).
			'WP_MCP_AI_Tool_Import_Video_Production_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/examples/class-wp-mcp-ai-tool-import-video-production-blueprint.php',
			// The always-on exec-service tools (the base registers these in the
			// unconditional `$pro_tools` map — they carry no enable gate).
			'WP_MCP_AI_Tool_Transcode_Video'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-transcode-video.php',
			'WP_MCP_AI_Tool_Extract_Video_Frames'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-extract-video-frames.php',
			'WP_MCP_AI_Tool_Get_Video_Metadata'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-get-video-metadata.php',
			'WP_MCP_AI_Tool_Create_Remotion_Video'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-create-remotion-video.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_video_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * video-production tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the financial/ecommerce inits).
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_video_production_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-create-video-from-images.php';
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/video-production/class-wp-mcp-ai-tool-trim-video.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Create_Video_From_Images',
				'WP_MCP_AI_Tool_Add_Watermark_To_Video',
				'WP_MCP_AI_Tool_Generate_Video_Captions',
				'WP_MCP_AI_Tool_Merge_Videos',
				'WP_MCP_AI_Tool_Trim_Video',
				'WP_MCP_AI_Tool_Resize_Video_Resolution',
				'WP_MCP_AI_Tool_Adjust_Video_Speed',
				'WP_MCP_AI_Tool_Compress_Video',
				'WP_MCP_AI_Tool_Convert_Video_Format',
				'WP_MCP_AI_Tool_Optimize_For_Platform',
				'WP_MCP_AI_Tool_Extract_Video_Metadata',
				'WP_MCP_AI_Tool_Generate_Video_Thumbnails',
				'WP_MCP_AI_Tool_Get_Queued_Videos',
				'WP_MCP_AI_Tool_Get_Videos_Without_Thumbnails',
				'WP_MCP_AI_Tool_Get_Videos_Without_Transcripts',
				'WP_MCP_AI_Tool_Upload_Video_Batch',
				'WP_MCP_AI_Tool_Transcribe_Video',
				'WP_MCP_AI_Tool_Import_Video_Production_Blueprint',
				'WP_MCP_AI_Tool_Transcode_Video',
				'WP_MCP_AI_Tool_Extract_Video_Frames',
				'WP_MCP_AI_Tool_Get_Video_Metadata',
				'WP_MCP_AI_Tool_Create_Remotion_Video',
			) as $nvoos_content_graph_pro_tool_class
		) {
			$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
			try {
				$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}

			// Wrap into the nvoos/core registry so the agentic chat loop can
			// resolve and execute the tool (same path the AI addon uses).
			if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
				$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
				try {
					$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
				} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
					unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
				}
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation 5). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_video_production_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_video_production_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
