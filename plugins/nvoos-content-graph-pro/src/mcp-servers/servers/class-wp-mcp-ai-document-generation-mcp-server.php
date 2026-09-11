<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-document-generation-mcp-server.php` for the standalone
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


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Generation MCP server.
 */
class WP_MCP_AI_Document_Generation_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'document-generation';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Document Generation', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'PDF, Word, and Excel document generation with QMS controls and OCR. Owns the Document Template research surface.',
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
				'type'               => 'research_add',
				'page_slug'          => 'research-document-template',
				'entity_type'        => 'mcp_ai_doc_tpl',
				'class_ref'          => 'WP_MCP_AI_Document_Template_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Document Templates', 'nvoos-content-graph-pro' ),
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
		 * Filter the candidate tool slugs the Document Generation MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_document_generation_candidate_tools',
			array(
				'generate_pdf',
				'generate_word',
				'generate_excel',
				'generate_invoice_pdf',
				'html_to_pdf',
				'merge_pdfs',
				'extract_pdf_text',
				'ocr_pdf_text',
				'add_watermark_to_pdf',
				'excel_data_export',
				'excel_data_import',
				'pro_pdf_document',
				'pro_word_document',
				'pro_excel_document',
				'pro_document_ocr',
				'docgen_capture_style_memory',
				'qms_create_controlled_document',
				'qms_submit_for_review',
				'qms_approve_document',
				'qms_release_document',
				'qms_sign_document',
				'qms_supersede_document',
				'qms_mark_obsolete',
				'qms_schedule_review',
				'qms_get_audit_trail',
				'qms_list_controlled_documents',
			)
		);
	}
}
