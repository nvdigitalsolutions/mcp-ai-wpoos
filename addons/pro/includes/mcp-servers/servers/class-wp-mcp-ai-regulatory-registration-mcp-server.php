<?php
/**
 * Regulatory Registration Toolkit MCP Server
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
 * Regulatory Registration MCP server.
 */
class WP_MCP_AI_Regulatory_Registration_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'regulatory-registration';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Regulatory Registration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cosmetic and pharmaceutical product registrations across regulatory authorities. Three-page Research & Add coverage (product, document, registration).',
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
				'page_slug'          => 'wp-mcp-ai-reg-product-research',
				'entity_type'        => 'mcp_ai_reg_product',
				'class_ref'          => 'WP_MCP_AI_Reg_Product_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Regulatory Products', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'wp-mcp-ai-reg-document-research',
				'entity_type'        => 'mcp_ai_reg_document',
				'class_ref'          => 'WP_MCP_AI_Reg_Document_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Regulatory Documents', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'wp-mcp-ai-registration-research',
				'entity_type'        => 'mcp_ai_registration',
				'class_ref'          => 'WP_MCP_AI_Registration_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Registrations', 'mcp-ai-wpoos-pro' ),
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
		 * Filter the candidate tool slugs the Regulatory Registration MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_regulatory_registration_candidate_tools',
			array(
				'create_reg_product',
				'update_reg_product',
				'delete_reg_product',
				'duplicate_reg_product',
				'list_reg_products',
				'get_reg_product',
				'search_reg_products',
				'validate_reg_product',
				'validate_inci_ingredients',
				'check_hs_code',
				'check_product_compliance',
				'upload_reg_document',
				'update_reg_document',
				'get_reg_document',
				'list_reg_documents',
				'track_document_version',
				'validate_document_checklist',
				'check_document_expiry',
				'create_registration',
				'update_registration_status',
				'submit_registration',
				'approve_registration',
				'renew_registration',
				'get_registration',
				'get_registration_timeline',
				'list_registrations',
				'list_registrations_by_country',
				'list_expiring_registrations',
				'submit_to_authority',
				'check_authority_status',
				'sync_with_mohap',
				'sync_with_nmra',
				'add_regulatory_requirement',
				'get_regulatory_requirements',
				'get_regulatory_updates',
				'create_workflow_rule',
				'update_workflow_rule',
				'delete_workflow_rule',
				'list_workflow_rules',
				'test_workflow_rule',
				'get_workflow_execution_log',
				'configure_email_notifications',
				'get_notification_history',
				'send_status_change_notification',
				'send_expiry_alerts',
				'generate_compliance_report',
				'generate_compliance_certificate',
				'generate_country_performance',
				'generate_pipeline_report',
				'generate_cost_analysis',
				'generate_expiry_forecast',
				'generate_pdf_dossier',
				'generate_submission_pack',
				'generate_cover_letter',
				'export_products_to_excel',
				'export_registrations_to_excel',
				'import_products_from_excel',
				'import_registrations_from_excel',
				'validate_excel_import',
			)
		);
	}
}
