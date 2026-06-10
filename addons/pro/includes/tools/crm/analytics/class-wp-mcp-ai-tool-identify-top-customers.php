<?php
/**
 * Identify Top Customers Tool — Ranks leads by business value, answering
 * "who is worth the most to my business?"
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TOP CUSTOMERS vs TOP CLIENTS
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   Top Customers (this tool) → ranks by BUSINESS VALUE
 *     Sources: lead scores, deal pipeline, won deals, lifecycle stage
 *     Use:   "Which accounts should I prioritise for revenue?"
 *
 *   Top Clients (sibling tool)  → ranks by ENGAGEMENT VOLUME
 *     Sources: activity logs (calls, emails, meetings, tasks, notes)
 *     Use:   "Who am I contacting the most? Who am I neglecting?"
 *
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Uses a composite scoring model that weights:
 *   - Lead qualification score (BANT/MEDDIC via CRM engine) — 40%
 *   - Associated deal pipeline value (won deals weighted most) — 35%
 *   - Activity volume (calls, emails, meetings) — 15%
 *   - Lifecycle progression (customer > opportunity > SQL > ...) — 10%
 *
 * Returns a ranked, paginated list suitable for the CRM Command Center
 * "Top Customers" tab and for AI assistants orchestrating CRM analysis.
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
 * Identifies and ranks top customers from the CRM database.
 *
 * @since 2.7.0
 */
class WP_MCP_AI_Tool_Identify_Top_Customers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The Identify Top Customers tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'identify_top_customers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Identify Top Customers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Identify your most valuable customer relationships by cross-referencing lead quality, deal pipeline value, activity volume, and lifecycle progression. Answers the question "who is worth the most to my business?" — ranks by revenue potential, not contact frequency. For contact-frequency ranking, use identify_top_clients instead.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'                  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of top customers to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'min_score'              => array(
					'type'        => 'integer',
					'description' => __( 'Minimum composite score threshold (0–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'lifecycle_stage'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by lifecycle stage (e.g. customer, opportunity, sql).', 'mcp-ai-wpoos-pro' ),
				),
				'contact_owner'          => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned owner WordPress user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'include_customers_only' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, only include leads that have been converted to customers.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'date_from'              => array(
					'type'        => 'string',
					'description' => __( 'Only include leads created on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'                => array(
					'type'        => 'string',
					'description' => __( 'Only include leads created on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
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

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = min( 100, max( 1, $limit ) );

		$min_score = isset( $arguments['min_score'] ) ? absint( $arguments['min_score'] ) : 0;
		$min_score = max( 0, min( 100, $min_score ) );

		$include_customers_only = ! empty( $arguments['include_customers_only'] );

		// --- Phase 1: Collect all leads ---

		$lead_args = array(
			'post_type'      => 'mcp_ai_lead',
			'post_status'    => 'publish',
			'posts_per_page' => 500, // Upper bound for in-memory scoring.
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

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
			$lead_args['date_query'] = array( $dq );
		}

		// Owner filter.
		$meta_query = array();
		if ( ! empty( $arguments['contact_owner'] ) ) {
			$meta_query[] = array(
				'key'   => '_contact_owner',
				'value' => absint( $arguments['contact_owner'] ),
				'type'  => 'NUMERIC',
			);
		}

		// Lifecycle stage filter.
		if ( ! empty( $arguments['lifecycle_stage'] ) ) {
			$stage = sanitize_key( $arguments['lifecycle_stage'] );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::is_valid_lifecycle_stage( $stage ) ) {
				$meta_query[] = array(
					'key'   => '_lifecycle_stage',
					'value' => $stage,
				);
			}
		}

		if ( ! empty( $meta_query ) ) {
			$lead_args['meta_query'] = $meta_query;
		}

		$lead_query = new WP_Query( $lead_args );
		$lead_ids   = $lead_query->posts;

		if ( empty( $lead_ids ) ) {
			return $this->format_success_response(
				__( 'No leads found matching the criteria.', 'mcp-ai-wpoos-pro' ),
				array(
					'customers' => array(),
					'count'     => 0,
				)
			);
		}

		// --- Phase 2: Score each lead ---

		$scored = array();

		foreach ( $lead_ids as $lead_id ) {
			$score_data = $this->calculate_composite_score( $lead_id, $include_customers_only );

			if ( null === $score_data ) {
				continue; // Skip if customer-only filter excludes this lead.
			}

			if ( $min_score > 0 && $score_data['composite_score'] < $min_score ) {
				continue;
			}

			$scored[] = $score_data;
		}

		// --- Phase 3: Sort by composite score descending ---

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['composite_score'] <=> $a['composite_score'];
			}
		);

		// --- Phase 4: Slice to limit ---

		$top_customers = array_slice( $scored, 0, $limit );

		// --- Phase 5: Enrich with score labels ---

		foreach ( $top_customers as &$customer ) {
			$customer['score_label'] = class_exists( 'WP_MCP_AI_CRM_Engine' )
				? WP_MCP_AI_CRM_Engine::score_label( $customer['composite_score'] )
				: ( $customer['composite_score'] >= 70 ? 'hot' : ( $customer['composite_score'] >= 40 ? 'warm' : 'cold' ) );
		}
		unset( $customer );

		// Record audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'top_customers_identified',
				'lead',
				'',
				array(
					'count'          => count( $top_customers ),
					'min_score'      => $min_score,
					'customers_only' => $include_customers_only,
				)
			);
		}

		// --- Gate 2: Escape at exit ---
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of top customers identified */
				_n(
					'Identified %d top customer.',
					'Identified %d top customers.',
					count( $top_customers ),
					'mcp-ai-wpoos-pro'
				),
				count( $top_customers )
			),
			array(
				'customers'     => $top_customers,
				'count'         => count( $top_customers ),
				'limit'         => $limit,
				'total_leads'   => count( $lead_ids ),
				'ranked_by'     => 'composite_score',
				'scoring_model' => array(
					'lead_score_weight' => 0.40,
					'deal_value_weight' => 0.35,
					'activity_weight'   => 0.15,
					'lifecycle_weight'  => 0.10,
				),
			)
		);
	}

	/**
	 * Calculate a composite score for a single lead.
	 *
	 * Weights:
	 *   - Lead qualification score: 40%
	 *   - Associated deal pipeline value: 35%
	 *   - Activity volume: 15%
	 *   - Lifecycle stage progression: 10%
	 *
	 * @since 2.7.0
	 *
	 * @param int  $lead_id              Lead post ID.
	 * @param bool $include_customers_only Whether to skip leads not converted to customers.
	 * @return array|null Score data array, or null if excluded.
	 */
	private function calculate_composite_score( $lead_id, $include_customers_only ) {
		// Check if converted to customer.
		$source_lead_meta = get_post_meta( $lead_id, '_source_lead_id', true );
		$is_customer      = false;
		$customer_id      = null;

		// Look for customer CPT linked to this lead.
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

		if ( ! empty( $customer_query->posts ) ) {
			$is_customer = true;
			$customer_id = $customer_query->posts[0];
		}

		// If customer-only mode and not a customer, skip.
		if ( $include_customers_only && ! $is_customer ) {
			return null;
		}

		// --- Sub-score 1: Lead qualification (0–100, weight 0.40) ---
		$lead_score = (int) get_post_meta( $lead_id, '_lead_score', true );
		$lead_score = max( 0, min( 100, $lead_score ) );
		$lead_sub   = $lead_score * 0.40;

		// --- Sub-score 2: Deal pipeline value (weight 0.35) ---
		$deal_sub = $this->calculate_deal_value_score( $lead_id );

		// --- Sub-score 3: Activity volume (weight 0.15) ---
		$activity_sub = $this->calculate_activity_score( $lead_id );

		// --- Sub-score 4: Lifecycle progression (weight 0.10) ---
		$lifecycle_sub = $this->calculate_lifecycle_score( $lead_id );

		// --- Composite ---
		$composite = round( $lead_sub + $deal_sub + $activity_sub + $lifecycle_sub, 1 );

		// --- Enrichment data ---
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

		// BANT fields.
		$budget    = sanitize_text_field( (string) get_post_meta( $lead_id, '_budget', true ) );
		$authority = sanitize_text_field( (string) get_post_meta( $lead_id, '_authority', true ) );
		$need      = sanitize_text_field( (string) get_post_meta( $lead_id, '_need', true ) );
		$timeline  = sanitize_text_field( (string) get_post_meta( $lead_id, '_timeline', true ) );

		// Get associated deal count and total value.
		$deal_data      = $this->get_lead_deal_data( $lead_id );
		$activity_count = $this->get_lead_activity_count( $lead_id );

		return array(
			'lead_id'          => $lead_id,
			'title'            => $lead_title,
			'email'            => $email,
			'phone'            => $phone,
			'company'          => $company,
			'lifecycle_stage'  => ( ! empty( $lifecycle ) ? $lifecycle : 'lead' ),
			'is_customer'      => $is_customer,
			'customer_id'      => $customer_id,
			'contact_owner_id' => $owner_id,
			'contact_owner'    => $owner_name,
			'lead_score'       => $lead_score,
			'composite_score'  => $composite,
			'score_breakdown'  => array(
				'lead_score' => round( $lead_sub, 1 ),
				'deal_value' => round( $deal_sub, 1 ),
				'activity'   => round( $activity_sub, 1 ),
				'lifecycle'  => round( $lifecycle_sub, 1 ),
			),
			'bant'             => array(
				'budget'    => $budget,
				'authority' => $authority,
				'need'      => $need,
				'timeline'  => $timeline,
			),
			'deal_count'       => $deal_data['count'],
			'total_deal_value' => $deal_data['total_value'],
			'won_deal_count'   => $deal_data['won_count'],
			'activity_count'   => $activity_count,
			'created_at'       => get_the_date( 'Y-m-d H:i:s', $lead_id ),
		);
	}

	/**
	 * Calculate deal value sub-score for a lead.
	 *
	 * @since 2.7.0
	 *
	 * @param int $lead_id Lead post ID.
	 * @return float Score contribution (0–35).
	 */
	private function calculate_deal_value_score( $lead_id ) {
		$deal_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_lead_id',
						'value' => $lead_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $deal_query->posts ) ) {
			return 0;
		}

		$total_value = 0;
		$won_value   = 0;

		foreach ( $deal_query->posts as $deal_id ) {
			$amount = (float) get_post_meta( $deal_id, '_deal_amount', true );
			$stage  = sanitize_key( (string) get_post_meta( $deal_id, '_deal_stage', true ) );

			$total_value += $amount;

			if ( 'closed_won' === $stage ) {
				$won_value += $amount;
			}
		}

		// Won deals contribute fully; open deals contribute at stage probability.
		// Cap at reasonable maximum for scoring (e.g. $100k = full 35 points).
		$max_value  = 100000;
		$raw_score  = min( $won_value + ( $total_value - $won_value ) * 0.5, $max_value );
		$normalized = ( $raw_score / $max_value ) * 35;

		return round( min( 35, $normalized ), 1 );
	}

	/**
	 * Calculate activity volume sub-score for a lead.
	 *
	 * @since 2.7.0
	 *
	 * @param int $lead_id Lead post ID.
	 * @return float Score contribution (0–15).
	 */
	private function calculate_activity_score( $lead_id ) {
		$activity_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => '_lead_id',
						'value' => $lead_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$count = $activity_query->found_posts;

		// Logarithmic scaling: 1 activity = 2 pts, 50+ = 15 pts.
		if ( 0 === $count ) {
			return 0;
		}

		$normalized = min( 15, round( log( $count + 1, 50 ) * 15, 1 ) );
		return round( $normalized, 1 );
	}

	/**
	 * Calculate lifecycle progression sub-score for a lead.
	 *
	 * @since 2.7.0
	 *
	 * @param int $lead_id Lead post ID.
	 * @return float Score contribution (0–10).
	 */
	private function calculate_lifecycle_score( $lead_id ) {
		$stage = sanitize_key( (string) get_post_meta( $lead_id, '_lifecycle_stage', true ) );

		// Stage weights: higher stages = more valuable.
		$stage_weights = array(
			'subscriber'  => 0,
			'lead'        => 2,
			'mql'         => 4,
			'sal'         => 5,
			'sql'         => 7,
			'opportunity' => 8,
			'customer'    => 10,
			'evangelist'  => 10,
		);

		$weight = isset( $stage_weights[ $stage ] ) ? $stage_weights[ $stage ] : 0;
		return (float) $weight;
	}

	/**
	 * Get deal data for a lead.
	 *
	 * @since 2.7.0
	 *
	 * @param int $lead_id Lead post ID.
	 * @return array{count: int, total_value: float, won_count: int}
	 */
	private function get_lead_deal_data( $lead_id ) {
		$deal_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_lead_id',
						'value' => $lead_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$total_value = 0;
		$won_count   = 0;
		$count       = count( $deal_query->posts );

		foreach ( $deal_query->posts as $deal_id ) {
			$amount       = (float) get_post_meta( $deal_id, '_deal_amount', true );
			$stage        = sanitize_key( (string) get_post_meta( $deal_id, '_deal_stage', true ) );
			$total_value += $amount;

			if ( 'closed_won' === $stage ) {
				++$won_count;
			}
		}

		return array(
			'count'       => $count,
			'total_value' => $total_value,
			'won_count'   => $won_count,
		);
	}

	/**
	 * Get activity count for a lead.
	 *
	 * @since 2.7.0
	 *
	 * @param int $lead_id Lead post ID.
	 * @return int
	 */
	private function get_lead_activity_count( $lead_id ) {
		$activity_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => '_lead_id',
						'value' => $lead_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return $activity_query->found_posts;
	}
}
