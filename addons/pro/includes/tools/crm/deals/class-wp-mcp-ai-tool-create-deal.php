<?php
/**
 * Tool for creating CRM deals/opportunities.
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
 * Create Deal Tool.
 *
 * Creates a new deal/opportunity in the CRM pipeline with:
 * - Lead association and validation
 * - Pipeline stage assignment with win probability
 * - Currency handling from engine defaults
 * - Stage change hook firing
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Create_Deal implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store
	 */
	private $data_store;

	/**
	 * CRM pipeline stages instance.
	 *
	 * @var WP_MCP_AI_CRM_Pipeline_Stages
	 */
	private $pipeline_stages;

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
		return __( 'The Create Deal tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'create_deal';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Deal', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new deal/opportunity in the CRM pipeline. Associates a lead, sets pipeline stage with win probability, and fires stage change hooks.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'             => array(
					'type'        => 'integer',
					'description' => __( 'Lead ID to associate this deal with (required)', 'mcp-ai-wpoos-pro' ),
				),
				'deal_name'           => array(
					'type'        => 'string',
					'description' => __( 'Name of the deal/opportunity', 'mcp-ai-wpoos-pro' ),
				),
				'amount'              => array(
					'type'        => 'number',
					'description' => __( 'Deal amount', 'mcp-ai-wpoos-pro' ),
				),
				'currency'            => array(
					'type'        => 'string',
					'description' => __( 'ISO 4217 currency code (defaults to engine setting)', 'mcp-ai-wpoos-pro' ),
				),
				'pipeline_stage'      => array(
					'type'        => 'string',
					'description' => __( 'Pipeline stage slug (defaults to first stage)', 'mcp-ai-wpoos-pro' ),
				),
				'expected_close_date' => array(
					'type'        => 'string',
					'description' => __( 'Expected close date (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'deal_owner'          => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the deal owner', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'lead_id' ),
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
			'database-write',
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
		// Check if data store is available.
		if ( ! $this->data_store ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gateway 1: Sanitize inputs.
		$lead_id = isset( $arguments['lead_id'] ) ? absint( $arguments['lead_id'] ) : 0;

		if ( ! $lead_id ) {
			return new WP_Error(
				'invalid_lead',
				__( 'A valid lead ID is required to create a deal.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate lead exists.
		$lead_store = null;
		if ( class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			$lead_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
		}
		if ( $lead_store ) {
			$lead = $lead_store->get_item( $lead_id );
			if ( is_wp_error( $lead ) ) {
				return new WP_Error(
					'lead_not_found',
					sprintf(
						/* translators: %d: lead ID */
						__( 'Lead with ID %d was not found.', 'mcp-ai-wpoos-pro' ),
						$lead_id
					)
				);
			}
		}

		// Resolve pipeline stage.
		$pipeline_stage = isset( $arguments['pipeline_stage'] )
			? sanitize_key( $arguments['pipeline_stage'] )
			: WP_MCP_AI_CRM_Pipeline_Stages::default_stage();

		if ( ! empty( $pipeline_stage ) && ! WP_MCP_AI_CRM_Pipeline_Stages::is_valid( $pipeline_stage ) ) {
			return new WP_Error(
				'invalid_stage',
				sprintf(
					/* translators: %s: pipeline stage slug */
					__( 'Invalid pipeline stage: "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $pipeline_stage )
				)
			);
		}

		// Resolve currency.
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$currency = isset( $arguments['currency'] )
			? strtoupper( sanitize_text_field( $arguments['currency'] ) )
			: $settings['default_currency'];

		// Resolve deal owner.
		$deal_owner = isset( $arguments['deal_owner'] ) ? absint( $arguments['deal_owner'] ) : 0;
		if ( ! $deal_owner ) {
			$deal_owner = get_current_user_id();
		}

		// Build deal data.
		$deal_data = array(
			'lead_id'             => $lead_id,
			'deal_name'           => isset( $arguments['deal_name'] )
				? sanitize_text_field( $arguments['deal_name'] )
				: sprintf(
					/* translators: %d: lead ID */
					__( 'Deal for Lead #%d', 'mcp-ai-wpoos-pro' ),
					$lead_id
				),
			'amount'              => isset( $arguments['amount'] ) ? (float) $arguments['amount'] : 0.0,
			'currency'            => $currency,
			'pipeline_stage'      => $pipeline_stage,
			'expected_close_date' => isset( $arguments['expected_close_date'] )
				? sanitize_text_field( $arguments['expected_close_date'] )
				: '',
			'deal_owner'          => $deal_owner,
			'win_probability'     => WP_MCP_AI_CRM_Pipeline_Stages::probability( $pipeline_stage ),
			'created_at'          => current_time( 'mysql' ),
		);

		/**
		 * Fires before a deal is created and assigned a stage.
		 *
		 * @since 2.3.0
		 *
		 * @param array  $deal_data     Deal data being created.
		 * @param string $pipeline_stage Stage being assigned.
		 */
		do_action( 'wp_mcp_ai_crm_before_deal_stage_change', $deal_data, $pipeline_stage );

		// Create deal using data store.
		$deal_id = $this->data_store->create_item( $deal_data );

		if ( is_wp_error( $deal_id ) ) {
			return $deal_id;
		}

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_created',
				'deal',
				$deal_id,
				array(
					'lead_id' => $lead_id,
					'stage'   => $pipeline_stage,
					'amount'  => $deal_data['amount'],
					'action'  => 'create',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal created successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'         => $deal_id,
				'pipeline_stage'  => $pipeline_stage,
				'win_probability' => $deal_data['win_probability'],
				'currency'        => $currency,
				'storage_type'    => $this->data_store->get_storage_type(),
			)
		);
	}
}
