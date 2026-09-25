<?php
/**
 * Tool for generating a compact CRM pipeline digest.
 *
 * Produces a plain-text digest (per-stage counts and value, total pipeline
 * value, stalled deals, hot leads, overdue tasks) that an agent can forward
 * to Telegram/WhatsApp/email MCP tools, plus the structured data behind it.
 * Stalled deals are detected from the stage-changed timestamp
 * (`stage_changed_at`) with an `updated_at` fallback for legacy records.
 *
 * Inspired by JobNavigator's daily digest alerts.
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
 * Get Pipeline Digest Tool.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_Tool_Get_Pipeline_Digest implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

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
		return __( 'The Get Pipeline Digest tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_pipeline_digest';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Pipeline Digest', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Build a compact pipeline digest: per-stage counts and value, total pipeline value, stalled deals, hot leads, and overdue tasks. Returns ready-to-forward text plus structured data.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building a compact daily CRM digest with per-stage counts and value, stalled deals, hot leads, and overdue tasks for messaging channels.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Per-deal stage board details; use get_pipeline_view. Future revenue scenarios; use forecast_pipeline_revenue.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_pipeline_view', 'get_workflow_inbox', 'forecast_pipeline_revenue' ),
			'notes'           => __( 'Returns ready-to-forward text plus structured data; stalled detection uses stage_changed_at with updated_at fallback.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'stale_days'        => array(
					'type'        => 'integer',
					'description' => __( 'Deals whose stage is unchanged for this many days count as stalled. Defaults to the CRM stale_deal_days setting.', 'mcp-ai-wpoos-pro' ),
				),
				'include_hot_leads' => array(
					'type'        => 'boolean',
					'description' => __( 'Include hot leads in the digest. Defaults to true.', 'mcp-ai-wpoos-pro' ),
				),
				'max_rows'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum rows per section. Defaults to 20.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
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
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		// Gateway 1: Sanitize inputs.
		$stale_days = isset( $arguments['stale_days'] ) && $arguments['stale_days'] > 0
			? min( 365, absint( $arguments['stale_days'] ) )
			: ( isset( $settings['stale_deal_days'] ) ? max( 1, (int) $settings['stale_deal_days'] ) : 14 );

		$include_hot = array_key_exists( 'include_hot_leads', $arguments ) ? (bool) $arguments['include_hot_leads'] : true;
		$max_rows    = isset( $arguments['max_rows'] ) && $arguments['max_rows'] > 0
			? min( 100, absint( $arguments['max_rows'] ) )
			: 20;

		$hot_threshold = isset( $settings['hot_score_threshold'] ) ? (int) $settings['hot_score_threshold'] : 70;
		$stale_seconds = $stale_days * DAY_IN_SECONDS;
		$now           = time();

		// ── Deals: per-stage counts + value, stalled detection ──
		$deal_ids = get_posts(
			array(
				'post_type'        => 'mcp_ai_deal',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$stage_totals   = array();
		$stalled        = array();
		$pipeline_value = 0.0;

		foreach ( $deal_ids as $deal_id ) {
			$deal_id = absint( $deal_id );
			$stage   = sanitize_key( (string) get_post_meta( $deal_id, 'pipeline_stage', true ) );
			$amount  = (float) get_post_meta( $deal_id, 'amount', true );

			$is_closed = class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' )
				&& ( WP_MCP_AI_CRM_Pipeline_Stages::is_won( $stage ) || WP_MCP_AI_CRM_Pipeline_Stages::is_lost( $stage ) );

			if ( '' === $stage ) {
				$stage = 'prospecting';
			}

			if ( ! isset( $stage_totals[ $stage ] ) ) {
				$stage_totals[ $stage ] = array(
					'count' => 0,
					'value' => 0.0,
				);
			}
			++$stage_totals[ $stage ]['count'];
			$stage_totals[ $stage ]['value'] += $amount;

			if ( ! $is_closed ) {
				$pipeline_value += $amount;
			}

			// Stalled detection: stage_changed_at with updated_at fallback.
			if ( ! $is_closed ) {
				$anchor = get_post_meta( $deal_id, 'stage_changed_at', true );
				if ( empty( $anchor ) ) {
					$post   = get_post( $deal_id );
					$anchor = $post ? $post->post_modified_gmt : '';
				}
				$anchor_ts = $anchor ? strtotime( $anchor ) : false;
				if ( false !== $anchor_ts && ( $now - $anchor_ts ) >= $stale_seconds ) {
					$stalled[] = array(
						'deal_id'            => $deal_id,
						'title'              => get_the_title( $deal_id ),
						'stage'              => $stage,
						'unchanged_for_days' => (int) floor( ( $now - $anchor_ts ) / DAY_IN_SECONDS ),
					);
				}
			}
		}

		// ── Hot leads ──
		$hot_leads = array();
		if ( $include_hot ) {
			$lead_ids = get_posts(
				array(
					'post_type'        => 'mcp_ai_lead',
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			foreach ( $lead_ids as $lead_id ) {
				$lead_id = absint( $lead_id );
				$score   = (int) get_post_meta( $lead_id, 'lead_score', true );
				if ( $score < $hot_threshold ) {
					continue;
				}
				$status = sanitize_key( (string) get_post_meta( $lead_id, 'lead_status', true ) );
				if ( 'disqualified' === $status ) {
					continue;
				}
				$hot_leads[] = array(
					'lead_id' => $lead_id,
					'title'   => get_the_title( $lead_id ),
					'email'   => (string) get_post_meta( $lead_id, 'email', true ),
					'score'   => $score,
				);
			}
		}

		// ── Overdue tasks ──
		$task_ids = get_posts(
			array(
				'post_type'        => 'mcp_ai_crm_activity',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$overdue = array();
		foreach ( $task_ids as $task_id ) {
			$task_id = absint( $task_id );
			if ( 'task' !== get_post_meta( $task_id, 'activity_type', true ) ) {
				continue;
			}
			$due = (string) get_post_meta( $task_id, 'due_date', true );
			if ( '' === $due ) {
				continue;
			}
			$due_ts = strtotime( $due );
			if ( false !== $due_ts && $due_ts < $now ) {
				$overdue[] = array(
					'task_id' => $task_id,
					'title'   => get_the_title( $task_id ),
					'due'     => $due,
				);
			}
		}

		// ── Compose the text digest ──
		$lines   = array();
		$lines[] = 'CRM Pipeline Digest — ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
		$lines[] = '';

		$lines[] = 'Pipeline:';
		foreach ( $stage_totals as $stage => $totals ) {
			$lines[] = sprintf( '- %s: %d deals, %s', $stage, $totals['count'], $this->format_money( $totals['value'] ) );
		}
		$lines[] = sprintf( '- Open pipeline value: %s', $this->format_money( $pipeline_value ) );

		if ( ! empty( $stalled ) ) {
			$lines[] = '';
			$lines[] = sprintf( 'Stalled deals (>= %d days):', $stale_days );
			foreach ( array_slice( $stalled, 0, $max_rows ) as $row ) {
				$lines[] = sprintf( '- #%d %s [%s] — %d days', $row['deal_id'], $row['title'], $row['stage'], $row['unchanged_for_days'] );
			}
		}

		if ( ! empty( $hot_leads ) ) {
			$lines[] = '';
			$lines[] = sprintf( 'Hot leads (score >= %d):', $hot_threshold );
			foreach ( array_slice( $hot_leads, 0, $max_rows ) as $row ) {
				$lines[] = sprintf( '- #%d %s (%s) — %d', $row['lead_id'], $row['title'], $row['email'], $row['score'] );
			}
		}

		if ( ! empty( $overdue ) ) {
			$lines[] = '';
			$lines[] = 'Overdue tasks:';
			foreach ( array_slice( $overdue, 0, $max_rows ) as $row ) {
				$lines[] = sprintf( '- #%d %s — due %s', $row['task_id'], $row['title'], $row['due'] );
			}
		}

		$data = array(
			'stage_totals'   => $stage_totals,
			'pipeline_value' => $pipeline_value,
			'stalled_deals'  => array_slice( $stalled, 0, $max_rows ),
			'hot_leads'      => array_slice( $hot_leads, 0, $max_rows ),
			'overdue_tasks'  => array_slice( $overdue, 0, $max_rows ),
		);

		return $this->format_success_response(
			__( 'Pipeline digest generated.', 'mcp-ai-wpoos-pro' ),
			array(
				'text' => implode( "\n", $lines ),
				'data' => $data,
			)
		);
	}

	/**
	 * Format an amount with the toolkit's default currency.
	 *
	 * @param float $amount Amount.
	 * @return string Formatted amount.
	 */
	private function format_money( $amount ) {
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return WP_MCP_AI_CRM_Engine::format_currency( (float) $amount );
		}
		return (string) round( (float) $amount, 2 );
	}
}
