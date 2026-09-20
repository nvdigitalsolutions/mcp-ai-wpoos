<?php
/**
 * Tool for bulk-moving CRM deals between pipeline stages.
 *
 * Moves many deals to one pipeline stage with per-row outcome reporting —
 * `updated`, `skipped`, and `not_found` — mirroring JobNavigator's bulk
 * application status updates. Every row goes through the same path as the
 * single move_deal_stage tool (stage history, hooks, audit, won-cascade),
 * and a row already at the target stage is skipped without touching
 * `updated_at` so the ageing signal is preserved.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk Move Deal Stages Tool.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_Tool_Bulk_Move_Deal_Stages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Hard cap on the number of deals per call.
	 *
	 * @var int
	 */
	const MAX_DEALS = 100;

	/**
	 * Data store instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Data_Store|null
	 */
	private $data_store;

	/**
	 * Determine whether the tool is available.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 3.2.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Bulk Move Deal Stages tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
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
		return 'bulk_move_deal_stages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Bulk Move Deal Stages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Move up to 100 deals to a single pipeline stage in one call. Reports per-row outcomes (updated, skipped, not_found) so a malformed ID never aborts the batch. Supports undo of the last move per row.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Moving up to 100 deals to one pipeline stage in a single call, with per-row updated, skipped, or not_found results.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Moving a single deal; use move_deal_stage. Changing non-stage fields; use update_deal.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'move_deal_stage', 'list_deals', 'update_deal' ),
			'notes'           => __( 'Rows already at the target stage are skipped without touching updated_at. Set undo=true to revert the last move.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_ids'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => __( 'Deal IDs to move (required, max 100).', 'mcp-ai-wpoos-pro' ),
				),
				'pipeline_stage' => array(
					'type'        => 'string',
					'description' => __( 'Target pipeline stage slug (required).', 'mcp-ai-wpoos-pro' ),
				),
				'undo'           => array(
					'type'        => 'boolean',
					'description' => __( 'When true, revert the last stage move of each deal instead of applying a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'source'         => array(
					'type'        => 'string',
					'description' => __( 'Who or what moved the deals. Defaults to "bulk".', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'deal_ids', 'pipeline_stage' ),
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
		$deal_ids = isset( $arguments['deal_ids'] ) && is_array( $arguments['deal_ids'] )
			? $arguments['deal_ids']
			: array();
		if ( empty( $deal_ids ) ) {
			return new WP_Error(
				'invalid_deals',
				__( 'A non-empty deal_ids list is required.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( count( $deal_ids ) > self::MAX_DEALS ) {
			return new WP_Error(
				'too_many_deals',
				sprintf(
					/* translators: %d: max deals per call */
					__( 'At most %d deals can be moved per call.', 'mcp-ai-wpoos-pro' ),
					self::MAX_DEALS
				)
			);
		}

		$pipeline_stage = isset( $arguments['pipeline_stage'] ) ? sanitize_key( $arguments['pipeline_stage'] ) : '';
		if ( ! $pipeline_stage || ! WP_MCP_AI_CRM_Pipeline_Stages::is_valid( $pipeline_stage ) ) {
			return new WP_Error(
				'invalid_stage',
				sprintf(
					/* translators: %s: pipeline stage slug */
					__( 'Invalid pipeline stage: "%s".', 'mcp-ai-wpoos-pro' ),
					esc_html( $pipeline_stage )
				)
			);
		}

		$undo   = ! empty( $arguments['undo'] );
		$source = WP_MCP_AI_CRM_Stage_History::sanitize_source(
			isset( $arguments['source'] ) ? $arguments['source'] : 'bulk'
		);

		$updated   = 0;
		$skipped   = 0;
		$not_found = array();
		$rows      = array();

		foreach ( $deal_ids as $raw_id ) {
			$deal_id = absint( $raw_id );

			if ( $deal_id <= 0 || 'mcp_ai_deal' !== get_post_type( $deal_id ) ) {
				$not_found[] = is_scalar( $raw_id ) ? (string) $raw_id : '';
				continue;
			}

			$deal = $this->data_store->get_item( $deal_id );
			if ( is_wp_error( $deal ) ) {
				$not_found[] = (string) $deal_id;
				continue;
			}

			$current_stage = isset( $deal['pipeline_stage'] ) ? $deal['pipeline_stage'] : '';

			if ( $undo ) {
				$popped = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );
				if ( null === $popped || empty( $popped['from'] ) ) {
					++$skipped;
					continue;
				}
				$target_stage = sanitize_key( $popped['from'] );
			} else {
				$target_stage = $pipeline_stage;
				if ( $current_stage === $target_stage ) {
					// Same-status row: skip entirely. No transition, no
					// updated_at bump — the ageing signal must not reset.
					++$skipped;
					continue;
				}
			}

			/**
			 * Fires before a deal moves to a new pipeline stage (bulk).
			 *
			 * @since 3.2.0
			 *
			 * @param int    $deal_id       Deal ID.
			 * @param string $current_stage Current stage slug.
			 * @param string $target_stage  Target stage slug.
			 * @param array  $deal          Full deal record.
			 */
			do_action( 'wp_mcp_ai_crm_before_deal_stage_change', $deal_id, $current_stage, $target_stage, $deal );

			$result = $this->data_store->update_item(
				$deal_id,
				array(
					'pipeline_stage'  => $target_stage,
					'win_probability' => WP_MCP_AI_CRM_Pipeline_Stages::probability( $target_stage ),
					'updated_at'      => current_time( 'mysql' ),
				)
			);

			if ( is_wp_error( $result ) ) {
				// A failed row is reported like a missing one so the caller
				// can retry it individually; the batch continues.
				$not_found[] = (string) $deal_id;
				continue;
			}

			if ( ! $undo ) {
				WP_MCP_AI_CRM_Stage_History::record( $deal_id, $current_stage, $target_stage, $source );
			}

			// Won-cascade (mirrors move_deal_stage).
			if ( ! $undo && WP_MCP_AI_CRM_Pipeline_Stages::is_won( $target_stage ) ) {
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
			 * Fires after a deal has moved to a new pipeline stage (bulk).
			 *
			 * @since 3.2.0
			 *
			 * @param int    $deal_id       Deal ID.
			 * @param string $current_stage Previous stage slug.
			 * @param string $target_stage  New stage slug.
			 * @param array  $deal          Full deal record.
			 */
			do_action( 'wp_mcp_ai_crm_after_deal_stage_change', $deal_id, $current_stage, $target_stage, $deal );

			// Record audit log.
			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record(
					$undo ? 'deal_stage_reverted' : 'deal_stage_moved',
					'deal',
					$deal_id,
					array(
						'previous_stage' => $current_stage,
						'new_stage'      => $target_stage,
						'source'         => $source,
						'action'         => $undo ? 'stage_undo_bulk' : 'stage_change_bulk',
					)
				);
			}

			$rows[] = array(
				'deal_id'        => $deal_id,
				'previous_stage' => $current_stage,
				'new_stage'      => $target_stage,
			);
			++$updated;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: 1: updated count, 2: skipped count, 3: not-found count */
				__( 'Bulk stage move complete: %1$d updated, %2$d skipped, %3$d not found.', 'mcp-ai-wpoos-pro' ),
				$updated,
				$skipped,
				count( $not_found )
			),
			array(
				'updated'      => $updated,
				'skipped'      => $skipped,
				'not_found'    => $not_found,
				'rows'         => $rows,
				'source'       => $source,
				'undo'         => $undo,
				'storage_type' => $this->data_store->get_storage_type(),
			)
		);
	}
}
