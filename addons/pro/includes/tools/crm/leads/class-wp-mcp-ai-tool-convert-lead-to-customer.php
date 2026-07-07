<?php
/**
 * Tool for converting a lead to a customer in the CRM system.
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
 * Provides functionality to convert a lead to a customer.
 *
 * Advances the lifecycle stage to 'customer', optionally creates a deal
 * record, and fires before/after hooks for automation integrations.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Convert_Lead_To_Customer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Data store instance for leads.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private $lead_data_store;

	/**
	 * Data store instance for deals (lazy-loaded).
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private $deal_data_store;

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
		return __( 'The Convert Lead to Customer tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->lead_data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
		}
	}

	/**
	 * Get the deal data store, lazy-loading on first access.
	 *
	 * @return WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private function get_deal_data_store() {
		if ( null === $this->deal_data_store && class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->deal_data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'deals' );
		}
		return $this->deal_data_store;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'convert_lead_to_customer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert a lead to a customer by creating a dedicated customer record, migrating data from the lead, advancing the lifecycle stage, and optionally creating a deal. Links the customer back to the originating lead for full traceability.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'lead_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to convert (required).', 'mcp-ai-wpoos-pro' ),
				),
				'create_deal' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to create a deal record upon conversion.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'deal_name'   => array(
					'type'        => 'string',
					'description' => __( 'Name of the deal to create. Defaults to "[Lead Name] - New Deal" if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'deal_amount' => array(
					'type'        => 'number',
					'description' => __( 'Deal amount. Required if create_deal is true.', 'mcp-ai-wpoos-pro' ),
				),
				'deal_stage'  => array(
					'type'        => 'string',
					'description' => __( 'Initial pipeline stage for the deal. Defaults to "qualification".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'qualification',
				),
				'deal_owner'  => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the deal owner. Defaults to the lead\'s contact owner.', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'sales_manager', 'account_executive', 'sdr' ),
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

		if ( ! $this->lead_data_store ) {
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
				__( 'You do not have permission to convert leads.', 'mcp-ai-wpoos-pro' ),
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

		$lead_id     = absint( $arguments['lead_id'] );
		$create_deal = ! empty( $arguments['create_deal'] );

		// Retrieve existing lead.
		$lead = $this->lead_data_store->get_item( $lead_id );
		if ( is_wp_error( $lead ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				$lead->get_error_message(),
				array( 'status' => 404 )
			);
		}

		// Validate the lead is not already a customer.
		$current_stage = isset( $lead['lifecycle_stage'] ) ? $lead['lifecycle_stage'] : '';
		if ( 'customer' === $current_stage ) {
			return new WP_Error(
				'wp_mcp_ai_already_customer',
				__( 'This lead is already a customer.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Build update data to advance lead lifecycle stage.
		$update_data = array(
			'lifecycle_stage' => 'customer',
		);

		/**
		 * Fires before a lead is converted to a customer.
		 *
		 * @since 2.3.0
		 *
		 * @param int   $lead_id      ID of the lead being converted.
		 * @param array $lead         Existing lead data.
		 * @param array $update_data  Fields being updated.
		 * @param array $arguments    Original tool arguments.
		 * @param array $context      Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_before_lead_create', $lead_id, $lead, $update_data, $arguments, $context );

		// --- Create a dedicated Customer CPT record ---
		$customer_id = 0;
		if ( post_type_exists( 'mcp_ai_customer' ) && class_exists( 'WP_MCP_AI_Tool_Create_Customer' ) ) {
			// Build customer data from the lead.
			$customer_tool = new WP_MCP_AI_Tool_Create_Customer();

			$customer_args = array(
				'email'           => isset( $lead['email'] ) ? $lead['email'] : '',
				'first_name'      => isset( $lead['first_name'] ) ? $lead['first_name'] : '',
				'last_name'       => isset( $lead['last_name'] ) ? $lead['last_name'] : '',
				'phone'           => isset( $lead['phone'] ) ? $lead['phone'] : '',
				'company_name'    => isset( $lead['company_name'] ) ? $lead['company_name'] : '',
				'job_title'       => isset( $lead['job_title'] ) ? $lead['job_title'] : '',
				'source'          => 'lead_conversion',
				'lifecycle_stage' => 'customer',
				'contact_owner'   => isset( $lead['contact_owner'] ) ? absint( $lead['contact_owner'] ) : 0,
				'source_lead_id'  => $lead_id,
				'tags'            => isset( $lead['tags'] ) ? $lead['tags'] : array(),
				'notes'           => isset( $lead['notes'] ) ? $lead['notes'] : '',
			);

			$customer_result = $customer_tool->execute( $customer_args, $context );

			if ( is_wp_error( $customer_result ) ) {
				// Customer CPT creation failed — still update the lead stage
				// but report the partial failure.
				$result = $this->lead_data_store->update_item( $lead_id, $update_data );

				return $this->format_success_response(
					sprintf(
						/* translators: %1$d: lead ID, %2$s: error message */
						__( 'Lead #%1$d lifecycle advanced to customer, but customer record creation failed: %2$s', 'mcp-ai-wpoos-pro' ),
						$lead_id,
						$customer_result->get_error_message()
					),
					array(
						'lead_id'          => $lead_id,
						'new_stage'        => 'customer',
						'customer_created' => false,
						'customer_error'   => $customer_result->get_error_message(),
						'storage_type'     => $this->lead_data_store->get_storage_type(),
					)
				);
			}

			// Extract customer ID from the success response.
			if ( isset( $customer_result['customer_id'] ) ) {
				$customer_id = absint( $customer_result['customer_id'] );
			}
		}

		// Persist the lifecycle stage change on the lead.
		$result = $this->lead_data_store->update_item( $lead_id, $update_data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_convert_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Optionally create a deal.
		$deal_id = 0;
		if ( $create_deal ) {
			$deal_store = $this->get_deal_data_store();
			if ( $deal_store ) {
				// Build deal name.
				$first_name = isset( $lead['first_name'] ) ? $lead['first_name'] : '';
				$last_name  = isset( $lead['last_name'] ) ? $lead['last_name'] : '';
				$lead_name  = trim( $first_name . ' ' . $last_name );

				$deal_name = isset( $arguments['deal_name'] ) && ! empty( $arguments['deal_name'] )
					? sanitize_text_field( $arguments['deal_name'] )
					: sprintf(
						/* translators: %s: lead name */
						__( '%s - New Deal', 'mcp-ai-wpoos-pro' ),
						$lead_name
					);

				// Resolve deal owner: provided → lead's contact_owner → current user.
				$deal_owner = $user_id;
				if ( ! empty( $arguments['deal_owner'] ) ) {
					$candidate = absint( $arguments['deal_owner'] );
					if ( user_can( $candidate, 'edit_posts' ) ) {
						$deal_owner = $candidate;
					}
				} elseif ( ! empty( $lead['contact_owner'] ) ) {
					$deal_owner = absint( $lead['contact_owner'] );
				}

				// Resolve deal stage.
				$deal_stage = isset( $arguments['deal_stage'] ) ? sanitize_key( $arguments['deal_stage'] ) : 'qualification';

				// Build deal data.
				$deal_data = array(
					'deal_name'    => $deal_name,
					'lead_id'      => $lead_id,
					'deal_owner'   => $deal_owner,
					'deal_stage'   => $deal_stage,
					'deal_amount'  => isset( $arguments['deal_amount'] ) ? floatval( $arguments['deal_amount'] ) : 0.0,
					'company_name' => isset( $lead['company_name'] ) ? $lead['company_name'] : '',
				);

				$deal_id = $deal_store->create_item( $deal_data );

				if ( is_wp_error( $deal_id ) ) {
					// Deal creation failed, but the lead conversion succeeded.
					// Return a warning level response.
					return $this->format_success_response(
						sprintf(
							/* translators: %d: lead ID */
							__( 'Lead #%1$d converted to customer, but deal creation failed: %2$s', 'mcp-ai-wpoos-pro' ),
							$lead_id,
							$deal_id->get_error_message()
						),
						array(
							'lead_id'      => $lead_id,
							'new_stage'    => 'customer',
							'deal_created' => false,
							'deal_error'   => $deal_id->get_error_message(),
							'customer_id'  => $customer_id,
							'storage_type' => $this->lead_data_store->get_storage_type(),
						)
					);
				}
			}
		}

		/**
		 * Fires after a lead is converted to a customer.
		 *
		 * @since 2.3.0
		 * @since 2.6.0 Added `$customer_id` parameter.
		 *
		 * @param int   $lead_id      ID of the converted lead.
		 * @param array $update_data  Fields that were updated.
		 * @param int   $deal_id      ID of the created deal (0 if no deal was created).
		 * @param int   $customer_id  ID of the created customer record (0 if creation was skipped).
		 * @param array $arguments    Original tool arguments.
		 * @param array $context      Execution context.
		 */
		do_action( 'wp_mcp_ai_crm_after_lead_create', $lead_id, $update_data, $deal_id, $customer_id, $arguments, $context );

		// Record lifecycle change and PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			$audit_meta = array(
				'previous_stage' => $current_stage,
				'new_stage'      => 'customer',
				'action'         => 'convert',
				'email'          => isset( $lead['email'] ) ? $lead['email'] : '',
			);
			if ( $deal_id > 0 ) {
				$audit_meta['deal_id'] = $deal_id;
			}
			if ( $customer_id > 0 ) {
				$audit_meta['customer_id'] = $customer_id;
			}
			WP_MCP_AI_CRM_Audit::record(
				'lead_converted',
				'lead',
				$lead_id,
				$audit_meta
			);
		}

		// --- Gate 2: Escape at exit ---
		$response_data = array(
			'lead_id'      => $lead_id,
			'new_stage'    => 'customer',
			'customer_id'  => $customer_id,
			'deal_created' => $create_deal && $deal_id > 0,
			'storage_type' => $this->lead_data_store->get_storage_type(),
		);

		if ( $deal_id > 0 ) {
			$response_data['deal_id'] = $deal_id;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %d: lead ID */
				__( 'Lead #%d converted to customer successfully.', 'mcp-ai-wpoos-pro' ),
				$lead_id
			),
			$response_data
		);
	}
}
