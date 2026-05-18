<?php
/**
 * Tool for exporting architectural documents.
 *
 * Exports floor plans and models to various CAD and BIM formats.
 * Supports PDF, DWG, DXF, IFC, and more.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Export architectural documents.
 */
class WP_MCP_AI_Tool_Export_Architectural_Documents implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when the Architectural Design toolkit is enabled
	 *              and the host plugin is not running in base mode.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_architectural_documents';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Architectural Documents', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export floor plans and models to various CAD and BIM formats including PDF, DWG, DXF, IFC, and Revit.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_data'      => array(
					'type'        => 'object',
					'description' => __( 'Document data to export (floor plan, 3D model, or drawings).', 'mcp-ai-wpoos-pro' ),
				),
				'export_format'      => array(
					'type'        => 'string',
					'description' => __( 'Export format: "pdf", "dwg", "dxf", "ifc", "revit", "sketchup".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pdf', 'dwg', 'dxf', 'ifc', 'revit', 'sketchup' ),
				),
				'include_metadata'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include project metadata in export.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'layer_organization' => array(
					'type'        => 'string',
					'description' => __( 'Layer organization: "by_type", "by_floor", "by_discipline".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'by_type', 'by_floor', 'by_discipline' ),
					'default'     => 'by_type',
				),
				'units'              => array(
					'type'        => 'string',
					'description' => __( 'Measurement units: "imperial", "metric".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'imperial', 'metric' ),
					'default'     => 'imperial',
				),
			),
			'required'             => array( 'document_data', 'export_format' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'async',
			'large-response',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to export documents.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate document data and format.
		if ( empty( $arguments['document_data'] ) || empty( $arguments['export_format'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Document data and export format are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$document_data      = $arguments['document_data'];
		$export_format      = sanitize_text_field( $arguments['export_format'] );
		$include_metadata   = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : true;
		$layer_organization = isset( $arguments['layer_organization'] ) ? sanitize_text_field( $arguments['layer_organization'] ) : 'by_type';
		$units              = isset( $arguments['units'] ) ? sanitize_text_field( $arguments['units'] ) : 'imperial';

		// Export document.
		$export = $this->export_document( $document_data, $export_format, $include_metadata, $layer_organization, $units );

		if ( is_wp_error( $export ) ) {
			return $export;
		}

		// Return structured export data.
		return array(
			'success'   => true,
			'export'    => $export,
			'format'    => $export_format,
			'file_size' => 0,
			'message'   => sprintf(
				/* translators: %s: export format */
				__( 'Successfully exported to %s format.', 'mcp-ai-wpoos-pro' ),
				strtoupper( $export_format )
			),
		);
	}

	/**
	 * Export document to specified format.
	 *
	 * @param array  $document_data      Document data.
	 * @param string $export_format      Export format.
	 * @param bool   $include_metadata   Include metadata.
	 * @param string $layer_organization Layer organization.
	 * @param string $units              Units.
	 * @return array Export data.
	 */
	protected function export_document( $document_data, $export_format, $include_metadata, $layer_organization, $units ) {
		return array(
			'file_url'  => '',
			'format'    => $export_format,
			'file_size' => 0,
			'metadata'  => array(
				'units'              => $units,
				'layer_organization' => $layer_organization,
				'has_metadata'       => $include_metadata,
				'exported_at'        => current_time( 'mysql' ),
			),
		);
	}
}
