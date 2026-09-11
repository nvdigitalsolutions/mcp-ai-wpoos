<?php
/**
 * Image Production Toolkit Initialization (ecosystem port — Wave F2,
 * image-production data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/image-production/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the admin pages (CPT settings + image-template
 *    research) and the research-add page requires are file-gated and the
 *    harmonization init require stays file-gated (all land with the later
 *    image-production slices).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_image_production_toolkit_admin_styles()` helper that
 *    the base init also declares; the collision is a compile-time fatal, so
 *    the ENTIRE body is wrapped in a runtime `! defined( 'WP_MCP_AI_PATH' )`
 *    block (financial-init deviation 5 precedent).
 * 5. New standalone-only tool wiring (financial-init deviation 6
 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
 *    `wp_mcp_ai_pro_register_image_production_ecosystem_tools()` — both
 *    start with empty maps and fill as the image-production tool batches
 *    land.
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

	// Load Image Template CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-image-template-cpt.php';

	// Register Image Template meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_image_tpl' );
	}

	// Load Image Production admin pages (always load so menu items appear).
	if ( is_admin() ) {
		// Load CPT-based settings page (deferred — file-gated until the
		// image-production admin slice lands).
		$nvoos_content_graph_pro_img_cpt_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_img_cpt_settings ) ) {
			require_once $nvoos_content_graph_pro_img_cpt_settings;
			new WP_MCP_AI_Image_Production_Settings_Page();
		}

		// Load and initialize Research & Add page for image templates (deferred
		// — file-gated until the image-production admin slice lands).
		$nvoos_content_graph_pro_img_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-image-template-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_img_research ) ) {
			require_once $nvoos_content_graph_pro_img_research;
			WP_MCP_AI_Image_Template_Research_Page::init();
		}
	}

	// Check if Image Production toolkit is enabled for advanced features.
	$nvoos_content_graph_pro_settings      = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled    = ! empty( $nvoos_content_graph_pro_settings['enable_image_production_toolkit'] );
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	// Only load advanced features if enabled and (not in base version or the Pro addon is active).
	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {
		// Load Research & Add for CCT/CPT integration (deferred — file-gated
		// until the image-production admin slice lands).
		$nvoos_content_graph_pro_img_research_add = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-image-production-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_img_research_add ) ) {
			require_once $nvoos_content_graph_pro_img_research_add;
			new WP_MCP_AI_Image_Production_Research_Add();
		}

		// Register tools will be loaded automatically via the tools directory structure.
		// Tools are located in: plugins/nvoos-content-graph-pro/src/tools/image-production/.

		// Load the harmonization sub-toolkit (deferred — file-gated until the
		// harmonization slice lands).
		$nvoos_content_graph_pro_harmonization_init = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonization-init.php';
		if ( file_exists( $nvoos_content_graph_pro_harmonization_init ) ) {
			require_once $nvoos_content_graph_pro_harmonization_init;
		}
	}

	// Initialize Image Template CPT.
	add_action(
		'init',
		function () {
			WP_MCP_AI_Image_Template_CPT::init();
		},
		5
	);

	/**
	 * Enqueue image production toolkit admin styles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $hook Current admin page hook (unused).
	 */
	function wp_mcp_ai_enqueue_image_production_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_image_production_toolkit'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-image-production-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-image-production-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-image-production-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_image_production_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported image-production tool
	 * subset (inert standalone, consumed by the base plugin monolith). The
	 * map carries the twenty-two top-level tools plus the tree-only import
	 * blueprint tool (the base registers only five of them inline).
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_image_production_tools( $tools ) {
		$nvoos_content_graph_pro_img_tools = array(
			'WP_MCP_AI_Tool_Adapt_Background_For_Subject' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-adapt-background-for-subject.php',
			'WP_MCP_AI_Tool_Analyze_Scene_Lighting'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-analyze-scene-lighting.php',
			'WP_MCP_AI_Tool_Apply_Artistic_Style'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-apply-artistic-style.php',
			'WP_MCP_AI_Tool_Apply_Watermark_Batch'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-apply-watermark-batch.php',
			'WP_MCP_AI_Tool_Auto_Clean_White_Background'  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-auto-clean-white-background.php',
			'WP_MCP_AI_Tool_Batch_Process_Images'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-batch-process-images.php',
			'WP_MCP_AI_Tool_Colorize_Image'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-colorize-image.php',
			'WP_MCP_AI_Tool_Compress_Image'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-compress-image.php',
			'WP_MCP_AI_Tool_Convert_Image_Format'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-convert-image-format.php',
			'WP_MCP_AI_Tool_Enhance_Image_Quality'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-enhance-image-quality.php',
			'WP_MCP_AI_Tool_Generate_Image_Ai'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php',
			'WP_MCP_AI_Tool_Generate_Image_Variations'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-generate-image-variations.php',
			'WP_MCP_AI_Tool_Generate_Reflection'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-generate-reflection.php',
			'WP_MCP_AI_Tool_Generate_Responsive_Images'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-generate-responsive-images.php',
			'WP_MCP_AI_Tool_Generate_Scene_Background'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-generate-scene-background.php',
			'WP_MCP_AI_Tool_Generate_Shadow'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-generate-shadow.php',
			'WP_MCP_AI_Tool_Get_Images_Without_Alt'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-get-images-without-alt.php',
			'WP_MCP_AI_Tool_Get_Unoptimised_Images'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-get-unoptimised-images.php',
			'WP_MCP_AI_Tool_Get_Unwatermarked_Images'     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-get-unwatermarked-images.php',
			'WP_MCP_AI_Tool_Harmonize_Batch'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonize-batch.php',
			'WP_MCP_AI_Tool_Harmonize_Color'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonize-color.php',
			'WP_MCP_AI_Tool_Harmonize_Image_Into_Background' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonize-image-into-background.php',
			'WP_MCP_AI_Tool_Image_Inpainting'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-image-inpainting.php',
			'WP_MCP_AI_Tool_Import_Image_Production_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/examples/class-wp-mcp-ai-tool-import-image-production-blueprint.php',
			'WP_MCP_AI_Tool_Optimise_Images_Batch'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-optimise-images-batch.php',
			'WP_MCP_AI_Tool_Optimize_For_Web'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-optimize-for-web.php',
			'WP_MCP_AI_Tool_Optimize_Image_Sharp'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-optimize-image-sharp.php',
			'WP_MCP_AI_Tool_Outpaint_Background'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-outpaint-background.php',
			'WP_MCP_AI_Tool_Refine_Composite_Boundary'    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-refine-composite-boundary.php',
			'WP_MCP_AI_Tool_Refine_Subject_Matte'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-refine-subject-matte.php',
			'WP_MCP_AI_Tool_Relight_Subject'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-relight-subject.php',
			'WP_MCP_AI_Tool_Remove_Background'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-remove-background.php',
			'WP_MCP_AI_Tool_Remove_Image_Background'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-remove-image-background.php',
			'WP_MCP_AI_Tool_Resize_Image_Smart'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-resize-image-smart.php',
			'WP_MCP_AI_Tool_Suggest_Placement'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/harmonization/class-wp-mcp-ai-tool-suggest-placement.php',
			'WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php',
			'WP_MCP_AI_Tool_Upscale_Image_Ai'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/image-production/class-wp-mcp-ai-tool-upscale-image-ai.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_img_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * image-production tools into the ecosystem graph ToolRegistry and the
	 * nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as
	 * the analytics/video inits). The list fills as the image-production
	 * tool batches land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_image_production_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Adapt_Background_For_Subject',
				'WP_MCP_AI_Tool_Analyze_Scene_Lighting',
				'WP_MCP_AI_Tool_Apply_Artistic_Style',
				'WP_MCP_AI_Tool_Apply_Watermark_Batch',
				'WP_MCP_AI_Tool_Auto_Clean_White_Background',
				'WP_MCP_AI_Tool_Batch_Process_Images',
				'WP_MCP_AI_Tool_Colorize_Image',
				'WP_MCP_AI_Tool_Compress_Image',
				'WP_MCP_AI_Tool_Convert_Image_Format',
				'WP_MCP_AI_Tool_Enhance_Image_Quality',
				'WP_MCP_AI_Tool_Generate_Image_Ai',
				'WP_MCP_AI_Tool_Generate_Image_Variations',
				'WP_MCP_AI_Tool_Generate_Reflection',
				'WP_MCP_AI_Tool_Generate_Responsive_Images',
				'WP_MCP_AI_Tool_Generate_Scene_Background',
				'WP_MCP_AI_Tool_Generate_Shadow',
				'WP_MCP_AI_Tool_Get_Images_Without_Alt',
				'WP_MCP_AI_Tool_Get_Unoptimised_Images',
				'WP_MCP_AI_Tool_Get_Unwatermarked_Images',
				'WP_MCP_AI_Tool_Harmonize_Batch',
				'WP_MCP_AI_Tool_Harmonize_Color',
				'WP_MCP_AI_Tool_Harmonize_Image_Into_Background',
				'WP_MCP_AI_Tool_Image_Inpainting',
				'WP_MCP_AI_Tool_Import_Image_Production_Blueprint',
				'WP_MCP_AI_Tool_Optimise_Images_Batch',
				'WP_MCP_AI_Tool_Optimize_For_Web',
				'WP_MCP_AI_Tool_Optimize_Image_Sharp',
				'WP_MCP_AI_Tool_Outpaint_Background',
				'WP_MCP_AI_Tool_Refine_Composite_Boundary',
				'WP_MCP_AI_Tool_Refine_Subject_Matte',
				'WP_MCP_AI_Tool_Relight_Subject',
				'WP_MCP_AI_Tool_Remove_Background',
				'WP_MCP_AI_Tool_Remove_Image_Background',
				'WP_MCP_AI_Tool_Resize_Image_Smart',
				'WP_MCP_AI_Tool_Suggest_Placement',
				'WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer',
				'WP_MCP_AI_Tool_Upscale_Image_Ai',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_image_production_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_image_production_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
