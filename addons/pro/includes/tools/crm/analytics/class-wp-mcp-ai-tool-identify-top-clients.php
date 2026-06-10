<?php
/**
 * Identify Top Clients Tool — Ranks contacts by engagement volume, answering
 * "who do I talk to the most?"
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TOP CLIENTS vs TOP CUSTOMERS
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   Top Clients (this tool)     → ranks by ENGAGEMENT VOLUME
 *     Sources: activity logs (calls, emails, meetings, tasks, notes)
 *     Use:   "Who am I contacting the most? Who am I neglecting?"
 *
 *   Top Customers (sibling tool) → ranks by BUSINESS VALUE
 *     Sources: lead scores, deal pipeline, won deals, lifecycle stage
 *     Use:   "Which accounts should I prioritise for revenue?"
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Designed to answer "who have I been contacting the most?" by aggregating
 * activity records per lead/contact and ranking by total interaction count,
 * recent engagement, and diversity of contact channels.
 *
 * A lead with zero deals can still rank #1 here if contacted daily — this
 * tool is about relationship management, not revenue.
 *
 * Returns a ranked, paginated list suitable for the CRM Command Center
 * "Top Clients" tab and for AI assistants auditing client engagement.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since     2.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifies and ranks most-engaged contacts from CRM activity records.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Tool_Identify_Top_Clients implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether the CRM toolkit is enabled.
	 *
	 * @since 2.7.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.7.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Identify Top Clients tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'identify_top_clients';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Identify Top Clients', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Identify your most-contacted relationships by aggregating activity volume, recency, channel diversity, and completion rates across calls, emails, meetings, and tasks. Answers the question "who do I talk to the most?" — ranks by engagement frequency, not revenue. For revenue-based ranking, use identify_top_customers instead.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of top clients to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'activity_type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by activity type: call, email, meeting, task, note. Leave empty for all.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'call', 'email', 'meeting', 'task', 'note' ),
				),
				'min_interactions'  => array(
					'type'        => 'integer',
					'description' => __( 'Minimum number of interactions required.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'contact_owner'     => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned owner WordPress user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'date_from'         => array(
					'type'        => 'string',
					'description' => __( 'Only include activities on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'           => array(
					'type'        => 'string',
					'description' => __( 'Only include activities on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'include_sequences' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, also include sequence enrolment data in the engagement score.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'sort_by'           => array(
					'type'        => 'string',
					'description' => __( 'Sort results by this metric.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'total_interactions', 'recent_activity', 'unique_channels', 'engagement_score' ),
					'default'     => 'engagement_score',
				),
			),
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
	 * @since 2.7.0
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

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view client engagement data.', 'mcp-ai-wpoos-pro' ),
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

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = min( 100, max( 1, $limit ) );

		$min_interactions  = isset( $arguments['min_interactions'] ) ? absint( $arguments['min_interactions'] ) : 0;
		$include_sequences = ! empty( $arguments['include_sequences'] );
		$sort_by           = isset( $arguments['sort_by'] ) ? sanitize_key( $arguments['sort_by'] ) : 'engagement_score';
		$allowed_sort      = array( 'total_interactions', 'recent_activity', 'unique_channels', 'engagement_score' );
		if ( ! in_array( $sort_by, $allowed_sort, true ) ) {
			$sort_by = 'engagement_score';
		}

		// --- Phase 1: Query all activities within date range ---

		$activity_args = array(
			'post_type'      => 'mcp_ai_crm_activity',
			'post_status'    => 'publish',
			'posts_per_page' => 1000, // Upper bound for in-memory aggregation.
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Activity type filter.
		if ( ! empty( $arguments['activity_type'] ) ) {
			$allowed_types = array( 'call', 'email', 'meeting', 'task', 'note' );
			$activity_type = sanitize_key( $arguments['activity_type'] );
			if ( in_array( $activity_type, $allowed_types, true ) ) {
				$activity_args['meta_query'] = array(
					array(
						'key'   => '_activity_type',
						'value' => $activity_type,
					),
				);
			}
		}

		// Date range filter.
		if ( ! empty( $arguments['date_from'] ) || ! empty( $arguments['date_to'] ) ) {
			$dq = array();
			if ( ! empty( $arguments['date_from'] ) ) {
				$dq['after'] = sanitize_text_field( $arguments['date_from'] );
			}
			if ( ! empty( $arguments['date_to'] ) ) {
				$dq['before']    = sanitize_text_field( $arguments['date_to'] );
				$dq['inclusive'] = true;
			}
			$activity_args['date_query'] = array( $dq );
		}

		$activity_query = new WP_Query( $activity_args );
		$activity_ids   = $activity_query->posts;

		if ( empty( $activity_ids ) ) {
			return $this->format_success_response(
				__( 'No activities found matching the criteria.', 'mcp-ai-wpoos-pro' ),
				array(
					'clients'          => array(),
					'count'            => 0,
					'total_activities' => 0,
				)
			);
		}

		// --- Phase 2: Aggregate activities by lead/contact ---

		$contact_aggregates = array(); // lead_id => aggregate data.
		$total_activities   = 0;

		foreach ( $activity_ids as $activity_id ) {
			$lead_id = (int) get_post_meta( $activity_id, '_lead_id', true );
			if ( empty( $lead_id ) ) {
				continue; // Activity not linked to any lead.
			}

			// Owner filter on the lead.
			if ( ! empty( $arguments['contact_owner'] ) ) {
				$lead_owner = (int) get_post_meta( $lead_id, '_contact_owner', true );
				if ( absint( $arguments['contact_owner'] ) !== $lead_owner ) {
					continue;
				}
			}

			if ( ! isset( $contact_aggregates[ $lead_id ] ) ) {
				$contact_aggregates[ $lead_id ] = array(
					'lead_id'             => $lead_id,
					'total_interactions'  => 0,
					'by_type'             => array(
						'call'    => 0,
						'email'   => 0,
						'meeting' => 0,
						'task'    => 0,
						'note'    => 0,
					),
					'last_activity_date'  => '',
					'first_activity_date' => '',
					'activity_dates'      => array(),
					'completed_count'     => 0,
					'snoozed_count'       => 0,
				);
			}

			$activity_type = sanitize_key( (string) get_post_meta( $activity_id, '_activity_type', true ) );
			$activity_date = get_the_date( 'Y-m-d', $activity_id );

			++$contact_aggregates[ $lead_id ]['total_interactions'];
			++$total_activities;

			if ( isset( $contact_aggregates[ $lead_id ]['by_type'][ $activity_type ] ) ) {
				++$contact_aggregates[ $lead_id ]['by_type'][ $activity_type ];
			}

			$contact_aggregates[ $lead_id ]['activity_dates'][] = $activity_date;

			// Track completion status.
			$status = sanitize_key( (string) get_post_meta( $activity_id, '_activity_status', true ) );
			if ( 'completed' === $status ) {
				++$contact_aggregates[ $lead_id ]['completed_count'];
			} elseif ( 'snoozed' === $status ) {
				++$contact_aggregates[ $lead_id ]['snoozed_count'];
			}
		}

		// --- Phase 3: Enrich with lead metadata ---

		$ranked_clients = array();

		foreach ( $contact_aggregates as $lead_id => $aggregate ) {
			// Apply minimum interactions filter.
			if ( $min_interactions > 0 && $aggregate['total_interactions'] < $min_interactions ) {
				continue;
			}

			// Skip if lead post no longer exists.
			$lead_post = get_post( $lead_id );
			if ( ! $lead_post || 'publish' !== $lead_post->post_status ) {
				continue;
			}

			// Sort activity dates to get recency/frequency.
			$dates = $aggregate['activity_dates'];
			sort( $dates );
			$aggregate['last_activity_date']  = end( $dates ) ?: '';
			$aggregate['first_activity_date'] = reset( $dates ) ?: '';

			// Calculate days since last activity.
			$days_since_last = '';
			if ( ! empty( $aggregate['last_activity_date'] ) ) {
				$last_date       = new DateTime( $aggregate['last_activity_date'] );
				$now             = new DateTime( current_time( 'Y-m-d' ) );
				$days_since_last = (int) $now->diff( $last_date )->days;
			}

			// Calculate days between first and last (engagement span).
			$engagement_span_days = 0;
			if ( ! empty( $aggregate['first_activity_date'] ) && ! empty( $aggregate['last_activity_date'] ) ) {
				$first_date           = new DateTime( $aggregate['first_activity_date'] );
				$last_date            = new DateTime( $aggregate['last_activity_date'] );
				$engagement_span_days = (int) $first_date->diff( $last_date )->days;
			}

			// Unique channel count.
			$unique_channels = 0;
			foreach ( $aggregate['by_type'] as $count ) {
				if ( $count > 0 ) {
					++$unique_channels;
				}
			}

			// Engagement score (0–100):
			// - Total interactions: 40% (logarithmic)
			// - Recency (inverse days since last): 25%
			// - Channel diversity: 20%
			// - Completion rate: 15%
			$interaction_score = min( 40, round( log( $aggregate['total_interactions'] + 1, 100 ) * 40, 1 ) );

			$recency_score = 0;
			if ( '' !== $days_since_last ) {
				// 0 days = 25, 365+ days = 0.
				$recency_score = round( max( 0, 25 - ( $days_since_last / 365 ) * 25 ), 1 );
			}

			$channel_score = round( ( $unique_channels / 5 ) * 20, 1 ); // 5 possible types.

			$completion_rate  = $aggregate['total_interactions'] > 0
				? $aggregate['completed_count'] / $aggregate['total_interactions']
				: 0;
			$completion_score = round( $completion_rate * 15, 1 );

			$engagement_score = round( $interaction_score + $recency_score + $channel_score + $completion_score, 1 );

			// Lead enrichment.
			$lead_title = get_the_title( $lead_id );
			$email      = sanitize_text_field( (string) get_post_meta( $lead_id, '_email', true ) );
			$phone      = sanitize_text_field( (string) get_post_meta( $lead_id, '_phone', true ) );
			$company    = sanitize_text_field( (string) get_post_meta( $lead_id, '_company', true ) );
			$lifecycle  = sanitize_key( (string) get_post_meta( $lead_id, '_lifecycle_stage', true ) );
			$owner_id   = (int) get_post_meta( $lead_id, '_contact_owner', true );
			$owner_name = '';
			if ( $owner_id > 0 ) {
				$owner_user = get_userdata( $owner_id );
				$owner_name = $owner_user ? $owner_user->display_name : '';
			}

			// Check if converted to customer.
			$customer_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_customer',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => '_source_lead_id',
							'value' => $lead_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);
			$is_customer    = ! empty( $customer_query->posts );
			$customer_id    = $is_customer ? $customer_query->posts[0] : null;

			$ranked_clients[] = array(
				'lead_id'              => $lead_id,
				'title'                => $lead_title,
				'email'                => $email,
				'phone'                => $phone,
				'company'              => $company,
				'lifecycle_stage'      => $lifecycle ?: 'lead',
				'is_customer'          => $is_customer,
				'customer_id'          => $customer_id,
				'contact_owner_id'     => $owner_id,
				'contact_owner'        => $owner_name,
				'total_interactions'   => $aggregate['total_interactions'],
				'interactions_by_type' => $aggregate['by_type'],
				'unique_channels'      => $unique_channels,
				'completed_count'      => $aggregate['completed_count'],
				'snoozed_count'        => $aggregate['snoozed_count'],
				'completion_rate_pct'  => round( $completion_rate * 100, 1 ),
				'last_activity_date'   => $aggregate['last_activity_date'],
				'first_activity_date'  => $aggregate['first_activity_date'],
				'days_since_last'      => $days_since_last,
				'engagement_span_days' => $engagement_span_days,
				'engagement_score'     => $engagement_score,
				'score_breakdown'      => array(
					'interaction_volume' => $interaction_score,
					'recency'            => $recency_score,
					'channel_diversity'  => $channel_score,
					'completion'         => $completion_score,
				),
			);
		}

		// --- Phase 4: Sort ---

		usort(
			$ranked_clients,
			function ( $a, $b ) use ( $sort_by ) {
				switch ( $sort_by ) {
					case 'total_interactions':
						return $b['total_interactions'] <=> $a['total_interactions'];
					case 'recent_activity':
						// Lower days_since_last = more recent = higher rank.
						return ( $a['days_since_last'] ?? 99999 ) <=> ( $b['days_since_last'] ?? 99999 );
					case 'unique_channels':
						return $b['unique_channels'] <=> $a['unique_channels'];
					case 'engagement_score':
					default:
						return $b['engagement_score'] <=> $a['engagement_score'];
				}
			}
		);

		// --- Phase 5: Slice to limit ---

		$top_clients = array_slice( $ranked_clients, 0, $limit );

		// Record audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'top_clients_identified',
				'lead',
				'',
				array(
					'count'            => count( $top_clients ),
					'total_activities' => $total_activities,
					'sort_by'          => $sort_by,
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of top clients identified */
				_n(
					'Identified %d top client by engagement.',
					'Identified %d top clients by engagement.',
					count( $top_clients ),
					'mcp-ai-wpoos-pro'
				),
				count( $top_clients )
			),
			array(
				'clients'          => $top_clients,
				'count'            => count( $top_clients ),
				'limit'            => $limit,
				'total_activities' => $total_activities,
				'sort_by'          => $sort_by,
				'scoring_model'    => array(
					'interaction_volume_weight' => 0.40,
					'recency_weight'            => 0.25,
					'channel_diversity_weight'  => 0.20,
					'completion_rate_weight'    => 0.15,
				),
			)
		);
	}
}
