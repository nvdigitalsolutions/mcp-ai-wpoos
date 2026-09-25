<?php
/**
 * Tool for recording inbound email replies against CRM records.
 *
 * The deterministic core of the JobNavigator-style email classification
 * loop: stores the last reply timestamp, snippet, and sentiment on the
 * lead and every open deal of that lead, and can advance a deal to the
 * next open stage with `source = email_reply` when a reply is a buying
 * signal. Closed deals are never advanced.
 *
 * A future Gmail-poll workflow rule calls this tool; today agents call it
 * directly after reading a reply.
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
 * Record CRM Reply Tool.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_Tool_Record_CRM_Reply implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Valid sentiment slugs.
	 *
	 * @var string[]
	 */
	const SENTIMENTS = array( 'positive', 'neutral', 'negative', 'mixed', 'unknown' );

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
		return __( 'The Record CRM Reply tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'record_crm_reply';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Record CRM Reply', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Record an inbound email reply against a lead and its open deals (last-received timestamp, snippet, sentiment). Can advance a deal to the next open pipeline stage with source "email_reply" when the reply is a buying signal.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Logging an inbound email reply against a lead and its open deals, optionally advancing one open deal stage.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Sending an automated reply; use auto_reply_inbound. Editing lead fields; use update_lead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'update_lead', 'auto_reply_inbound', 'move_deal_stage' ),
			'notes'           => __( 'Closed deals are never advanced; sentiment must be positive, neutral, negative, mixed, or unknown.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Lead ID to record the reply against.', 'mcp-ai-wpoos-pro' ),
				),
				'email'        => array(
					'type'        => 'string',
					'description' => __( 'Reply sender email — used to resolve the lead when lead_id is omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'deal_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Optional deal ID to advance when advance_deal is true.', 'mcp-ai-wpoos-pro' ),
				),
				'snippet'      => array(
					'type'        => 'string',
					'description' => __( 'Short snippet of the reply (capped at 500 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'sentiment'    => array(
					'type'        => 'string',
					'description' => __( 'Classified sentiment: positive, neutral, negative, mixed, or unknown.', 'mcp-ai-wpoos-pro' ),
				),
				'received_at'  => array(
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp of the reply. Defaults to now.', 'mcp-ai-wpoos-pro' ),
				),
				'advance_deal' => array(
					'type'        => 'boolean',
					'description' => __( 'When true and a deal is resolved, move it to the next open pipeline stage with source "email_reply". Closed deals are never advanced.', 'mcp-ai-wpoos-pro' ),
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
		// Gateway 1: Sanitize inputs.
		$lead_id   = isset( $arguments['lead_id'] ) ? absint( $arguments['lead_id'] ) : 0;
		$deal_id   = isset( $arguments['deal_id'] ) ? absint( $arguments['deal_id'] ) : 0;
		$email     = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$snippet   = isset( $arguments['snippet'] )
			? substr( sanitize_textarea_field( $arguments['snippet'] ), 0, 500 )
			: '';
		$sentiment = isset( $arguments['sentiment'] ) ? sanitize_key( $arguments['sentiment'] ) : 'unknown';
		if ( ! in_array( $sentiment, self::SENTIMENTS, true ) ) {
			return new WP_Error(
				'invalid_sentiment',
				__( 'Sentiment must be one of: positive, neutral, negative, mixed, unknown.', 'mcp-ai-wpoos-pro' )
			);
		}

		$received_at = isset( $arguments['received_at'] ) ? sanitize_text_field( $arguments['received_at'] ) : '';
		if ( '' === $received_at ) {
			$received_at = gmdate( 'c' );
		} elseif ( false === strtotime( $received_at ) ) {
			return new WP_Error(
				'invalid_received_at',
				__( 'received_at must be an ISO 8601 timestamp.', 'mcp-ai-wpoos-pro' )
			);
		}

		$advance_deal = ! empty( $arguments['advance_deal'] );

		// Resolve the lead: explicit ID or normalized-email identity lookup.
		if ( ! $lead_id && $email && class_exists( 'WP_MCP_AI_CRM_Identity' ) ) {
			$lead_id = WP_MCP_AI_CRM_Identity::find_lead_by_email( $email );
		}

		if ( ! $lead_id ) {
			return new WP_Error(
				'lead_not_found',
				__( 'No lead could be resolved. Provide lead_id or a known reply email.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$result = self::apply( $lead_id, $deal_id, $snippet, $sentiment, $received_at, $advance_deal );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record audit log.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'reply_recorded',
				'lead',
				$lead_id,
				array(
					'sentiment'      => $sentiment,
					'deals_touched'  => count( $result['deals_touched'] ),
					'advanced_deals' => $advance_deal ? 1 : 0,
					'action'         => 'email_reply',
				)
			);
		}

		return $this->format_success_response(
			__( 'Reply recorded.', 'mcp-ai-wpoos-pro' ),
			array(
				'lead_id'      => $lead_id,
				'sentiment'    => $sentiment,
				'received_at'  => $received_at,
				'deals_touched' => $result['deals_touched'],
			)
		);
	}

	/**
	 * Apply reply signals to a lead and its open deals.
	 *
	 * Shared static core used by both the tool (after its capability gates)
	 * and the Gmail reply poller (cron context, no acting user).
	 *
	 * @since 3.2.0
	 *
	 * @param int    $lead_id      Lead post ID.
	 * @param int    $deal_id      Optional deal ID to advance.
	 * @param string $snippet      Reply snippet (pre-sanitized).
	 * @param string $sentiment    Sentiment slug.
	 * @param string $received_at  ISO 8601 timestamp.
	 * @param bool   $advance_deal Advance the requested open deal one stage.
	 * @return array|WP_Error Array with deals_touched, or WP_Error.
	 */
	public static function apply( $lead_id, $deal_id, $snippet, $sentiment, $received_at, $advance_deal ) {
		$lead_id = absint( $lead_id );
		$deal_id = absint( $deal_id );

		if ( ! $lead_id || 'mcp_ai_lead' !== get_post_type( $lead_id ) ) {
			return new WP_Error(
				'lead_not_found',
				__( 'No lead could be resolved. Provide lead_id or a known reply email.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Toolkit_Data_Store_Factory' ) ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		$lead_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'leads' );
		if ( ! $lead_store ) {
			return new WP_Error(
				'store_unavailable',
				__( 'CRM data store not available. Please ensure the CRM Toolkit is enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Spread the signal onto the lead.
		$lead_update = $lead_store->update_item(
			$lead_id,
			array(
				'last_email_received'  => $received_at,
				'last_email_snippet'   => $snippet,
				'last_email_sentiment' => $sentiment,
			)
		);
		if ( is_wp_error( $lead_update ) ) {
			return $lead_update;
		}

		$deals_touched = array();

		// Spread the signal onto every open deal of the lead.
		$open_deals = get_posts(
			array(
				'post_type'        => 'mcp_ai_deal',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Intentional lead linkage lookup.
					array(
						'key'   => 'lead_id',
						'value' => $lead_id,
					),
				),
			)
		);

		$deal_store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'deals' );

		foreach ( $open_deals as $open_deal_id ) {
			$open_deal_id = absint( $open_deal_id );
			$deal         = $deal_store ? $deal_store->get_item( $open_deal_id ) : null;

			if ( is_wp_error( $deal ) || ! is_array( $deal ) ) {
				continue;
			}

			$stage     = isset( $deal['pipeline_stage'] ) ? $deal['pipeline_stage'] : '';
			$is_closed = class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' )
				&& ( WP_MCP_AI_CRM_Pipeline_Stages::is_won( $stage ) || WP_MCP_AI_CRM_Pipeline_Stages::is_lost( $stage ) );

			if ( $is_closed ) {
				continue;
			}

			$deal_store->update_item(
				$open_deal_id,
				array(
					'last_email_received'  => $received_at,
					'last_email_snippet'   => $snippet,
					'last_email_sentiment' => $sentiment,
				)
			);

			// Advance only the explicitly requested deal, and only when open.
			$advanced = false;
			if ( $advance_deal && $deal_id === $open_deal_id && class_exists( 'WP_MCP_AI_CRM_Stage_History' ) ) {
				$next_stage = WP_MCP_AI_CRM_Stage_History::next_open_stage( $stage );
				if ( $next_stage ) {
					$deal_store->update_item(
						$open_deal_id,
						array(
							'pipeline_stage'  => $next_stage,
							'win_probability' => WP_MCP_AI_CRM_Pipeline_Stages::probability( $next_stage ),
							'updated_at'      => current_time( 'mysql' ),
						)
					);
					WP_MCP_AI_CRM_Stage_History::record( $open_deal_id, $stage, $next_stage, 'email_reply' );
					$advanced = true;

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
					do_action( 'wp_mcp_ai_crm_after_deal_stage_change', $open_deal_id, $stage, $next_stage, $deal );
				}
			}

			$deals_touched[] = array(
				'deal_id'  => $open_deal_id,
				'advanced' => $advanced,
				'stage'    => $advanced ? $next_stage : $stage,
			);
		}

		return array(
			'deals_touched' => $deals_touched,
		);
	}
}
