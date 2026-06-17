<?php
/**
 * Tool for creating customers in the CRM system.
 *
 * Creates a new `mcp_ai_customer` record with full contact details,
 * billing/revenue data, and source attribution.  Used both standalone
 * and by `convert_lead_to_customer` during lead conversion.
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
 * Provides functionality to create customers in the CRM.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Create_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether the Customer CPT is available.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && post_type_exists( 'mcp_ai_customer' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.6.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! post_type_exists( 'mcp_ai_customer' ) ) {
			return __( 'Customer CPT is not registered. Enable the CRM Toolkit in settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Create Customer tool requires the CRM Toolkit to be enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Customer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new customer record in the CRM system with contact details, company info, billing data, and source attribution. Use this for post-conversion customer management.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'email'           => array(
					'type'        => 'string',
					'description' => __( 'Email address of the customer (required).', 'mcp-ai-wpoos-pro' ),
				),
				'first_name'      => array(
					'type'        => 'string',
					'description' => __( 'First name.', 'mcp-ai-wpoos-pro' ),
				),
				'last_name'       => array(
					'type'        => 'string',
					'description' => __( 'Last name.', 'mcp-ai-wpoos-pro' ),
				),
				'phone'           => array(
					'type'        => 'string',
					'description' => __( 'Phone number in E.164 format.', 'mcp-ai-wpoos-pro' ),
				),
				'company_name'    => array(
					'type'        => 'string',
					'description' => __( 'Company or organisation name.', 'mcp-ai-wpoos-pro' ),
				),
				'job_title'       => array(
					'type'        => 'string',
					'description' => __( 'Job title.', 'mcp-ai-wpoos-pro' ),
				),
				'source'          => array(
					'type'        => 'string',
					'description' => __( 'Customer source (e.g. website, referral, lead_conversion).', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Lifecycle stage. Defaults to "customer".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'customer',
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID for the contact owner.', 'mcp-ai-wpoos-pro' ),
				),
				'source_lead_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the originating lead, if converted from one.', 'mcp-ai-wpoos-pro' ),
				),
				'total_revenue'   => array(
					'type'        => 'number',
					'description' => __( 'Total revenue from this customer.', 'mcp-ai-wpoos-pro' ),
				),
				'lifetime_value'  => array(
					'type'        => 'number',
					'description' => __( 'Estimated lifetime value (LTV).', 'mcp-ai-wpoos-pro' ),
				),
				'customer_since'  => array(
					'type'        => 'string',
					'description' => __( 'Date the contact became a customer (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'currency'        => array(
					'type'        => 'string',
					'description' => __( 'ISO 4217 currency code. Defaults to toolkit setting.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Tags for categorisation.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'General notes about the customer.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'email' ),
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 2.6.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'account_executive', 'business_development' ),
			'risk_level'            => 'standard',
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
			return new WP_Error(
				'wp_mcp_ai_customer_cpt_missing',
				self::get_unavailable_reason(),
				array( 'status' => 403 )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create customers.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// --- Gate 1: Sanitise at entry ---

		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_email',
				__( 'Email is required to create a customer.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate email.
		if ( class_exists( 'WP_MCP_AI_Validator_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
			$validator   = new WP_MCP_AI_Validator_Service();
			$email_valid = $validator->is_email( $email );
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

		// Resolve lifecycle stage.
		$lifecycle_stage = 'customer';
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$candidate = sanitize_key( $arguments['lifecycle_stage'] );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $candidate ) ) {
				$lifecycle_stage = $candidate;
			}
		}

		// Resolve contact owner: provided → routing engine → 0.
		$contact_owner = 0;
		if ( ! empty( $arguments['contact_owner'] ) ) {
			$contact_owner = absint( $arguments['contact_owner'] );
			if ( ! user_can( $contact_owner, 'edit_posts' ) ) {
				$contact_owner = 0;
			}
		}
		if ( 0 === $contact_owner && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$contact_owner = WP_MCP_AI_CRM_Engine::get_next_owner();
		}

		// Build display title from name fields.
		$first_name = isset( $arguments['first_name'] ) ? sanitize_text_field( $arguments['first_name'] ) : '';
		$last_name  = isset( $arguments['last_name'] ) ? sanitize_text_field( $arguments['last_name'] ) : '';
		$title      = trim( $first_name . ' ' . $last_name );
		if ( empty( $title ) ) {
			$title = $email;
		}

		// Resolve currency.
		$currency = isset( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : '';
		if ( empty( $currency ) && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$currency = $settings['default_currency'];
		}

		// Resolve customer_since: provided → today.
		$customer_since = isset( $arguments['customer_since'] ) ? sanitize_text_field( $arguments['customer_since'] ) : '';
		if ( empty( $customer_since ) ) {
			$customer_since = wp_date( 'Y-m-d' );
		}

		// Create the customer post.
		$post_data = array(
			'post_title'  => $title,
			'post_type'   => 'mcp_ai_customer',
			'post_status' => 'publish',
			'post_author' => $user_id,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_customer_create_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Save meta fields.
		$meta_fields = array(
			'email'           => $email,
			'first_name'      => $first_name,
			'last_name'       => $last_name,
			'phone'           => isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '',
			'company_name'    => isset( $arguments['company_name'] ) ? sanitize_text_field( $arguments['company_name'] ) : '',
			'job_title'       => isset( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '',
			'source'          => isset( $arguments['source'] ) ? sanitize_text_field( $arguments['source'] ) : 'manual',
			'lifecycle_stage' => $lifecycle_stage,
			'contact_owner'   => $contact_owner,
			'source_lead_id'  => isset( $arguments['source_lead_id'] ) ? absint( $arguments['source_lead_id'] ) : 0,
			'total_revenue'   => isset( $arguments['total_revenue'] ) ? floatval( $arguments['total_revenue'] ) : 0,
			'lifetime_value'  => isset( $arguments['lifetime_value'] ) ? floatval( $arguments['lifetime_value'] ) : 0,
			'customer_since'  => $customer_since,
			'currency'        => $currency,
			'tags'            => isset( $arguments['tags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['tags'] ) : array(),
			'notes'           => isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '',
		);

		foreach ( $meta_fields as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, $value );
		}

		/**
		 * Fires after a customer is created.
		 *
		 * @since 2.6.0
		 *
		 * @param int   $post_id    ID of the newly created customer.
		 * @param array $meta_fields Customer meta that was persisted.
		 * @param array $arguments   Original tool arguments.
		 * @param array $context     Execution context.
		 */
		do_action( 'wp_mcp_ai_customer_created', $post_id, $meta_fields, $arguments, $context );

		// Record in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'customer_created',
				'customer',
				$post_id,
				array(
					'email'  => $email,
					'action' => 'create',
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %s: customer email */
				__( 'Customer created successfully for %s.', 'mcp-ai-wpoos-pro' ),
				esc_html( $email )
			),
			array(
				'customer_id'     => $post_id,
				'email'           => esc_html( $email ),
				'lifecycle_stage' => esc_html( $lifecycle_stage ),
				'contact_owner'   => $contact_owner,
				'customer_since'  => esc_html( $customer_since ),
			)
		);
	}
}
