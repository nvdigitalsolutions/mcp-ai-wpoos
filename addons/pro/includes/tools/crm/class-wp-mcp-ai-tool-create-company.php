<?php
/**
 * Tool for creating companies in the CRM system.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to create companies in the CRM.
 */
class WP_MCP_AI_Tool_Create_Company implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether Company CPT is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return post_type_exists( 'mcp_ai_company' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Create Company tool is disabled because the CRM Toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_company';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Company', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new company record in the CRM system with industry, size, location, and contact information. Useful for tracking target companies and prospects.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'company_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the company or organization.', 'mcp-ai-wpoos-pro' ),
				),
				'industry'     => array(
					'type'        => 'string',
					'description' => __( 'Industry sector (e.g., Technology, Healthcare, Finance, Manufacturing).', 'mcp-ai-wpoos-pro' ),
				),
				'company_size' => array(
					'type'        => 'string',
					'description' => __( 'Company size range: 1-10, 11-50, 51-200, 201-500, 501-1000, 1001-5000, or 5001+.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+' ),
				),
				'website'      => array(
					'type'        => 'string',
					'description' => __( 'Company website URL.', 'mcp-ai-wpoos-pro' ),
					'format'      => 'uri',
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Company description or overview.', 'mcp-ai-wpoos-pro' ),
				),
				'address'      => array(
					'type'        => 'string',
					'description' => __( 'Street address.', 'mcp-ai-wpoos-pro' ),
				),
				'city'         => array(
					'type'        => 'string',
					'description' => __( 'City.', 'mcp-ai-wpoos-pro' ),
				),
				'state'        => array(
					'type'        => 'string',
					'description' => __( 'State or province.', 'mcp-ai-wpoos-pro' ),
				),
				'zip'          => array(
					'type'        => 'string',
					'description' => __( 'ZIP or postal code.', 'mcp-ai-wpoos-pro' ),
				),
				'country'      => array(
					'type'        => 'string',
					'description' => __( 'Country.', 'mcp-ai-wpoos-pro' ),
				),
				'phone'        => array(
					'type'        => 'string',
					'description' => __( 'Main phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'revenue'      => array(
					'type'        => 'number',
					'description' => __( 'Annual revenue (numeric value).', 'mcp-ai-wpoos-pro' ),
				),
				'target_status' => array(
					'type'        => 'string',
					'description' => __( 'Target status for sales pipeline.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'prospect', 'target', 'in_discussion', 'client', 'not_interested' ),
					'default'     => 'prospect',
				),
				'linkedin'     => array(
					'type'        => 'string',
					'description' => __( 'LinkedIn company page URL.', 'mcp-ai-wpoos-pro' ),
					'format'      => 'uri',
				),
				'twitter'      => array(
					'type'        => 'string',
					'description' => __( 'Twitter/X handle (with or without @).', 'mcp-ai-wpoos-pro' ),
				),
				'tags'         => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated tags for categorization.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'        => array(
					'type'        => 'string',
					'description' => __( 'Research notes or additional information about this company.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'company_name', 'industry' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_company_cpt_missing', __( 'Company CPT is not registered. Enable CRM Toolkit in settings.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create companies.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['company_name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_company_name', __( 'Company name is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		if ( empty( $arguments['industry'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_industry', __( 'Industry is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$company_name = sanitize_text_field( $arguments['company_name'] );
		$description  = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		// Create the company post.
		$post_data = array(
			'post_title'   => $company_name,
			'post_content' => $description,
			'post_type'    => 'mcp_ai_company',
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_create_failed', __( 'Failed to create company.', 'mcp-ai-wpoos-pro' ) . ' ' . $post_id->get_error_message() );
		}

		// Save company metadata.
		$meta_fields = array(
			'_company_industry'      => 'industry',
			'_company_size'          => 'company_size',
			'_company_website'       => 'website',
			'_company_address'       => 'address',
			'_company_city'          => 'city',
			'_company_state'         => 'state',
			'_company_zip'           => 'zip',
			'_company_country'       => 'country',
			'_company_phone'         => 'phone',
			'_company_revenue'       => 'revenue',
			'_company_target_status' => 'target_status',
			'_company_linkedin'      => 'linkedin',
			'_company_twitter'       => 'twitter',
			'_company_tags'          => 'tags',
			'_company_notes'         => 'notes',
		);

		foreach ( $meta_fields as $meta_key => $arg_key ) {
			if ( isset( $arguments[ $arg_key ] ) && '' !== $arguments[ $arg_key ] ) {
				$value = sanitize_text_field( $arguments[ $arg_key ] );

				// Handle special cases.
				if ( 'notes' === $arg_key ) {
					$value = wp_kses_post( $arguments[ $arg_key ] );
				}

				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Trigger action for other plugins/automations.
		do_action( 'wp_mcp_ai_company_created', $post_id, $arguments, $context );

		return array(
			'success'     => true,
			'company_id'  => $post_id,
			'company_name' => $company_name,
			'industry'    => $arguments['industry'],
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'message'     => sprintf(
				/* translators: %s: Company name */
				__( 'Company "%s" created successfully in the CRM.', 'mcp-ai-wpoos-pro' ),
				$company_name
			),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'business_development', 'marketing_manager' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires user capabilities.
			'modifies-data',        // Creates new data.
			'local-only',           // No external API calls.
		);
	}
}
