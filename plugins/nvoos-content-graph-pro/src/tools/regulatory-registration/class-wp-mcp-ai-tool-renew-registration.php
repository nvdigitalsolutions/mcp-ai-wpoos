<?php
/**
 * WP_MCP_AI_Tool_Renew_Registration (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Renews a regulatory registration.
 */
class WP_MCP_AI_Tool_Renew_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'renew_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Renew Registration', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a renewal registration from an existing registration. Carries forward product and authority information, resets dates and status for renewal workflow.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Original registration ID to renew (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'expected_expiry_date' => array(
					'type'        => 'string',
					'description' => __( 'Expected new expiry date after renewal (YYYY-MM-DD, optional)', 'nvoos-content-graph-pro' ),
				),
				'notes'                => array(
					'type'        => 'string',
					'description' => __( 'Notes about the renewal (optional)', 'nvoos-content-graph-pro' ),
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
			'database-write',       // Writes to database.
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
		if ( empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration ID is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$original_registration_id = absint( $arguments['registration_id'] );

		// Verify original registration exists.
		$original_registration = get_post( $original_registration_id );
		if ( ! $original_registration || 'mcp_ai_registration' !== $original_registration->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Original registration not found.', 'nvoos-content-graph-pro' ),
			);
		}

		// Get original registration data.
		$product_id      = get_post_meta( $original_registration_id, 'product_id', true );
		$country         = get_post_meta( $original_registration_id, 'country', true );
		$authority       = get_post_meta( $original_registration_id, 'authority', true );
		$old_cos_number  = get_post_meta( $original_registration_id, 'cos_number', true );
		$old_expiry_date = get_post_meta( $original_registration_id, 'expiry_date', true );

		// Create renewal registration.
		$renewal_title = sprintf(
			'%s - %s Renewal',
			$original_registration->post_title,
			$country
		);

		$notes = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		if ( ! empty( $old_expiry_date ) ) {
			$notes .= sprintf(
				"\n\nRenewal of COS: %s (Expired: %s)",
				$old_cos_number,
				$old_expiry_date
			);
		}

		$renewal_data = array(
			'post_title'   => $renewal_title,
			'post_type'    => 'mcp_ai_registration',
			'post_status'  => 'publish',
			'post_content' => $notes,
		);

		$renewal_id = wp_insert_post( $renewal_data );

		if ( is_wp_error( $renewal_id ) ) {
			return array(
				'success' => false,
				'error'   => $renewal_id->get_error_message(),
			);
		}

		// Save renewal metadata.
		update_post_meta( $renewal_id, 'product_id', $product_id );
		update_post_meta( $renewal_id, 'country', $country );
		update_post_meta( $renewal_id, 'authority', $authority );
		update_post_meta( $renewal_id, 'registration_type', 'renewal' );
		update_post_meta( $renewal_id, 'original_registration_id', $original_registration_id );

		if ( ! empty( $arguments['expected_expiry_date'] ) ) {
			update_post_meta( $renewal_id, 'expected_expiry_date', sanitize_text_field( $arguments['expected_expiry_date'] ) );
		}

		// Set initial status to Draft.
		$draft_status = get_term_by( 'slug', 'draft', 'mcp_ai_reg_status' );
		if ( $draft_status ) {
			wp_set_object_terms( $renewal_id, $draft_status->term_id, 'mcp_ai_reg_status' );
		}

		// Update original registration to mark it as renewed.
		update_post_meta( $original_registration_id, 'renewed_by', $renewal_id );
		update_post_meta( $original_registration_id, 'renewal_date', current_time( 'mysql' ) );

		return array(
			'success'                  => true,
			'renewal_id'               => $renewal_id,
			'renewal_title'            => $renewal_title,
			'original_registration_id' => $original_registration_id,
			'product_id'               => $product_id,
			'country'                  => $country,
			'authority'                => $authority,
			'registration_type'        => 'renewal',
			'message'                  => __( 'Renewal registration created successfully.', 'nvoos-content-graph-pro' ),
		);
	}
}
