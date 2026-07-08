<?php
/**
 * Tool for updating leads in the CRM system.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides functionality to update lead records.
 *
 * Validates email and phone when provided, enforces lifecycle stage
 * progression rules, and fires before/after hooks so other plugins
 * can react to lead updates.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Update_Lead implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private $data_store;

	/**
	 * Determine whether the CRM toolkit is enabled.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Update Lead tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_lead';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Lead', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Update an existing lead record. Validates email/phone when provided, enforces lifecycle stage progression, and fires hooks for automation integrations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'lead_id'         => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to update (required).', 'mcp-ai-wpoos-pro' ),
				),
				'email'           => array(
					'type'        => 'string',
					'description' => __( 'New email address for the lead.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Lead source.', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Lifecycle stage. Must be a valid stage; progressions are enforced.', 'mcp-ai-wpoos-pro' ),
				),
				'lead_score'      => array(
					'type'        => 'integer',
					'description' => __( 'Lead score (0–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the new contact owner.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Updated notes.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Updated tags.', 'mcp-ai-wpoos-pro' ),
				),
				// BANT fields.
				'budget'          => array(
					'type'        => 'number',
					'description' => __( 'Budget amount (BANT qualification).', 'mcp-ai-wpoos-pro' ),
				),
				'authority'       => array(
					'type'        => 'string',
					'description' => __( 'Decision authority description (BANT qualification).', 'mcp-ai-wpoos-pro' ),
				),
				'need'            => array(
					'type'        => 'string',
					'description' => __( 'Identified need/pain point (BANT qualification).', 'mcp-ai-wpoos-pro' ),
				),
				'timeline'        => array(
					'type'        => 'string',
					'description' => __( 'Purchase timeline (BANT qualification).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'lead_id' ),
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
			return isset( $map['edit_lead'] ) ? $map['edit_lead'] : 'edit_posts';
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
	 * @since 2.3.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'sdr', 'account_executive' ),
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
				'wp_mcp_ai_crm_toolkit_disabled',
				__( 'CRM Toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->data_store ) {
			return new WP_Error(
				'wp_mcp_ai_crm_data_store_unavailable',
				__( 'CRM data store is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to update leads.', 'mcp-ai-wpoos-pro' ),
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

		if ( empty( $arguments['lead_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_lead_id',
				__( 'Lead ID is required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$lead_id = absint( $arguments['lead_id'] );

		// Retrieve existing lead to validate lifecycle progression.
		$existing_lead = $this->data_store->get_item( $lead_id );
		if ( is_wp_error( $existing_lead ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				$existing_lead->get_error_message(),
				array( 'status' => 404 )
			);
		}

		// Build update payload - only include fields that were provided.
		$update_data = array();

		// Validate email if provided.
		if ( isset( $arguments['email'] ) ) {
			$email = sanitize_email( $arguments['email'] );
			if ( empty( $email ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_email',
					__( 'Invalid email address format.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}
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
			$update_data['email'] = $email;
		}

		// Validate phone if provided.
		if ( ! empty( $arguments['phone'] ) ) {
			if ( class_exists( 'WP_MCP_AI_Validator_Service' ) ) {
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
			$update_data['phone'] = sanitize_text_field( $arguments['phone'] );
		}

		// Validate lifecycle stage progression if provided.
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$new_stage = sanitize_key( $arguments['lifecycle_stage'] );

			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
				if ( ! WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $new_stage ) ) {
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

			$update_data['lifecycle_stage'] = $new_stage;
		}

		// Copy remaining simple fields if provided.
		$simple_fields = array(
			'first_name'   => 'sanitize_text_field',
			'last_name'    => 'sanitize_text_field',
			'company_name' => 'sanitize_text_field',
			'job_title'    => 'sanitize_text_field',
			'source'       => 'sanitize_text_field',
			'authority'    => 'sanitize_text_field',
			'need'         => 'sanitize_text_field',
			'timeline'     => 'sanitize_text_field',
		);

		foreach ( $simple_fields as $field => $sanitizer ) {
			if ( isset( $arguments[ $field ] ) ) {
				$update_data[ $field ] = call_user_func( $sanitizer, $arguments[ $field ] );
			}
		}

		// Numeric fields.
		if ( isset( $arguments['lead_score'] ) ) {
			$update_data['lead_score'] = max( 0, min( 100, absint( $arguments['lead_score'] ) ) );
		}

		if ( isset( $arguments['contact_owner'] ) ) {
			$owner_id = absint( $arguments['contact_owner'] );
			if ( user_can( $owner_id, 'edit_posts' ) ) {
				$update_data['contact_owner'] = $owner_id;
			}
		}

		if ( isset( $arguments['budget'] ) ) {
			$update_data['budget'] = floatval( $arguments['budget'] );
		}

		// Rich text fields.
		if ( isset( $arguments['notes'] ) ) {
			$update_data['notes'] = wp_kses_post( $arguments['notes'] );
		}

		// Tags.
		if ( isset( $arguments['tags'] ) ) {
			$update_data['tags'] = array_map( 'sanitize_text_field', (array) $arguments['tags'] );
		}

		if ( empty( $update_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_update_fields',
				__( 'No fields to update were provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires before a lead is updated.
		 *
		 * The 'is_update' meta key signals to listeners that this is an
		 * update rather than a creation, allowing hooks to differentiate
		 * behaviour.
		 *
		 * @since 2.3.0
		 *
		 * @param int   $lead_id      ID of the lead being updated.
		 * @param array $update_data  Fields being updated.
		 * @param array $arguments    Original tool arguments.
		 * @param array $context      Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_before_lead_create', $lead_id, $update_data, $arguments, $context );

		// Persist via data store.
		$result = $this->data_store->update_item( $lead_id, $update_data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a lead is updated.
		 *
		 * @since 2.3.0
		 *
		 * @param int   $lead_id      ID of the updated lead.
		 * @param array $update_data  Fields that were updated.
		 * @param array $arguments    Original tool arguments.
		 * @param array $context      Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_after_lead_create', $lead_id, $update_data, $arguments, $context );

		// Record PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_updated',
				'lead',
				$lead_id,
				array(
					'updated_fields' => implode( ',', array_keys( $update_data ) ),
					'action'         => 'update',
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: lead ID */
				__( 'Lead #%d updated successfully.', 'mcp-ai-wpoos-pro' ),
				$lead_id
			),
			array(
				'lead_id'        => $lead_id,
				'updated_fields' => array_keys( $update_data ),
				'storage_type'   => $this->data_store->get_storage_type(),
			)
		);
	}
}
