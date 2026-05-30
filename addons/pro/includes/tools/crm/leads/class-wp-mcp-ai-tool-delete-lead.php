<?php
/**
 * Tool for deleting leads from the CRM system.
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
 * Provides functionality to delete leads from the CRM system.
 *
 * Requires a confirmation parameter to prevent accidental deletions
 * and enforces elevated capability checks.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Delete_Lead implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Delete Lead tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'delete_lead';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Lead', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Delete a lead from the CRM system. Requires an explicit confirmation parameter to prevent accidental deletions. This action cannot be undone.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'lead_id'               => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to delete (required).', 'mcp-ai-wpoos-pro' ),
				),
				'confirmation_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Must be explicitly set to true to confirm deletion. Set to "delete" (string) as an extra safety measure.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'lead_id', 'confirmation_required' ),
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
			return isset( $map['delete_lead'] ) ? $map['delete_lead'] : 'manage_options';
		}
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'destructive',
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
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'sales_manager', 'sales_ops' ),
			'risk_level'            => 'elevated',
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

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to delete leads.', 'mcp-ai-wpoos-pro' ),
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

		// --- Confirmation gate: prevent accidental deletions ---
		$confirmed = ! empty( $arguments['confirmation_required'] );
		if ( ! $confirmed ) {
			return new WP_Error(
				'wp_mcp_ai_delete_not_confirmed',
				__( 'Deletion not confirmed. Set confirmation_required to true to proceed with deleting this lead.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Verify lead exists before attempting deletion.
		$existing_lead = $this->data_store->get_item( $lead_id );
		if ( is_wp_error( $existing_lead ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				$existing_lead->get_error_message(),
				array( 'status' => 404 )
			);
		}

		// Capture lead email for the response message.
		$lead_email = isset( $existing_lead['email'] ) ? $existing_lead['email'] : '';

		// Perform deletion.
		$result = $this->data_store->delete_item( $lead_id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_delete_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Record destructive action in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_deleted',
				'lead',
				$lead_id,
				array(
					'email'  => $lead_email,
					'action' => 'delete',
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: lead ID */
				__( 'Lead #%d deleted successfully.', 'mcp-ai-wpoos-pro' ),
				$lead_id
			),
			array(
				'lead_id'       => $lead_id,
				'deleted_email' => esc_html( $lead_email ),
				'storage_type'  => $this->data_store->get_storage_type(),
			)
		);
	}
}
