<?php
/**
 * Matter Analytics Generator Tool
 *
 * Generates detailed analytics for a specific matter including time,
 * budget, deadline compliance, and communication metrics.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates per-matter analytics: time spent, budget status, deadline compliance, and communication.
 */
class WP_MCP_AI_Tool_LF_Matter_Analytics_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_matter_analytics_generator'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Matter Analytics Generator', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generates detailed analytics for a specific matter including time analysis, budget status, deadline compliance, and communication frequency.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id' => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID to analyze.', 'mcp-ai-wpoos-pro' ),
				),
				'metrics'   => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'time_spent', 'budget_status', 'deadline_compliance', 'communication_frequency' ),
					),
					'description' => __( 'Metrics to include in the report. Defaults to all.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$metrics   = isset( $arguments['metrics'] ) && is_array( $arguments['metrics'] ) ? array_map( 'sanitize_text_field', $arguments['metrics'] ) : array( 'time_spent', 'budget_status', 'deadline_compliance', 'communication_frequency' );

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$allowed_metrics = array( 'time_spent', 'budget_status', 'deadline_compliance', 'communication_frequency' );
		$metrics         = array_intersect( $metrics, $allowed_metrics );
		if ( empty( $metrics ) ) {
			$metrics = $allowed_metrics;
		}

		$result_data = array(
			'matter_id'    => $matter_id,
			'matter_title' => $matter->post_title,
			'status'       => get_post_meta( $matter_id, '_lf_status', true ),
		);

		// Time analysis.
		if ( in_array( 'time_spent', $metrics, true ) ) {
			$entries = get_posts(
				array(
					'post_type'      => 'mcp_ai_lf_time_entry',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_matter_analytics_generator', 0, 1000 ) : 1000,
					'meta_query'     => array(
						array(
							'key'   => '_lf_matter_id',
							'value' => $matter_id,
						),
					), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);

			$billable_hours     = 0;
			$non_billable_hours = 0;
			$total_amount       = 0;
			$by_attorney        = array();
			$by_task_type       = array();

			foreach ( $entries as $entry ) {
				$hours     = (float) get_post_meta( $entry->ID, '_lf_hours', true );
				$amount    = (float) get_post_meta( $entry->ID, '_lf_amount', true );
				$type      = get_post_meta( $entry->ID, '_lf_billing_type', true );
				$task_type = get_post_meta( $entry->ID, '_lf_task_type', true );
				$author_id = $entry->post_author;

				if ( 'billable' === $type ) {
					$billable_hours += $hours;
				} else {
					$non_billable_hours += $hours;
				}
				$total_amount += $amount;

				if ( ! isset( $by_attorney[ $author_id ] ) ) {
					$user                      = get_userdata( $author_id );
					$by_attorney[ $author_id ] = array(
						'name'  => $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
						'hours' => 0,
					);
				}
				$by_attorney[ $author_id ]['hours'] += $hours;

				$task_key = ! empty( $task_type ) ? $task_type : 'general';
				if ( ! isset( $by_task_type[ $task_key ] ) ) {
					$by_task_type[ $task_key ] = 0;
				}
				$by_task_type[ $task_key ] += $hours;
			}

			$result_data['time_analysis'] = array(
				'total_hours'        => round( $billable_hours + $non_billable_hours, 1 ),
				'billable_hours'     => round( $billable_hours, 1 ),
				'non_billable_hours' => round( $non_billable_hours, 1 ),
				'total_amount'       => round( $total_amount, 2 ),
				'by_attorney'        => array_values( $by_attorney ),
				'by_task_type'       => $by_task_type,
			);
		}

		// Budget analysis.
		if ( in_array( 'budget_status', $metrics, true ) ) {
			$budget = (float) get_post_meta( $matter_id, '_lf_budget', true );
			$spent  = isset( $result_data['time_analysis']['total_amount'] ) ? $result_data['time_analysis']['total_amount'] : 0;

			if ( 0 === $spent && ! in_array( 'time_spent', $metrics, true ) ) {
				// Recalculate if time_spent was not already computed.
				$budget_entries = get_posts(
					array(
						'post_type'      => 'mcp_ai_lf_time_entry',
						'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_matter_analytics_generator', 0, 1000 ) : 1000,
						'meta_query'     => array(
							array(
								'key'   => '_lf_matter_id',
								'value' => $matter_id,
							),
						), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					)
				);
				foreach ( $budget_entries as $be ) {
					$spent += (float) get_post_meta( $be->ID, '_lf_amount', true );
				}
			}

			$expenses      = get_post_meta( $matter_id, '_lf_expenses', true );
			$expense_total = 0;
			if ( is_array( $expenses ) ) {
				foreach ( $expenses as $exp ) {
					$expense_total += (float) ( $exp['amount'] ?? 0 );
				}
			}

			$total_spent     = round( $spent + $expense_total, 2 );
			$remaining       = round( $budget - $total_spent, 2 );
			$utilization_pct = $budget > 0 ? round( ( $total_spent / $budget ) * 100, 1 ) : 0;

			$budget_status = 'on_track';
			if ( $utilization_pct >= 100 ) {
				$budget_status = 'over_budget';
			} elseif ( $utilization_pct >= 85 ) {
				$budget_status = 'at_risk';
			}

			$result_data['budget_analysis'] = array(
				'budget'          => $budget,
				'total_spent'     => $total_spent,
				'remaining'       => $remaining,
				'utilization_pct' => $utilization_pct,
				'status'          => $budget_status,
				'expense_total'   => round( $expense_total, 2 ),
			);
		}

		// Deadline compliance.
		if ( in_array( 'deadline_compliance', $metrics, true ) ) {
			$deadlines        = get_post_meta( $matter_id, '_lf_deadlines', true );
			$total_deadlines  = 0;
			$met_deadlines    = 0;
			$missed_deadlines = 0;
			$upcoming         = array();
			$now              = current_time( 'Y-m-d' );

			if ( is_array( $deadlines ) ) {
				foreach ( $deadlines as $deadline ) {
					++$total_deadlines;
					$dl_date   = isset( $deadline['date'] ) ? sanitize_text_field( $deadline['date'] ) : '';
					$dl_status = isset( $deadline['status'] ) ? sanitize_text_field( $deadline['status'] ) : 'pending';

					if ( 'completed' === $dl_status ) {
						++$met_deadlines;
					} elseif ( ! empty( $dl_date ) && $dl_date < $now && 'completed' !== $dl_status ) {
						++$missed_deadlines;
					}

					if ( ! empty( $dl_date ) && $dl_date >= $now && 'completed' !== $dl_status ) {
						$upcoming[] = array(
							'description' => isset( $deadline['description'] ) ? sanitize_text_field( $deadline['description'] ) : '',
							'date'        => $dl_date,
							'days_until'  => (int) ( ( strtotime( $dl_date ) - strtotime( $now ) ) / DAY_IN_SECONDS ),
						);
					}
				}
			}

			$compliance_rate = $total_deadlines > 0 ? round( ( $met_deadlines / $total_deadlines ) * 100, 1 ) : 100;

			$result_data['deadline_compliance'] = array(
				'total_deadlines' => $total_deadlines,
				'met'             => $met_deadlines,
				'missed'          => $missed_deadlines,
				'compliance_rate' => $compliance_rate,
				'upcoming'        => $upcoming,
			);
		}

		// Communication frequency.
		if ( in_array( 'communication_frequency', $metrics, true ) ) {
			$communications = get_post_meta( $matter_id, '_lf_communications', true );
			$total_comms    = 0;
			$by_type        = array();
			$last_contact   = '';

			if ( is_array( $communications ) ) {
				$total_comms = count( $communications );
				foreach ( $communications as $comm ) {
					$comm_type = isset( $comm['type'] ) ? sanitize_text_field( $comm['type'] ) : 'other';
					if ( ! isset( $by_type[ $comm_type ] ) ) {
						$by_type[ $comm_type ] = 0;
					}
					++$by_type[ $comm_type ];

					$comm_date = isset( $comm['date'] ) ? sanitize_text_field( $comm['date'] ) : '';
					if ( ! empty( $comm_date ) && ( empty( $last_contact ) || $comm_date > $last_contact ) ) {
						$last_contact = $comm_date;
					}
				}
			}

			$days_since_contact = 0;
			if ( ! empty( $last_contact ) ) {
				$days_since_contact = (int) ( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( $last_contact ) ) / DAY_IN_SECONDS );
			}

			// Calculate the matter age in weeks for frequency.
			$matter_created = $matter->post_date;
			$weeks_active   = max( 1, (int) ( ( time() - strtotime( $matter_created ) ) / WEEK_IN_SECONDS ) );

			$result_data['communication_frequency'] = array(
				'total_communications' => $total_comms,
				'by_type'              => $by_type,
				'last_contact_date'    => $last_contact,
				'days_since_contact'   => $days_since_contact,
				'avg_per_week'         => round( $total_comms / $weeks_active, 1 ),
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: matter title, 2: metric count */
				__( 'Analytics generated for "%1$s" across %2$d metrics. ', 'mcp-ai-wpoos-pro' ),
				$matter->post_title,
				count( $metrics )
			) . self::DISCLAIMER,
			'data'       => $result_data,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
