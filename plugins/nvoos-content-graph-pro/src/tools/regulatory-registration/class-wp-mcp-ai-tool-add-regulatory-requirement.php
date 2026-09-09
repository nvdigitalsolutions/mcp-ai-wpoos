<?php
/**
 * WP_MCP_AI_Tool_Add_Regulatory_Requirement (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Adds a regulatory requirement.
 */
class WP_MCP_AI_Tool_Add_Regulatory_Requirement implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_regulatory_requirement';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Regulatory Requirement', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new regulatory requirement for a specific country/authority. Used to define what documents, tests, or compliance items are required for registration.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Requirement title (required)', 'nvoos-content-graph-pro' ),
				),
				'country'          => array(
					'type'        => 'string',
					'description' => __( 'Country code (required, e.g. LK, AE, SA)', 'nvoos-content-graph-pro' ),
				),
				'authority'        => array(
					'type'        => 'string',
					'description' => __( 'Regulatory authority name (required, e.g. NMRA, MOHAP, SFDA)', 'nvoos-content-graph-pro' ),
				),
				'requirement_type' => array(
					'type'        => 'string',
					'description' => __( 'Type: document, test, certification, ingredient_restriction, other (required)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'document', 'test', 'certification', 'ingredient_restriction', 'other' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the requirement (optional)', 'nvoos-content-graph-pro' ),
				),
				'is_mandatory'     => array(
					'type'        => 'boolean',
					'description' => __( 'Is this requirement mandatory? (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'product_category' => array(
					'type'        => 'string',
					'description' => __( 'Applicable product category (optional, leave empty for all)', 'nvoos-content-graph-pro' ),
				),
				'effective_date'   => array(
					'type'        => 'string',
					'description' => __( 'Date requirement becomes effective (YYYY-MM-DD, optional)', 'nvoos-content-graph-pro' ),
				),
				'reference_url'    => array(
					'type'        => 'string',
					'description' => __( 'URL to official regulation/guideline (optional)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'title', 'country', 'authority', 'requirement_type' ),
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
			'admin-required',       // Requires admin privileges.
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
		$required_fields = array( 'title', 'country', 'authority', 'requirement_type' );
		foreach ( $required_fields as $field ) {
			if ( empty( $arguments[ $field ] ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: field name */
						__( '%s is required.', 'nvoos-content-graph-pro' ),
						ucfirst( str_replace( '_', ' ', $field ) )
					),
				);
			}
		}

		// Create requirement post.
		$requirement_data = array(
			'post_title'   => sanitize_text_field( $arguments['title'] ),
			'post_type'    => 'mcp_ai_requirement',
			'post_status'  => 'publish',
			'post_content' => ! empty( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '',
		);

		$requirement_id = wp_insert_post( $requirement_data );

		if ( is_wp_error( $requirement_id ) ) {
			return array(
				'success' => false,
				'error'   => $requirement_id->get_error_message(),
			);
		}

		// Save metadata.
		update_post_meta( $requirement_id, 'country', sanitize_text_field( $arguments['country'] ) );
		update_post_meta( $requirement_id, 'authority', sanitize_text_field( $arguments['authority'] ) );
		update_post_meta( $requirement_id, 'requirement_type', sanitize_text_field( $arguments['requirement_type'] ) );
		update_post_meta( $requirement_id, 'is_mandatory', ! empty( $arguments['is_mandatory'] ) );

		if ( ! empty( $arguments['product_category'] ) ) {
			update_post_meta( $requirement_id, 'product_category', sanitize_text_field( $arguments['product_category'] ) );
		}

		if ( ! empty( $arguments['effective_date'] ) ) {
			update_post_meta( $requirement_id, 'effective_date', sanitize_text_field( $arguments['effective_date'] ) );
		}

		if ( ! empty( $arguments['reference_url'] ) ) {
			update_post_meta( $requirement_id, 'reference_url', esc_url_raw( $arguments['reference_url'] ) );
		}

		return array(
			'success'          => true,
			'requirement_id'   => $requirement_id,
			'country'          => $arguments['country'],
			'authority'        => $arguments['authority'],
			'requirement_type' => $arguments['requirement_type'],
			'is_mandatory'     => ! empty( $arguments['is_mandatory'] ),
			'message'          => __( 'Regulatory requirement created successfully.', 'nvoos-content-graph-pro' ),
		);
	}
}
