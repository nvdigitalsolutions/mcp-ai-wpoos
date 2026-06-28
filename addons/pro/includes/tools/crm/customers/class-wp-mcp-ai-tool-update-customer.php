<?php
/**
 * Tool for updating customers in the CRM.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update customer records.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Update_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && post_type_exists( 'mcp_ai_customer' );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'The Update Customer tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Customer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Update an existing customer record. Modify contact details, billing data, lifecycle stage, tags, or notes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the customer to update (required).', 'mcp-ai-wpoos-pro' ),
				),
				'email'           => array(
					'type'        => 'string',
					'description' => __( 'Updated email address.', 'mcp-ai-wpoos-pro' ),
				),
				'first_name'      => array(
					'type'        => 'string',
					'description' => __( 'Updated first name.', 'mcp-ai-wpoos-pro' ),
				),
				'last_name'       => array(
					'type'        => 'string',
					'description' => __( 'Updated last name.', 'mcp-ai-wpoos-pro' ),
				),
				'phone'           => array(
					'type'        => 'string',
					'description' => __( 'Updated phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'company_name'    => array(
					'type'        => 'string',
					'description' => __( 'Updated company name.', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'       => array(
					'type'        => 'string',
					'description' => __( 'Updated job title.', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Updated lifecycle stage.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'Updated owner user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'total_revenue'   => array(
					'type'        => 'number',
					'description' => __( 'Updated total revenue.', 'mcp-ai-wpoos-pro' ),
				),
				'lifetime_value'  => array(
					'type'        => 'number',
					'description' => __( 'Updated lifetime value (LTV).', 'mcp-ai-wpoos-pro' ),
				),
				'customer_since'  => array(
					'type'        => 'string',
					'description' => __( 'Updated customer-since date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'currency'        => array(
					'type'        => 'string',
					'description' => __( 'Updated currency code.', 'mcp-ai-wpoos-pro' ),
				),
				'source'          => array(
					'type'        => 'string',
					'description' => __( 'Updated source attribution.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Updated tags.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Updated notes.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'customer_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		if ( class_exists( 'WP_MCP_AI_CRM_Capabilities' ) ) {
			$map = WP_MCP_AI_CRM_Capabilities::get_map();
			return isset( $map['edit_customer'] ) ? $map['edit_customer'] : 'edit_posts';
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'account_executive', 'sales_ops' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_crm_toolkit_disabled',
				self::get_unavailable_reason(),
				array( 'status' => 403 )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		$customer_id = absint( $arguments['customer_id'] );
		$post        = get_post( $customer_id );

		if ( ! $post || 'mcp_ai_customer' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_customer_not_found',
				__( 'Customer not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Validate email if provided.
		if ( ! empty( $arguments['email'] ) && class_exists( 'WP_MCP_AI_Validator_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
			$validator   = new WP_MCP_AI_Validator_Service();
			$email_valid = $validator->is_email( $arguments['email'] );
			if ( is_wp_error( $email_valid ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_email',
					$email_valid->get_error_message(),
					array( 'status' => 400 )
				);
			}
		}

		// Validate phone if provided.
		if ( ! empty( $arguments['phone'] ) && class_exists( 'WP_MCP_AI_Validator_Service' ) ) {
			if ( ! isset( $validator ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
				$validator = new WP_MCP_AI_Validator_Service();
			}
			$phone_valid = $validator->is_phone_number( $arguments['phone'] );
			if ( is_wp_error( $phone_valid ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_phone',
					$phone_valid->get_error_message(),
					array( 'status' => 400 )
				);
			}
		}

		// Validate lifecycle stage if provided.
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$candidate = sanitize_key( $arguments['lifecycle_stage'] );
			if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) || ! WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $candidate ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_lifecycle_stage',
					sprintf(
						/* translators: %s: provided lifecycle stage */
						__( '"%s" is not a valid lifecycle stage.', 'mcp-ai-wpoos-pro' ),
						esc_html( $arguments['lifecycle_stage'] )
					),
					array( 'status' => 400 )
				);
			}
		}

		// Validate contact owner if provided.
		if ( isset( $arguments['contact_owner'] ) && '' !== $arguments['contact_owner'] ) {
			$owner = absint( $arguments['contact_owner'] );
			if ( $owner > 0 && ! user_can( $owner, 'edit_posts' ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_owner',
					__( 'The specified contact owner does not have sufficient permissions.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}
		}

		// --- Gate 1: Sanitise at entry, build update map ---

		$updates = array();

		$text_fields = array(
			'email'           => 'sanitize_email',
			'first_name'      => 'sanitize_text_field',
			'last_name'       => 'sanitize_text_field',
			'phone'           => 'sanitize_text_field',
			'company_name'    => 'sanitize_text_field',
			'job_title'       => 'sanitize_text_field',
			'lifecycle_stage' => 'sanitize_key',
			'customer_since'  => 'sanitize_text_field',
			'currency'        => 'sanitize_text_field',
			'source'          => 'sanitize_text_field',
		);

		foreach ( $text_fields as $field => $sanitizer ) {
			if ( isset( $arguments[ $field ] ) && '' !== $arguments[ $field ] ) {
				$updates[ $field ] = call_user_func( $sanitizer, $arguments[ $field ] );
			}
		}

		// Numeric fields.
		$numeric_fields = array( 'total_revenue', 'lifetime_value', 'contact_owner' );
		foreach ( $numeric_fields as $field ) {
			if ( isset( $arguments[ $field ] ) && '' !== $arguments[ $field ] ) {
				$updates[ $field ] = floatval( $arguments[ $field ] );
			}
		}

		// Tags.
		if ( isset( $arguments['tags'] ) ) {
			$updates['tags'] = array_map( 'sanitize_text_field', (array) $arguments['tags'] );
		}

		// Notes (allow rich text).
		if ( isset( $arguments['notes'] ) ) {
			$updates['notes'] = wp_kses_post( $arguments['notes'] );
		}

		if ( empty( $updates ) ) {
			return new WP_Error(
				'wp_mcp_ai_nothing_to_update',
				__( 'No fields provided to update.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// If first_name or last_name changed, rebuild the post title.
		$new_title = '';
		if ( isset( $updates['first_name'] ) || isset( $updates['last_name'] ) ) {
			$first     = isset( $updates['first_name'] ) ? $updates['first_name'] : get_post_meta( $customer_id, 'first_name', true );
			$last      = isset( $updates['last_name'] ) ? $updates['last_name'] : get_post_meta( $customer_id, 'last_name', true );
			$new_title = trim( $first . ' ' . $last );
		}
		if ( ! empty( $new_title ) ) {
			wp_update_post(
				array(
					'ID'         => $customer_id,
					'post_title' => $new_title,
				)
			);
		}

		// Persist meta updates.
		$updated_fields = array();
		foreach ( $updates as $meta_key => $value ) {
			$old = get_post_meta( $customer_id, $meta_key, true );
			update_post_meta( $customer_id, $meta_key, $value );
			$updated_fields[] = $meta_key;
		}

		/**
		 * Fires after a customer is updated.
		 *
		 * @since 2.6.0
		 *
		 * @param int   $customer_id    Customer ID.
		 * @param array $updates        Fields that were updated.
		 * @param array $arguments      Original tool arguments.
		 * @param array $context        Execution context.
		 */
		do_action( 'wp_mcp_ai_customer_updated', $customer_id, $updates, $arguments, $context );

		// Record in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'customer_updated',
				'customer',
				$customer_id,
				array(
					'action'         => 'update',
					'updated_fields' => $updated_fields,
					'email'          => get_post_meta( $customer_id, 'email', true ),
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: customer ID */
				__( 'Customer #%d updated successfully.', 'mcp-ai-wpoos-pro' ),
				$customer_id
			),
			array(
				'customer_id'    => $customer_id,
				'updated_fields' => $updated_fields,
			)
		);
	}
}
