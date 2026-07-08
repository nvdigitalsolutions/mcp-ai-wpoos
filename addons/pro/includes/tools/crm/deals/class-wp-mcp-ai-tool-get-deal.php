<?php
/**
 * Tool for retrieving a single CRM deal/opportunity.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Deal Tool.
 *
 * Retrieves a full deal record with:
 * - Stage label and colour
 * - Win probability
 * - Associated activities count
 * - Weighted amount
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Get_Deal implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Data store instance for deals.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store
	 */
	private $data_store;

	/**
	 * Determine whether the tool is available.
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
		return __( 'The Get Deal tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$this->data_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'deals' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_deal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Deal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve a single CRM deal/opportunity with full details including stage label, win probability, weighted amount, and activity count.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_id' => array(
					'type'        => 'integer',
					'description' => __( 'Deal ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_id' ),
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
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! $this->data_store ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gateway 1: Sanitize inputs.
		$deal_id = isset( $arguments['deal_id'] ) ? absint( $arguments['deal_id'] ) : 0;

		if ( ! $deal_id ) {
			return new WP_Error(
				'invalid_deal',
				__( 'A valid deal ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Retrieve deal.
		$deal = $this->data_store->get_item( $deal_id );

		if ( is_wp_error( $deal ) ) {
			return $deal;
		}

		// Enrich with stage metadata.
		$stage_id = isset( $deal['pipeline_stage'] ) ? $deal['pipeline_stage'] : '';
		if ( $stage_id ) {
			$stage_def = WP_MCP_AI_CRM_Pipeline_Stages::get_stage( $stage_id );
			if ( $stage_def ) {
				$deal['stage_label']     = isset( $stage_def['label'] ) ? $stage_def['label'] : '';
				$deal['stage_color']     = isset( $stage_def['color'] ) ? $stage_def['color'] : '';
				$deal['win_probability'] = WP_MCP_AI_CRM_Pipeline_Stages::probability( $stage_id );
				$deal['is_won']          = ! empty( $stage_def['is_won'] );
				$deal['is_lost']         = ! empty( $stage_def['is_lost'] );
			}
		}

		// Calculate weighted amount.
		$amount                  = isset( $deal['amount'] ) ? (float) $deal['amount'] : 0.0;
		$probability             = isset( $deal['win_probability'] ) ? (float) $deal['win_probability'] : 0.0;
		$deal['weighted_amount'] = round( $amount * $probability, 2 );

		// Count associated activities.
		$activities_count = 0;
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$activities_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'activities' );
			if ( $activities_store ) {
				$activities = $activities_store->query_items(
					array(
						'deal_id'  => $deal_id,
						'per_page' => 1,
					)
				);
				if ( is_array( $activities ) ) {
					$activities_count = count( $activities );
				}
			}
		}
		$deal['activities_count'] = $activities_count;

		// Format currency.
		if ( isset( $deal['currency'] ) && isset( $deal['amount'] ) ) {
			$deal['formatted_amount'] = WP_MCP_AI_CRM_Engine::format_currency(
				$deal['amount'],
				$deal['currency']
			);
		}

		// Enrich with lead summary if linked.
		$lead_id = isset( $deal['lead_id'] ) ? absint( $deal['lead_id'] ) : 0;
		if ( $lead_id && class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$lead_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
			if ( $lead_store ) {
				$lead = $lead_store->get_item( $lead_id );
				if ( ! is_wp_error( $lead ) && is_array( $lead ) ) {
					$deal['lead_summary'] = array(
						'lead_id'    => $lead_id,
						'first_name' => isset( $lead['first_name'] ) ? $lead['first_name'] : '',
						'last_name'  => isset( $lead['last_name'] ) ? $lead['last_name'] : '',
						'email'      => isset( $lead['email'] ) ? $lead['email'] : '',
					);
				}
			}
		}

		// Record PII access in audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_viewed',
				'deal',
				$deal_id,
				array(
					'action' => 'read',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal retrieved successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal'         => $deal,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
