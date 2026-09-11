<?php
/**
 * Document Generation Toolkit Initialization (ecosystem port — Wave F2,
 * document-generation data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/document-generation/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical.
 *
 * Documented deviations:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_*` with the `src/`
 *    root.
 * 3. Slimmed wiring — the settings-page + research-page requires are
 *    file-gated (they land with the document-generation admin slice), the
 *    research-add require is file-gated, and the tool registry registration
 *    is replaced by the standalone-only tool wiring (deviation 5).
 * 4. Monolith guard — this init declares the global
 *    `wp_mcp_ai_enqueue_document_generation_toolkit_admin_styles()` helper
 *    that the base init also declares; the collision is a compile-time
 *    fatal, so the ENTIRE body is wrapped in a runtime
 *    `! defined( 'WP_MCP_AI_PATH' )` block (financial-init deviation 5
 *    precedent).
	 * 5. New standalone-only tool wiring (financial-init deviation 6
	 *    precedent): a `wp_mcp_ai_pro_tools` filter plus
	 *    `wp_mcp_ai_pro_register_document_generation_ecosystem_tools()` —
	 *    both carry all thirty-four ported tools (the eleven always-on
	 *    email/QMS tools, the twenty-one toolkit-gated tools, the two
	 *    tree-only OCR tools, and the tree-only import-blueprint tool).
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

	// Load Document Template CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-document-template-cpt.php';

	// Register Document Template meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_doc_tpl' );
	}

	// Load Document Generation admin pages (file-gated — they land with the
	// document-generation admin slice).
	if ( is_admin() ) {
		$nvoos_content_graph_pro_docgen_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_docgen_settings ) ) {
			require_once $nvoos_content_graph_pro_docgen_settings;
			new WP_MCP_AI_Document_Generation_Settings_Page();
		}

		$nvoos_content_graph_pro_docgen_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-document-template-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_docgen_research ) ) {
			require_once $nvoos_content_graph_pro_docgen_research;
			WP_MCP_AI_Document_Template_Research_Page::init();
		}
	}

	// Check if Document Generation toolkit is enabled for advanced features.
	$nvoos_content_graph_pro_settings      = get_option( 'wp_mcp_ai_settings', array() );
	$nvoos_content_graph_pro_is_enabled    = ! empty( $nvoos_content_graph_pro_settings['enable_document_generation_toolkit'] );
	$nvoos_content_graph_pro_is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$nvoos_content_graph_pro_is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	// Only load advanced features if enabled and (not in base version or the Pro addon is active).
	if ( $nvoos_content_graph_pro_is_enabled && ( ! $nvoos_content_graph_pro_is_base || $nvoos_content_graph_pro_is_pro_active ) ) {
		// Load Research & Add for CCT/CPT integration (file-gated — lands with
		// the document-generation admin slice).
		$nvoos_content_graph_pro_docgen_research_add = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-document-generation-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_docgen_research_add ) ) {
			require_once $nvoos_content_graph_pro_docgen_research_add;
			new WP_MCP_AI_Document_Generation_Research_Add();
		}

		// --- Performance optimization (QMS audit log retention, audit schema autoload) ---
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-document-gen-optimization.php';
		WP_MCP_AI_Document_Gen_Optimization::init();
	}

	// Initialize Document Template CPT.
	add_action(
		'init',
		function () {
			WP_MCP_AI_Document_Template_CPT::init();
		},
		5
	);

	/**
	 * Enqueue document generation toolkit admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_document_generation_toolkit_admin_styles( $hook ) {
		// Only load if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return;
		}

		// Check if we're on document template pages.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_doc_tpl' ), true ) ) {
			// Also check for new settings page.
			if ( ! $screen || 'mcp_ai_doc_tpl_page_document-generation-settings' !== $screen->id ) {
				return;
			}
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-document-generation-toolkit.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-document-generation-toolkit-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-document-generation-toolkit.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_document_generation_toolkit_admin_styles' );

	/**
	 * Standalone-only tool filter — carries the ported document-generation
	 * tool subset (inert standalone, consumed by the base plugin monolith).
	 * The map fills as the document-generation tool batches land.
	 *
	 * @param array $tools Existing tool map (class => file).
	 * @return array Extended tool map.
	 */
	function wp_mcp_ai_pro_register_document_generation_tools( $tools ) {
		$nvoos_content_graph_pro_docgen_tools = array(
			'WP_MCP_AI_Tool_Generate_Email_Template'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-email-template.php',
			'WP_MCP_AI_Tool_QMS_Create_Controlled_Document' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-create-controlled-document.php',
			'WP_MCP_AI_Tool_QMS_Submit_For_Review'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-submit-for-review.php',
			'WP_MCP_AI_Tool_QMS_Approve_Document'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-approve-document.php',
			'WP_MCP_AI_Tool_QMS_Release_Document'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-release-document.php',
			'WP_MCP_AI_Tool_QMS_Supersede_Document'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-supersede-document.php',
			'WP_MCP_AI_Tool_QMS_Mark_Obsolete'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-mark-obsolete.php',
			'WP_MCP_AI_Tool_QMS_Sign_Document'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-sign-document.php',
			'WP_MCP_AI_Tool_QMS_List_Controlled_Documents' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-list-controlled-documents.php',
			'WP_MCP_AI_Tool_QMS_Get_Audit_Trail'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-get-audit-trail.php',
			'WP_MCP_AI_Tool_QMS_Schedule_Review'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-qms-schedule-review.php',
			'WP_MCP_AI_Tool_Pro_PDF'                       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php',
			'WP_MCP_AI_Tool_Pro_Word'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php',
			'WP_MCP_AI_Tool_Pro_Excel_Document'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php',
			'WP_MCP_AI_Tool_Generate_PDF'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php',
			'WP_MCP_AI_Tool_Generate_Word'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php',
			'WP_MCP_AI_Tool_Generate_Excel'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php',
			'WP_MCP_AI_Tool_Extract_PDF_Text'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php',
			'WP_MCP_AI_Tool_OCR_PDF_Text'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php',
			'WP_MCP_AI_Tool_Pro_Document_OCR'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php',
			'WP_MCP_AI_Tool_HTML_To_PDF'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php',
			'WP_MCP_AI_Tool_Merge_PDFs'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php',
			'WP_MCP_AI_Tool_Add_Watermark_To_PDF'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php',
			'WP_MCP_AI_Tool_Generate_Invoice_PDF'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php',
			'WP_MCP_AI_Tool_Excel_Data_Import'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php',
			'WP_MCP_AI_Tool_Excel_Data_Export'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-excel-data-export.php',
			'WP_MCP_AI_Tool_DocGen_Capture_Style_Memory'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-docgen-capture-style-memory.php',
			'WP_MCP_AI_Tool_Get_Expired_Documents'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-get-expired-documents.php',
			'WP_MCP_AI_Tool_Get_Uninvoiced_Orders'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-get-uninvoiced-orders.php',
			'WP_MCP_AI_Tool_Archive_Documents'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-archive-documents.php',
			'WP_MCP_AI_Tool_Generate_Invoice_Batch'        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-batch.php',
			'WP_MCP_AI_Tool_Pro_Batch_OCR'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-batch-ocr.php',
			'WP_MCP_AI_Tool_Pro_Unlimited_OCR'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/class-wp-mcp-ai-tool-pro-unlimited-ocr.php',
			'WP_MCP_AI_Tool_Import_Document_Generation_Blueprint' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/document-generation/examples/class-wp-mcp-ai-tool-import-document-generation-blueprint.php',
		);

		return array_merge( $tools, $nvoos_content_graph_pro_docgen_tools );
	}

	/**
	 * Standalone-only ecosystem registration — registers the ported
	 * document-generation tools into the ecosystem graph ToolRegistry and
	 * the nvoos/core registry via `WP_MCP_AI_Pro_Tool_Adapter` (same wiring
	 * as the image-production/video inits). The list fills as the
	 * document-generation tool batches land.
	 *
	 * @return void
	 */
	function wp_mcp_ai_pro_register_document_generation_ecosystem_tools() {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

		$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
		if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
			return;
		}

		foreach (
			array(
				'WP_MCP_AI_Tool_Generate_Email_Template',
				'WP_MCP_AI_Tool_QMS_Create_Controlled_Document',
				'WP_MCP_AI_Tool_QMS_Submit_For_Review',
				'WP_MCP_AI_Tool_QMS_Approve_Document',
				'WP_MCP_AI_Tool_QMS_Release_Document',
				'WP_MCP_AI_Tool_QMS_Supersede_Document',
				'WP_MCP_AI_Tool_QMS_Mark_Obsolete',
				'WP_MCP_AI_Tool_QMS_Sign_Document',
				'WP_MCP_AI_Tool_QMS_List_Controlled_Documents',
				'WP_MCP_AI_Tool_QMS_Get_Audit_Trail',
				'WP_MCP_AI_Tool_QMS_Schedule_Review',
				'WP_MCP_AI_Tool_Pro_PDF',
				'WP_MCP_AI_Tool_Pro_Word',
				'WP_MCP_AI_Tool_Pro_Excel_Document',
				'WP_MCP_AI_Tool_Generate_PDF',
				'WP_MCP_AI_Tool_Generate_Word',
				'WP_MCP_AI_Tool_Generate_Excel',
				'WP_MCP_AI_Tool_Extract_PDF_Text',
				'WP_MCP_AI_Tool_OCR_PDF_Text',
				'WP_MCP_AI_Tool_Pro_Document_OCR',
				'WP_MCP_AI_Tool_HTML_To_PDF',
				'WP_MCP_AI_Tool_Merge_PDFs',
				'WP_MCP_AI_Tool_Add_Watermark_To_PDF',
				'WP_MCP_AI_Tool_Generate_Invoice_PDF',
				'WP_MCP_AI_Tool_Excel_Data_Import',
				'WP_MCP_AI_Tool_Excel_Data_Export',
				'WP_MCP_AI_Tool_DocGen_Capture_Style_Memory',
				'WP_MCP_AI_Tool_Get_Expired_Documents',
				'WP_MCP_AI_Tool_Get_Uninvoiced_Orders',
				'WP_MCP_AI_Tool_Archive_Documents',
				'WP_MCP_AI_Tool_Generate_Invoice_Batch',
				'WP_MCP_AI_Tool_Pro_Batch_OCR',
				'WP_MCP_AI_Tool_Pro_Unlimited_OCR',
				'WP_MCP_AI_Tool_Import_Document_Generation_Blueprint',
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
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_document_generation_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_document_generation_ecosystem_tools();
	}
} // End monolith guard (deviation 4).
