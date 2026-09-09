<?php
/**
 * WP_MCP_AI_Tool_Get_Reg_Document (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Gets a regulatory document.
 */
class WP_MCP_AI_Tool_Get_Reg_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_reg_document';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Regulatory Document', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific document in the regulatory registration system.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Document ID to retrieve (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'include_product'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include related product information (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'include_registration' => array(
					'type'        => 'boolean',
					'description' => __( 'Include related registration information (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'document_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['document_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Document ID is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$document_id = absint( $arguments['document_id'] );

		// Get document.
		$document = get_post( $document_id );
		if ( ! $document || 'mcp_ai_reg_document' !== $document->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Document not found.', 'nvoos-content-graph-pro' ),
			);
		}

		// Get metadata.
		$product_id      = get_post_meta( $document_id, 'product_id', true );
		$registration_id = get_post_meta( $document_id, 'registration_id', true );
		$document_type   = get_post_meta( $document_id, 'document_type', true );
		$file_url        = get_post_meta( $document_id, 'file_url', true );
		$version         = get_post_meta( $document_id, 'version', true );
		$issue_date      = get_post_meta( $document_id, 'issue_date', true );
		$expiry_date     = get_post_meta( $document_id, 'expiry_date', true );

		// Calculate expiry status.
		$expiry_status  = 'valid';
		$days_to_expiry = null;
		if ( ! empty( $expiry_date ) ) {
			$expiry_timestamp = strtotime( $expiry_date );
			$current_time     = time();
			if ( $expiry_timestamp < $current_time ) {
				$expiry_status  = 'expired';
				$days_to_expiry = floor( ( $expiry_timestamp - $current_time ) / DAY_IN_SECONDS );
			} elseif ( $expiry_timestamp < strtotime( '+90 days', $current_time ) ) {
				$expiry_status  = 'expiring_soon';
				$days_to_expiry = floor( ( $expiry_timestamp - $current_time ) / DAY_IN_SECONDS );
			} else {
				$days_to_expiry = floor( ( $expiry_timestamp - $current_time ) / DAY_IN_SECONDS );
			}
		}

		// Get document type terms.
		$doc_types = wp_get_object_terms( $document_id, 'mcp_ai_doc_type', array( 'fields' => 'names' ) );

		// Build document data.
		$document_data = array(
			'document_id'     => $document_id,
			'title'           => $document->post_title,
			'document_type'   => $document_type,
			'file_url'        => $file_url,
			'version'         => $version,
			'issue_date'      => $issue_date,
			'expiry_date'     => $expiry_date,
			'expiry_status'   => $expiry_status,
			'days_to_expiry'  => $days_to_expiry,
			'status'          => $document->post_status,
			'notes'           => $document->post_content,
			'created_at'      => $document->post_date,
			'modified_at'     => $document->post_modified,
			'product_id'      => $product_id,
			'registration_id' => $registration_id,
			'taxonomies'      => array(
				'doc_types' => $doc_types,
			),
		);

		// Include product information if requested.
		if ( ! empty( $arguments['include_product'] ) && ! empty( $product_id ) ) {
			$product = get_post( $product_id );
			if ( $product && 'mcp_ai_reg_product' === $product->post_type ) {
				$document_data['product'] = array(
					'id'    => $product_id,
					'title' => $product->post_title,
					'brand' => get_post_meta( $product_id, 'brand', true ),
				);
			}
		}

		// Include registration information if requested.
		if ( ! empty( $arguments['include_registration'] ) && ! empty( $registration_id ) ) {
			$registration = get_post( $registration_id );
			if ( $registration && 'mcp_ai_registration' === $registration->post_type ) {
				$document_data['registration'] = array(
					'id'         => $registration_id,
					'title'      => $registration->post_title,
					'country'    => get_post_meta( $registration_id, 'country', true ),
					'cos_number' => get_post_meta( $registration_id, 'cos_number', true ),
				);
			}
		}

		return array(
			'success'  => true,
			'document' => $document_data,
		);
	}
}
