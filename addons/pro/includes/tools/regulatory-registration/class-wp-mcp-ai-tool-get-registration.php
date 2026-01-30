<?php
/**
 * Tool for getting a single registration in the Regulatory Registration system.
 *
 * Allows AI assistants to retrieve detailed registration information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets a regulatory registration by ID.
 */
class WP_MCP_AI_Tool_Get_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Registration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific registration including status, dates, documents, and product details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_product'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include product details (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'include_documents' => array(
					'type'        => 'boolean',
					'description' => __( 'Include related documents (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id   = absint( $arguments['registration_id'] );
		$include_product   = ! empty( $arguments['include_product'] );
		$include_documents = ! empty( $arguments['include_documents'] );

		// Get the registration.
		$registration = get_post( $registration_id );

		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build registration data.
		$registration_data = array(
			'id'                => $registration->ID,
			'title'             => $registration->post_title,
			'notes'             => $registration->post_content,
			'product_id'        => absint( get_post_meta( $registration->ID, 'product_id', true ) ),
			'country'           => get_post_meta( $registration->ID, 'country', true ),
			'authority'         => get_post_meta( $registration->ID, 'authority', true ),
			'registration_type' => get_post_meta( $registration->ID, 'registration_type', true ),
			'cos_number'        => get_post_meta( $registration->ID, 'cos_number', true ),
			'submission_date'   => get_post_meta( $registration->ID, 'submission_date', true ),
			'approval_date'     => get_post_meta( $registration->ID, 'approval_date', true ),
			'expiry_date'       => get_post_meta( $registration->ID, 'expiry_date', true ),
			'created_date'      => $registration->post_date,
			'modified_date'     => $registration->post_modified,
		);

		// Get status.
		$statuses = wp_get_post_terms( $registration->ID, 'mcp_ai_reg_status' );
		if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
			$registration_data['status'] = $statuses[0]->name;
		}

		// Calculate days to expiry if expiry date set.
		if ( ! empty( $registration_data['expiry_date'] ) ) {
			$expiry                              = strtotime( $registration_data['expiry_date'] );
			$today                               = time();
			$days_to_expiry                      = floor( ( $expiry - $today ) / DAY_IN_SECONDS );
			$registration_data['days_to_expiry'] = $days_to_expiry;
			$registration_data['is_expired']     = $days_to_expiry < 0;
			$registration_data['expiring_soon']  = $days_to_expiry >= 0 && $days_to_expiry <= 90;
		}

		// Include product details if requested.
		if ( $include_product && $registration_data['product_id'] ) {
			$product = get_post( $registration_data['product_id'] );
			if ( $product && 'mcp_ai_reg_product' === $product->post_type ) {
				$registration_data['product'] = array(
					'id'           => $product->ID,
					'name'         => $product->post_title,
					'brand'        => get_post_meta( $product->ID, 'brand', true ),
					'manufacturer' => get_post_meta( $product->ID, 'manufacturer', true ),
				);
			}
		}

		// Include documents if requested.
		if ( $include_documents ) {
			$documents_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_reg_document',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_query'     => array(
						array(
							'key'   => 'registration_id',
							'value' => $registration_id,
						),
					),
				)
			);

			$documents = array();

			if ( $documents_query->have_posts() ) {
				foreach ( $documents_query->posts as $doc_post ) {
					$documents[] = array(
						'id'          => $doc_post->ID,
						'title'       => $doc_post->post_title,
						'type'        => get_post_meta( $doc_post->ID, 'document_type', true ),
						'status'      => get_post_meta( $doc_post->ID, 'status', true ),
						'issue_date'  => get_post_meta( $doc_post->ID, 'issue_date', true ),
						'expiry_date' => get_post_meta( $doc_post->ID, 'expiry_date', true ),
					);
				}
			}

			$registration_data['documents']      = $documents;
			$registration_data['document_count'] = count( $documents );
		}

		return array(
			'success'      => true,
			'registration' => $registration_data,
		);
	}
}
