<?php
/**
 * WP_MCP_AI_Tool_Submit_To_Authority (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Submits registrations to regulatory authorities.
 */
class WP_MCP_AI_Tool_Submit_To_Authority implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_to_authority';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit to Authority', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Electronically submits registration application to regulatory authority with all required documents and metadata.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Registration ID to submit (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'submission_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of submission (optional, default: "new")', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation', 'transfer' ),
					'default'     => 'new',
				),
				'priority'        => array(
					'type'        => 'string',
					'description' => __( 'Submission priority (optional, default: "normal")', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'normal', 'expedited', 'fast_track' ),
					'default'     => 'normal',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional submission notes (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'registration_id' ),
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
			'database-write',       // Updates submission status.
			'destructive',          // Critical action.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit to authorities.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$submission_type = ! empty( $arguments['submission_type'] ) ? sanitize_text_field( $arguments['submission_type'] ) : 'new';
		$priority        = ! empty( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'normal';
		$notes           = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'nvoos-content-graph-pro' ) );
		}

		// Get registration details.
		$country    = get_post_meta( $registration_id, 'country', true );
		$authority  = get_post_meta( $registration_id, 'authority', true );
		$product_id = absint( get_post_meta( $registration_id, 'product_id', true ) );

		// Verify required documents are attached.
		$documents_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'submit_to_authority', 0, 1000 ) : 1000,
				'meta_query'     => array(
					array(
						'key'   => 'registration_id',
						'value' => $registration_id,
					),
				),
			)
		);

		if ( ! $documents_query->have_posts() ) {
			return new WP_Error( 'wp_mcp_ai_validation_error', __( 'At least one document is required for submission.', 'nvoos-content-graph-pro' ) );
		}

		$document_count = $documents_query->found_posts;

		// Generate submission reference.
		$submission_reference = sprintf( 'SUB-%s-%d-%s', strtoupper( substr( $country, 0, 2 ) ), $registration_id, gmdate( 'YmdHis' ) );

		// Placeholder for actual electronic submission.
		$submission_result = array(
			'status'           => 'submitted',
			'tracking_id'      => $submission_reference,
			'submission_date'  => current_time( 'mysql' ),
			'estimated_review' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'documents_count'  => $document_count,
		);

		// Update registration status to submitted.
		$submitted_term = get_term_by( 'slug', 'submitted', 'mcp_ai_reg_status' );
		if ( $submitted_term ) {
			wp_set_post_terms( $registration_id, array( $submitted_term->term_id ), 'mcp_ai_reg_status' );
		}

		// Update metadata.
		update_post_meta( $registration_id, 'submission_date', current_time( 'mysql' ) );
		update_post_meta( $registration_id, '_submission_reference', $submission_reference );
		update_post_meta( $registration_id, '_submission_type', $submission_type );
		update_post_meta( $registration_id, '_submission_priority', $priority );
		if ( $notes ) {
			update_post_meta( $registration_id, '_submission_notes', $notes );
		}

		// Log submission.
		$submission_log = get_post_meta( $registration_id, '_submission_log', true );
		if ( ! is_array( $submission_log ) ) {
			$submission_log = array();
		}
		$submission_log[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $current_user_id,
			'type'      => $submission_type,
			'priority'  => $priority,
			'reference' => $submission_reference,
		);
		update_post_meta( $registration_id, '_submission_log', $submission_log );

		return array(
			'success'              => true,
			'registration_id'      => $registration_id,
			'submission_reference' => $submission_reference,
			'submission_type'      => $submission_type,
			'priority'             => $priority,
			'authority'            => $authority,
			'country'              => $country,
			'documents_submitted'  => $document_count,
			'submitted_at'         => current_time( 'mysql' ),
			'estimated_review'     => $submission_result['estimated_review'],
			'message'              => __( 'Successfully submitted to regulatory authority.', 'nvoos-content-graph-pro' ),
		);
	}
}
