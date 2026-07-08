<?php
/**
 * Tool for listing CRM deals/opportunities.
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
 * List Deals Tool.
 *
 * Lists and filters CRM deals with:
 * - Stage, lead, owner, and amount-range filters
 * - Expected close date range filtering
 * - Pagination support
 * - Win probability and weighted amount calculations
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_List_Deals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Data store instance.
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
		return __( 'The List Deals tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'list_deals';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Deals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List and filter CRM deals/opportunities by stage, lead, owner, amount range, and close date. Returns deals with win probability and weighted amounts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pipeline_stage'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by pipeline stage slug', 'mcp-ai-wpoos-pro' ),
				),
				'lead_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Filter by lead ID', 'mcp-ai-wpoos-pro' ),
				),
				'deal_owner'      => array(
					'type'        => 'integer',
					'description' => __( 'Filter by deal owner user ID', 'mcp-ai-wpoos-pro' ),
				),
				'amount_min'      => array(
					'type'        => 'number',
					'description' => __( 'Minimum deal amount', 'mcp-ai-wpoos-pro' ),
				),
				'amount_max'      => array(
					'type'        => 'number',
					'description' => __( 'Maximum deal amount', 'mcp-ai-wpoos-pro' ),
				),
				'close_date_from' => array(
					'type'        => 'string',
					'description' => __( 'Filter deals with expected close date on or after (Y-m-d)', 'mcp-ai-wpoos-pro' ),
				),
				'close_date_to'   => array(
					'type'        => 'string',
					'description' => __( 'Filter deals with expected close date on or before (Y-m-d)', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'        => array(
					'type'        => 'integer',
					'description' => __( 'Results per page', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
				),
				'page'            => array(
					'type'        => 'integer',
					'description' => __( 'Page number', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
			),
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
		$pipeline_stage  = isset( $arguments['pipeline_stage'] ) ? sanitize_key( $arguments['pipeline_stage'] ) : '';
		$lead_id         = isset( $arguments['lead_id'] ) ? absint( $arguments['lead_id'] ) : 0;
		$deal_owner      = isset( $arguments['deal_owner'] ) ? absint( $arguments['deal_owner'] ) : 0;
		$amount_min      = isset( $arguments['amount_min'] ) ? (float) $arguments['amount_min'] : 0.0;
		$amount_max      = isset( $arguments['amount_max'] ) ? (float) $arguments['amount_max'] : 0.0;
		$close_date_from = isset( $arguments['close_date_from'] ) ? sanitize_text_field( $arguments['close_date_from'] ) : '';
		$close_date_to   = isset( $arguments['close_date_to'] ) ? sanitize_text_field( $arguments['close_date_to'] ) : '';
		$per_page        = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page            = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Build query args.
		$query_args = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		if ( $pipeline_stage ) {
			$query_args['pipeline_stage'] = $pipeline_stage;
		}
		if ( $lead_id ) {
			$query_args['lead_id'] = $lead_id;
		}
		if ( $deal_owner ) {
			$query_args['deal_owner'] = $deal_owner;
		}
		if ( $amount_min > 0 ) {
			$query_args['amount_min'] = $amount_min;
		}
		if ( $amount_max > 0 ) {
			$query_args['amount_max'] = $amount_max;
		}
		if ( $close_date_from ) {
			$query_args['close_date_from'] = $close_date_from;
		}
		if ( $close_date_to ) {
			$query_args['close_date_to'] = $close_date_to;
		}

		// Query deals from data store.
		$deals = $this->data_store->query_items( $query_args );

		// Enrich deal records with computed fields.
		if ( is_array( $deals ) ) {
			foreach ( $deals as &$deal ) {
				$amount      = isset( $deal['amount'] ) ? (float) $deal['amount'] : 0.0;
				$probability = isset( $deal['win_probability'] ) ? (float) $deal['win_probability'] : 0.0;

				if ( ! isset( $deal['win_probability'] ) ) {
					$stage_id                = isset( $deal['pipeline_stage'] ) ? $deal['pipeline_stage'] : '';
					$deal['win_probability'] = WP_MCP_AI_CRM_Pipeline_Stages::probability( $stage_id );
					$probability             = $deal['win_probability'];
				}

				$deal['weighted_amount']  = round( $amount * $probability, 2 );
				$deal['formatted_amount'] = class_exists( 'WP_MCP_AI_CRM_Engine' )
					? WP_MCP_AI_CRM_Engine::format_currency( $amount )
					: (string) $amount;
			}
			unset( $deal );
		}

		// Record audit log for PII access.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deals_listed',
				'deal',
				'',
				array(
					'count'   => is_array( $deals ) ? count( $deals ) : 0,
					'action'  => 'list',
					'filters' => wp_json_encode( $query_args ),
				)
			);
		}

		return $this->format_success_response(
			__( 'Deals retrieved successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deals'        => $deals,
				'per_page'     => $per_page,
				'page'         => $page,
				'filters'      => $query_args,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
