<?php
/**
 * WP_MCP_AI_Tool_Submit_Registration (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Marks registration as submitted.
 */
class WP_MCP_AI_Tool_Submit_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit Registration', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Marks a registration as submitted to the regulatory authority. Updates status to "Submitted" and records submission date.', 'nvoos-content-graph-pro' );
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
				'submission_date' => array(
					'type'        => 'string',
					'description' => __( 'Submission date (YYYY-MM-DD format, optional, defaults to today)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Submission notes (optional)', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit registrations.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );

		// Get the registration.
		$registration = get_post( $registration_id );

		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'nvoos-content-graph-pro' ) );
		}

		// Set submission date (default to today).
		$submission_date = ! empty( $arguments['submission_date'] )
			? sanitize_text_field( $arguments['submission_date'] )
			: current_time( 'Y-m-d' );

		// Update status to Submitted.
		$term = term_exists( 'Submitted', 'mcp_ai_reg_status' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Submitted', 'mcp_ai_reg_status' );
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $registration_id, absint( $term['term_id'] ), 'mcp_ai_reg_status' );
		}

		// Update submission date.
		update_post_meta( $registration_id, 'submission_date', $submission_date );

		// Update notes if provided.
		if ( ! empty( $arguments['notes'] ) ) {
			$post_content = get_post_field( 'post_content', $registration_id );
			$new_content  = $post_content . "\n\n" . sprintf(
				/* translators: 1: submission date, 2: notes */
				__( '[Submitted on %1$s] %2$s', 'nvoos-content-graph-pro' ),
				$submission_date,
				sanitize_textarea_field( $arguments['notes'] )
			);
			wp_update_post(
				array(
					'ID'           => $registration_id,
					'post_content' => $new_content,
				)
			);
		}

		return array(
			'success'         => true,
			'message'         => __( 'Registration marked as submitted successfully.', 'nvoos-content-graph-pro' ),
			'registration_id' => $registration_id,
			'status'          => 'Submitted',
			'submission_date' => $submission_date,
		);
	}
}
