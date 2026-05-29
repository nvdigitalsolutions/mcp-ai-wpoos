<?php
/**
 * Tool for retrieving a single lead from the CRM system.
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
 * Provides functionality to retrieve a full lead record.
 *
 * Returns the complete lead payload including BANT qualification fields,
 * lifecycle stage, lead score with label, associated activities, and
 * related deals.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Get_Lead implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Get Lead tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'get_lead';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Lead', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve a full lead record with BANT qualification fields, lifecycle stage, lead score with label, associated activities, and related deals.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'lead_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the lead to retrieve (required).', 'mcp-ai-wpoos-pro' ),
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
			return isset( $map['view_lead'] ) ? $map['view_lead'] : 'edit_posts';
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
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
			'profession_tags'       => array( 'sales_manager', 'sdr', 'account_executive', 'crm_viewer' ),
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

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view leads.', 'mcp-ai-wpoos-pro' ),
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

		// Retrieve lead via data store.
		$lead = $this->data_store->get_item( $lead_id );

		if ( is_wp_error( $lead ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				$lead->get_error_message(),
				array( 'status' => 404 )
			);
		}

		if ( ! is_array( $lead ) ) {
			return new WP_Error(
				'wp_mcp_ai_lead_not_found',
				__( 'Lead not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Enrich with score label.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$score               = isset( $lead['lead_score'] ) ? $lead['lead_score'] : null;
			$lead['score_label'] = WP_MCP_AI_CRM_Engine::score_label( $score );
		}

		// Enrich with BANT qualification fields if present.
		$bant_fields = array( 'budget', 'authority', 'need', 'timeline' );
		foreach ( $bant_fields as $field ) {
			if ( ! isset( $lead[ $field ] ) ) {
				$lead[ $field ] = null;
			}
		}

		// Enrich with activities if the data store supports it.
		if ( method_exists( $this->data_store, 'get_related_items' ) ) {
			$activities = $this->data_store->get_related_items( $lead_id, 'activities' );
			if ( ! is_wp_error( $activities ) ) {
				$lead['activities'] = $activities;
			}
		}

		// Enrich with deals if the data store supports it.
		if ( method_exists( $this->data_store, 'get_related_items' ) ) {
			$deals = $this->data_store->get_related_items( $lead_id, 'deals' );
			if ( ! is_wp_error( $deals ) ) {
				$lead['deals'] = $deals;
			}
		}

		// Record PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_viewed',
				'lead',
				$lead_id,
				array(
					'email'  => isset( $lead['email'] ) ? $lead['email'] : '',
					'action' => 'read',
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %s: lead email or ID */
				__( 'Lead #%d retrieved successfully.', 'mcp-ai-wpoos-pro' ),
				$lead_id
			),
			array(
				'lead'         => $lead,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
