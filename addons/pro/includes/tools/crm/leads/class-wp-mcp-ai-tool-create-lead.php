<?php
/**
 * Tool for creating leads in the CRM system.
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
 * Provides functionality to create leads in the CRM system.
 *
 * Validates email via WP_MCP_AI_Validator_Service, sets default lifecycle
 * stage from the CRM engine, initialises lead_score to zero, and assigns
 * a contact owner using the engine's routing strategy if none is specified.
 *
 * Fires before/after actions so other plugins can react to lead creation.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Create_Lead implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Create Lead tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'crm', 'leads' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_lead';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Lead', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new lead in the CRM system. Validates email, assigns a lifecycle stage from the configured default, sets initial lead score to 0, and routes the lead to a contact owner via the active routing strategy.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Email address of the lead (required).', 'mcp-ai-wpoos-pro' ),
				),
				'first_name'      => array(
					'type'        => 'string',
					'description' => __( 'First name of the lead.', 'mcp-ai-wpoos-pro' ),
				),
				'last_name'       => array(
					'type'        => 'string',
					'description' => __( 'Last name of the lead.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Job title of the lead.', 'mcp-ai-wpoos-pro' ),
				),
				'source'          => array(
					'type'        => 'string',
					'description' => __( 'Lead source (e.g. website, referral, event, cold_outreach).', 'mcp-ai-wpoos-pro' ),
				),
				'lifecycle_stage' => array(
					'type'        => 'string',
					'description' => __( 'Lifecycle stage. Defaults to the value configured in toolkit settings if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'contact_owner'   => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID for the contact owner. If omitted, auto-assigned via the active routing strategy.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'General notes about the lead.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Tags for categorisation.', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'sales_manager', 'sdr', 'business_development' ),
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
				__( 'You do not have permission to create leads.', 'mcp-ai-wpoos-pro' ),
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
				__( 'Email is required to create a lead.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Validate email via validator service.
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
		}

		// Resolve lifecycle stage: provided value → engine default → 'lead'.
		$lifecycle_stage = 'lead';
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$candidate = sanitize_key( $arguments['lifecycle_stage'] );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $candidate ) ) {
				$lifecycle_stage = $candidate;
			} else {
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
		} elseif ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings        = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$default_stage   = isset( $settings['default_lifecycle_stage'] ) ? $settings['default_lifecycle_stage'] : 'lead';
			$lifecycle_stage = sanitize_key( $default_stage );
		}

		// Resolve contact owner: provided value → routing engine → 0.
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

		// Build lead data payload.
		$lead_data = array(
			'email'           => $email,
			'first_name'      => isset( $arguments['first_name'] ) ? sanitize_text_field( $arguments['first_name'] ) : '',
			'last_name'       => isset( $arguments['last_name'] ) ? sanitize_text_field( $arguments['last_name'] ) : '',
			'phone'           => isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '',
			'company_name'    => isset( $arguments['company_name'] ) ? sanitize_text_field( $arguments['company_name'] ) : '',
			'job_title'       => isset( $arguments['job_title'] ) ? sanitize_text_field( $arguments['job_title'] ) : '',
			'source'          => isset( $arguments['source'] ) ? sanitize_text_field( $arguments['source'] ) : '',
			'lifecycle_stage' => $lifecycle_stage,
			'lead_score'      => 0,
			'contact_owner'   => $contact_owner,
			'notes'           => isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '',
			'tags'            => isset( $arguments['tags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['tags'] ) : array(),
		);

		/**
		 * Fires before a lead is created.
		 *
		 * @since 2.3.0
		 *
		 * @param array $lead_data  Lead data about to be persisted.
		 * @param array $arguments  Original tool arguments.
		 * @param array $context    Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_before_lead_create', $lead_data, $arguments, $context );

		// Persist via data store.
		$lead_id = $this->data_store->create_item( $lead_data );

		if ( is_wp_error( $lead_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_create_failed',
				$lead_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a lead is created.
		 *
		 * @since 2.3.0
		 *
		 * @param int   $lead_id    ID of the newly created lead.
		 * @param array $lead_data  Lead data that was persisted.
		 * @param array $arguments  Original tool arguments.
		 * @param array $context    Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_after_lead_create', $lead_id, $lead_data, $arguments, $context );

		// Record PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_created',
				'lead',
				$lead_id,
				array(
					'email'  => $email,
					'action' => 'create',
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %s: lead email */
				__( 'Lead created successfully for %s.', 'mcp-ai-wpoos-pro' ),
				esc_html( $email )
			),
			array(
				'lead_id'         => $lead_id,
				'email'           => esc_html( $email ),
				'lifecycle_stage' => esc_html( $lifecycle_stage ),
				'lead_score'      => 0,
				'contact_owner'   => $contact_owner,
				'storage_type'    => $this->data_store->get_storage_type(),
			)
		);
	}
}
