<?php
/**
 * WP_MCP_AI_Tool_Update_Registration_Status (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Updates registration status.
 */
class WP_MCP_AI_Tool_Update_Registration_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_registration_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Registration Status', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates the status of a registration (Draft, Pending Documents, Ready for Submission, Submitted, Under Review, Approved, Rejected, On Hold, Renewal Due).', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'status'          => array(
					'type'        => 'string',
					'description' => __( 'New status (required): Draft, Pending Documents, Ready for Submission, Submitted, Under Review, Approved, Rejected, On Hold, or Renewal Due', 'nvoos-content-graph-pro' ),
					'enum'        => array(
						'Draft',
						'Pending Documents',
						'Ready for Submission',
						'Submitted',
						'Under Review',
						'Approved',
						'Rejected',
						'On Hold',
						'Renewal Due',
					),
				),
				'submission_date' => array(
					'type'        => 'string',
					'description' => __( 'Submission date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'approval_date'   => array(
					'type'        => 'string',
					'description' => __( 'Approval date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'expiry_date'     => array(
					'type'        => 'string',
					'description' => __( 'Expiry/renewal date (YYYY-MM-DD format, optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'cos_number'      => array(
					'type'        => 'string',
					'description' => __( 'COS/registration certificate number (optional)', 'nvoos-content-graph-pro' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional notes about the status change (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'registration_id', 'status' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Modifies database.
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update registrations.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( empty( $arguments['status'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Status is required.', 'nvoos-content-graph-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$status          = sanitize_text_field( $arguments['status'] );

		// Get the registration.
		$registration = get_post( $registration_id );

		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'nvoos-content-graph-pro' ) );
		}

		// Update status taxonomy.
		$term = term_exists( $status, 'mcp_ai_reg_status' );
		if ( ! $term ) {
			$term = wp_insert_term( $status, 'mcp_ai_reg_status' );
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $registration_id, absint( $term['term_id'] ), 'mcp_ai_reg_status' );
		}

		// Update meta fields conditionally.
		if ( isset( $arguments['submission_date'] ) ) {
			$date = sanitize_text_field( $arguments['submission_date'] );
			if ( $this->validate_date( $date ) ) {
				update_post_meta( $registration_id, 'submission_date', $date );
			}
		}

		if ( isset( $arguments['approval_date'] ) ) {
			$date = sanitize_text_field( $arguments['approval_date'] );
			if ( $this->validate_date( $date ) ) {
				update_post_meta( $registration_id, 'approval_date', $date );
			}
		}

		if ( isset( $arguments['expiry_date'] ) ) {
			$date = sanitize_text_field( $arguments['expiry_date'] );
			if ( $this->validate_date( $date ) ) {
				update_post_meta( $registration_id, 'expiry_date', $date );
			}
		}

		if ( isset( $arguments['cos_number'] ) ) {
			update_post_meta( $registration_id, 'cos_number', sanitize_text_field( $arguments['cos_number'] ) );
		}

		// Update notes if provided.
		if ( isset( $arguments['notes'] ) ) {
			wp_update_post(
				array(
					'ID'           => $registration_id,
					'post_content' => wp_kses_post( $arguments['notes'] ),
				)
			);
		}

		// Get updated registration data.
		$updated_registration = get_post( $registration_id );
		$registration_data    = array(
			'id'              => $updated_registration->ID,
			'title'           => $updated_registration->post_title,
			'notes'           => $updated_registration->post_content,
			'status'          => $status,
			'country'         => get_post_meta( $registration_id, 'country', true ),
			'cos_number'      => get_post_meta( $registration_id, 'cos_number', true ),
			'submission_date' => get_post_meta( $registration_id, 'submission_date', true ),
			'approval_date'   => get_post_meta( $registration_id, 'approval_date', true ),
			'expiry_date'     => get_post_meta( $registration_id, 'expiry_date', true ),
			'modified_date'   => $updated_registration->post_modified,
		);

		return array(
			'success'      => true,
			'message'      => __( 'Registration status updated successfully.', 'nvoos-content-graph-pro' ),
			'registration' => $registration_data,
		);
	}

	/**
	 * Validate date format.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
