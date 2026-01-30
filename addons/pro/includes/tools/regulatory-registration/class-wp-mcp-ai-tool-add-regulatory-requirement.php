<?php
/**
 * Tool for adding regulatory requirements in the Regulatory Registration system.
 *
 * Allows AI assistants to create country-specific regulatory requirements.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Add Regulatory Requirement', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new regulatory requirement for a specific country/authority. Used to define what documents, tests, or compliance items are required for registration.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Requirement title (required)', 'mcp-ai-wpoos-pro' ),
				),
				'country'          => array(
					'type'        => 'string',
					'description' => __( 'Country code (required, e.g. LK, AE, SA)', 'mcp-ai-wpoos-pro' ),
				),
				'authority'        => array(
					'type'        => 'string',
					'description' => __( 'Regulatory authority name (required, e.g. NMRA, MOHAP, SFDA)', 'mcp-ai-wpoos-pro' ),
				),
				'requirement_type' => array(
					'type'        => 'string',
					'description' => __( 'Type: document, test, certification, ingredient_restriction, other (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'document', 'test', 'certification', 'ingredient_restriction', 'other' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the requirement (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'is_mandatory'     => array(
					'type'        => 'boolean',
					'description' => __( 'Is this requirement mandatory? (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'product_category' => array(
					'type'        => 'string',
					'description' => __( 'Applicable product category (optional, leave empty for all)', 'mcp-ai-wpoos-pro' ),
				),
				'effective_date'   => array(
					'type'        => 'string',
					'description' => __( 'Date requirement becomes effective (YYYY-MM-DD, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'reference_url'    => array(
					'type'        => 'string',
					'description' => __( 'URL to official regulation/guideline (optional)', 'mcp-ai-wpoos-pro' ),
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
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
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
						__( '%s is required.', 'mcp-ai-wpoos-pro' ),
						ucfirst( str_replace( '_', ' ', $field ) )
					),
				);
			}
		}

		// Create requirement post.
		$requirement_data = array(
			'post_title'   => sanitize_text_field( $arguments['title'] ),
			'post_type'    => 'mcp_ai_reg_requirement',
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
			'message'          => __( 'Regulatory requirement created successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
