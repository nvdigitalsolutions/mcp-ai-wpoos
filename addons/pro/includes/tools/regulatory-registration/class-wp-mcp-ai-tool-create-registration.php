<?php
/**
 * Tool for creating registration instances in the Regulatory Registration system.
 *
 * Allows AI assistants to create new registration records for products.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new registration instance.
 */
class WP_MCP_AI_Tool_Create_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Context_Restrictions_Interface {
	
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Registration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new registration instance for a product with a specific country/authority. Each product can have multiple registrations for different countries (e.g., Sri Lanka NMRA, UAE, Saudi SFDA).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Product ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'country'            => array(
					'type'        => 'string',
					'description' => __( 'Country/region (e.g., Sri Lanka, UAE, Saudi Arabia) (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'authority'          => array(
					'type'        => 'string',
					'description' => __( 'Regulatory authority (e.g., NMRA, MOHAP, SFDA) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'registration_type'  => array(
					'type'        => 'string',
					'description' => __( 'Registration type: new, renewal, or variation (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation' ),
					'default'     => 'new',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Initial status (optional, defaults to "draft")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'draft', 'pending_documents', 'ready_for_submission', 'submitted', 'under_review', 'approved', 'rejected', 'on_hold', 'renewal_due' ),
					'default'     => 'draft',
				),
				'cos_number'         => array(
					'type'        => 'string',
					'description' => __( 'COS/Certificate number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'submission_date'    => array(
					'type'        => 'string',
					'description' => __( 'Submission date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'approval_date'      => array(
					'type'        => 'string',
					'description' => __( 'Approval date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'expiry_date'        => array(
					'type'        => 'string',
					'description' => __( 'Registration expiry date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or comments (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'product_id', 'country' ),
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
			'state-changing',       // Modifies database state.
			'reversible',           // Can be undone by deleting the registration.
			'idempotent',           // Can be called multiple times safely (creates new registrations each time).
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['product_id'] ) || empty( $arguments['country'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Product ID and country are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$product_id = absint( $arguments['product_id'] );

		// Verify product exists.
		$product = get_post( $product_id );
		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_product', __( 'Invalid product ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$country           = sanitize_text_field( $arguments['country'] );
		$authority         = ! empty( $arguments['authority'] ) ? sanitize_text_field( $arguments['authority'] ) : '';
		$registration_type = ! empty( $arguments['registration_type'] ) ? sanitize_text_field( $arguments['registration_type'] ) : 'new';
		$status            = ! empty( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : 'draft';
		$cos_number        = ! empty( $arguments['cos_number'] ) ? sanitize_text_field( $arguments['cos_number'] ) : '';
		$submission_date   = ! empty( $arguments['submission_date'] ) ? sanitize_text_field( $arguments['submission_date'] ) : '';
		$approval_date     = ! empty( $arguments['approval_date'] ) ? sanitize_text_field( $arguments['approval_date'] ) : '';
		$expiry_date       = ! empty( $arguments['expiry_date'] ) ? sanitize_text_field( $arguments['expiry_date'] ) : '';
		$notes             = ! empty( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		// Create registration title.
		$registration_title = sprintf(
			'%s - %s Registration',
			$product->post_title,
			$country
		);

		// Create the post.
		$post_data = array(
			'post_title'   => $registration_title,
			'post_content' => $notes,
			'post_type'    => 'mcp_ai_registration',
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$registration_id = wp_insert_post( $post_data );

		if ( is_wp_error( $registration_id ) ) {
			return $registration_id;
		}

		// Save meta fields.
		update_post_meta( $registration_id, 'product_id', $product_id );
		update_post_meta( $registration_id, 'country', $country );
		if ( $authority ) {
			update_post_meta( $registration_id, 'authority', $authority );
		}
		update_post_meta( $registration_id, 'registration_type', $registration_type );
		if ( $cos_number ) {
			update_post_meta( $registration_id, 'cos_number', $cos_number );
		}
		if ( $submission_date ) {
			update_post_meta( $registration_id, 'submission_date', $submission_date );
		}
		if ( $approval_date ) {
			update_post_meta( $registration_id, 'approval_date', $approval_date );
		}
		if ( $expiry_date ) {
			update_post_meta( $registration_id, 'expiry_date', $expiry_date );
		}

		// Set status taxonomy.
		$status_slug = str_replace( '_', '-', strtolower( $status ) );
		$status_term = get_term_by( 'slug', $status_slug, 'mcp_ai_reg_status' );
		if ( ! $status_term ) {
			// Create the term if it doesn't exist.
			$status_name   = str_replace( '_', ' ', ucwords( $status, '_' ) );
			$status_result = wp_insert_term( $status_name, 'mcp_ai_reg_status' );
			if ( ! is_wp_error( $status_result ) ) {
				$status_term = get_term( $status_result['term_id'], 'mcp_ai_reg_status' );
			}
		}
		if ( $status_term && ! is_wp_error( $status_term ) ) {
			wp_set_object_terms( $registration_id, array( $status_term->term_id ), 'mcp_ai_reg_status' );
		}

		// Log activity.
		if ( function_exists( 'wp_mcp_ai_log_activity' ) ) {
			wp_mcp_ai_log_activity(
				'create_registration',
				sprintf( 'Created registration: %s (ID: %d)', $registration_title, $registration_id ),
				array(
					'registration_id' => $registration_id,
					'product_id'      => $product_id,
					'country'         => $country,
					'user_id'         => $current_user_id,
				)
			);
		}

		return array(
			'success'         => true,
			'registration_id' => $registration_id,
			'message'         => sprintf( __( 'Registration for "%s" in %s created successfully.', 'mcp-ai-wpoos-pro' ), $product->post_title, $country ),
			'edit_url'        => get_edit_post_link( $registration_id, 'raw' ),
		);
	}
}
