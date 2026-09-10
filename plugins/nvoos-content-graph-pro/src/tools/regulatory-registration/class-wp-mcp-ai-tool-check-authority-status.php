<?php
/**
 * WP_MCP_AI_Tool_Check_Authority_Status (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Checks authority status across multiple countries.
 */
class WP_MCP_AI_Tool_Check_Authority_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_authority_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Authority Status', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks registration status across multiple regulatory authorities and countries, providing unified status updates and tracking information.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of registration IDs to check (required)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'countries'        => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'include_history'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include status history (optional, default: false)', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'registration_ids' ),
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
			'idempotent',           // Can be called multiple times safely.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check authority status.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_ids'] ) || ! is_array( $arguments['registration_ids'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration IDs array is required.', 'nvoos-content-graph-pro' ) );
		}

		$registration_ids = array_map( 'absint', $arguments['registration_ids'] );
		$countries        = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();
		$include_history  = ! empty( $arguments['include_history'] );

		$results = array();

		foreach ( $registration_ids as $registration_id ) {
			// Verify registration exists.
			$registration = get_post( $registration_id );
			if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
				$results[] = array(
					'registration_id' => $registration_id,
					'error'           => __( 'Registration not found', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			// Get registration details.
			$country   = get_post_meta( $registration_id, 'country', true );
			$authority = get_post_meta( $registration_id, 'authority', true );

			// Filter by countries if specified.
			if ( ! empty( $countries ) && ! in_array( $country, $countries, true ) ) {
				continue;
			}

			// Get current status.
			$status_terms = wp_get_post_terms( $registration_id, 'mcp_ai_reg_status' );
			$status       = ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ? $status_terms[0]->name : 'Unknown';

			$registration_data = array(
				'registration_id' => $registration_id,
				'title'           => $registration->post_title,
				'country'         => $country,
				'authority'       => $authority,
				'status'          => $status,
				'submission_date' => get_post_meta( $registration_id, 'submission_date', true ),
				'approval_date'   => get_post_meta( $registration_id, 'approval_date', true ),
				'expiry_date'     => get_post_meta( $registration_id, 'expiry_date', true ),
				'last_checked'    => current_time( 'mysql' ),
			);

			// Get authority-specific data.
			if ( 'Sri Lanka' === $country || 'LK' === $country ) {
				$registration_data['nmra_status']    = get_post_meta( $registration_id, '_nmra_status', true );
				$registration_data['nmra_reference'] = get_post_meta( $registration_id, '_nmra_reference', true );
			} elseif ( 'UAE' === $country || 'AE' === $country ) {
				$registration_data['mohap_status']      = get_post_meta( $registration_id, '_mohap_status', true );
				$registration_data['mohap_tracking_id'] = get_post_meta( $registration_id, '_mohap_tracking_id', true );
			}

			// Include history if requested.
			if ( $include_history ) {
				$registration_data['status_history'] = get_post_meta( $registration_id, '_status_history', true );
			}

			$results[] = $registration_data;
		}

		return array(
			'success'    => true,
			'total'      => count( $results ),
			'checked_at' => current_time( 'mysql' ),
			'results'    => $results,
		);
	}
}
