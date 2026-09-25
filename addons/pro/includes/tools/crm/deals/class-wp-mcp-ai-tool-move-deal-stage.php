<?php
/**
 * Tool for moving a CRM deal through pipeline stages.
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
 * Move Deal Stage Tool.
 *
 * Moves a deal through the CRM pipeline with:
 * - Stage validity and duplicate-stage guards
 * - Before/after stage change hooks
 * - Closed-won lifecycle update (lead promoted to 'customer')
 * - Win probability recalculation
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Move_Deal_Stage implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return __( 'The Move Deal Stage tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'move_deal_stage';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Move Deal Stage', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Move a deal to a new pipeline stage. Records a machine-readable stage transition (source-attributed), recalculates win probability, and promotes the lead to customer when moving to closed-won. Set "undo" to true to revert the last stage move without recording a new transition.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Moving one deal to a new pipeline stage, recording source-attributed history and promoting the lead on closed-won.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Moving many deals at once; use bulk_move_deal_stages. Editing non-stage fields; use update_deal.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'bulk_move_deal_stages', 'update_deal', 'list_deals' ),
			'notes'           => __( 'Set undo=true to revert the last move. source accepts tool, agent, workflow, manual, email_reply, or bulk.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Deal ID to move (required)', 'mcp-ai-wpoos-pro' ),
				),
				'new_stage' => array(
					'type'        => 'string',
					'description' => __( 'Target pipeline stage slug (required)', 'mcp-ai-wpoos-pro' ),
				),
				'source'    => array(
					'type'        => 'string',
					'description' => __( 'Who or what moved the deal: tool, agent, workflow, manual, email_reply, or bulk. Defaults to "tool".', 'mcp-ai-wpoos-pro' ),
				),
				'undo'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, revert the last stage move instead of applying a new one. The transition is removed from history and no new transition is recorded.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_id', 'new_stage' ),
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
		if ( ! $this->data_store ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gateway 1: Sanitize inputs.
		$deal_id   = isset( $arguments['deal_id'] ) ? absint( $arguments['deal_id'] ) : 0;
		$new_stage = isset( $arguments['new_stage'] ) ? sanitize_key( $arguments['new_stage'] ) : '';
		$undo      = ! empty( $arguments['undo'] );
		$source    = isset( $arguments['source'] ) ? $arguments['source'] : 'tool';

		if ( ! $deal_id ) {
			return new WP_Error(
				'invalid_deal',
				__( 'A valid deal ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $new_stage ) {
			return new WP_Error(
				'invalid_stage',
				__( 'A target pipeline stage is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate new stage exists.
		if ( ! WP_MCP_AI_CRM_Pipeline_Stages::is_valid( $new_stage ) ) {
			return new WP_Error(
				'invalid_stage',
				sprintf(
					/* translators: %s: pipeline stage slug */
					__( 'Invalid pipeline stage: "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $new_stage )
				)
			);
		}

		// Resolve the transition source (validated against the source registry).
		$source = WP_MCP_AI_CRM_Stage_History::sanitize_source( $source );

		// Retrieve existing deal.
		$deal = $this->data_store->get_item( $deal_id );
		if ( is_wp_error( $deal ) ) {
			return $deal;
		}

		$current_stage = isset( $deal['pipeline_stage'] ) ? $deal['pipeline_stage'] : '';

		// ── Undo path: revert the last recorded move without a new transition ──
		if ( $undo ) {
			return $this->execute_undo( $deal_id, $current_stage, $deal );
		}

		// Guard: no-op if already at target stage.
		if ( $current_stage === $new_stage ) {
			return new WP_Error(
				'already_at_stage',
				sprintf(
					/* translators: %s: pipeline stage */
					__( 'Deal is already at stage "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $new_stage )
				)
			);
		}

		/**
		 * Fires before a deal moves to a new pipeline stage.
		 *
		 * @since 2.3.0
		 *
		 * @param int    $deal_id       Deal ID.
		 * @param string $current_stage Current stage slug.
		 * @param string $new_stage     Target stage slug.
		 * @param array  $deal          Full deal record.
		 */
		do_action( 'wp_mcp_ai_crm_before_deal_stage_change', $deal_id, $current_stage, $new_stage, $deal );

		// Build update data.
		$update_data = array(
			'pipeline_stage'  => $new_stage,
			'win_probability' => WP_MCP_AI_CRM_Pipeline_Stages::probability( $new_stage ),
			'updated_at'      => current_time( 'mysql' ),
		);

		// Update the deal via data store.
		$result = $this->data_store->update_item( $deal_id, $update_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record the machine-readable transition (source-attributed).
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, $current_stage, $new_stage, $source );

		// --- Closed-won lifecycle update ---.
		if ( WP_MCP_AI_CRM_Pipeline_Stages::is_won( $new_stage ) ) {
			$lead_id = isset( $deal['lead_id'] ) ? absint( $deal['lead_id'] ) : 0;
			if ( $lead_id && class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
				$lead_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
				if ( $lead_store ) {
					$lead_store->update_item(
						$lead_id,
						array(
							'lifecycle_stage' => 'customer',
							'updated_at'      => current_time( 'mysql' ),
						)
					);
				}
			}
		}

		/**
		 * Fires after a deal has moved to a new pipeline stage.
		 *
		 * @since 2.3.0
		 *
		 * @param int    $deal_id       Deal ID.
		 * @param string $current_stage Previous stage slug.
		 * @param string $new_stage     New stage slug.
		 * @param array  $deal          Full deal record.
		 */
		do_action( 'wp_mcp_ai_crm_after_deal_stage_change', $deal_id, $current_stage, $new_stage, $deal );

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_stage_moved',
				'deal',
				$deal_id,
				array(
					'previous_stage' => $current_stage,
					'new_stage'      => $new_stage,
					'source'         => $source,
					'action'         => 'stage_change',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal stage updated successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'         => $deal_id,
				'previous_stage'  => $current_stage,
				'new_stage'       => $new_stage,
				'source'          => $source,
				'win_probability' => $update_data['win_probability'],
				'is_won'          => WP_MCP_AI_CRM_Pipeline_Stages::is_won( $new_stage ),
				'storage_type'    => $this->data_store->get_storage_type(),
			)
		);
	}

	/**
	 * Undo the last stage move.
	 *
	 * Pops the newest transition from history, restores the deal to the
	 * previous stage and win probability, and does not record a new
	 * transition — an undo must not fabricate forward-looking history.
	 *
	 * @since 3.2.0
	 *
	 * @param int    $deal_id       Deal ID.
	 * @param string $current_stage Current stage slug (sanity check only).
	 * @param array  $deal          Full deal record.
	 * @return array|WP_Error
	 */
	private function execute_undo( $deal_id, $current_stage, $deal ) {
		$popped = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );

		if ( null === $popped || empty( $popped['from'] ) ) {
			return new WP_Error(
				'no_stage_history',
				__( 'This deal has no stage history to undo.', 'mcp-ai-wpoos-pro' )
			);
		}

		$restore_stage = sanitize_key( $popped['from'] );

		/**
		 * Fires before a deal stage move is reverted.
		 *
		 * @since 3.2.0
		 *
		 * @param int    $deal_id       Deal ID.
		 * @param string $current_stage Current stage slug.
		 * @param string $restore_stage Stage being restored.
		 * @param array  $deal          Full deal record.
		 */
		do_action( 'wp_mcp_ai_crm_before_deal_stage_change', $deal_id, $current_stage, $restore_stage, $deal );

		$update_data = array(
			'pipeline_stage'  => $restore_stage,
			'win_probability' => WP_MCP_AI_CRM_Pipeline_Stages::probability( $restore_stage ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$result = $this->data_store->update_item( $deal_id, $update_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after a deal stage move has been reverted.
		 *
		 * @since 3.2.0
		 *
		 * @param int    $deal_id       Deal ID.
		 * @param string $current_stage Previous stage slug.
		 * @param string $restore_stage Restored stage slug.
		 * @param array  $deal          Full deal record.
		 */
		do_action( 'wp_mcp_ai_crm_after_deal_stage_change', $deal_id, $current_stage, $restore_stage, $deal );

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'deal_stage_reverted',
				'deal',
				$deal_id,
				array(
					'previous_stage' => $current_stage,
					'restored_stage' => $restore_stage,
					'action'         => 'stage_undo',
				)
			);
		}

		return $this->format_success_response(
			__( 'Deal stage move reverted successfully.', 'mcp-ai-wpoos-pro' ),
			array(
				'deal_id'         => $deal_id,
				'previous_stage'  => $current_stage,
				'restored_stage'  => $restore_stage,
				'win_probability' => $update_data['win_probability'],
				'storage_type'    => $this->data_store->get_storage_type(),
			)
		);
	}
}
