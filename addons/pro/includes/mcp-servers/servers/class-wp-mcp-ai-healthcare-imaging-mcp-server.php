<?php
/**
 * Healthcare Imaging Toolkit MCP Server
 *
 * Phase 6 Tier-2 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Healthcare Imaging MCP server.
 *
 * Exposes DICOM import/export, radiology reporting, and imaging-study
 * comparison tools. Tools-only server; the imaging dashboard is a pure
 * viewer without a CPT-shaped ingestion surface.
 */
class WP_MCP_AI_Healthcare_Imaging_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'healthcare-imaging';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Healthcare Imaging', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'DICOM study management, radiology report attachment, DICOMweb connectivity, and imaging study comparison. Tools-only server.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Healthcare Imaging MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_healthcare_imaging_candidate_tools',
			array(
				'import_dicom_study',
				'export_dicom_study',
				'connect_dicomweb',
				'attach_radiology_report',
				'compare_imaging_studies',
				'get_imaging_hanging_protocol',
				'interpret_imaging_study',
				'manage_imaging_studies',
			)
		);
	}
}
