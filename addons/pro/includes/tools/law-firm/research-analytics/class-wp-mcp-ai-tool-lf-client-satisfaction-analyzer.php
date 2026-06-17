<?php
/**
 * Client Satisfaction Analyzer Tool
 *
 * Analyzes client satisfaction by evaluating communication patterns,
 * payment timeliness, matter outcomes, and retention risk.
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
 * Analyzes client satisfaction through communication, payment, and outcome metrics.
 */
class WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_client_satisfaction_analyzer'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Client Satisfaction Analyzer', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Analyzes client satisfaction by evaluating communication responsiveness, payment timeliness, matter outcomes, and retention risk with a 0-100 satisfaction score.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'client_id' => array(
					'type'        => 'integer',
					'description' => __( 'Client user ID to analyze.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'client_id' ),
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$client_id = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;

		if ( ! $client_id ) {
			return new WP_Error( 'missing_required', __( 'Client ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client = get_userdata( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'not_found', __( 'Client not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Fetch all matters for this client.
		$matters = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_matter',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_client_satisfaction_analyzer', 0, 1000 ) : 1000,
				'post_status'    => 'any',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_lf_client_id',
					'value' => $client_id,
				),
				),
			)
		);

		if ( empty( $matters ) ) {
			return array(
				'success'    => true,
				'message'    => __( 'No matters found for this client. Unable to generate satisfaction analysis. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
				'data'       => array(
					'client_id'   => $client_id,
					'client_name' => $client->display_name,
					'matters'     => 0,
				),
				'disclaimer' => self::DISCLAIMER,
			);
		}

		// 1. Communication responsiveness analysis (25 points max).
		$total_comms         = 0;
		$total_response_days = 0;
		$response_count      = 0;
		$last_contact_date   = '';

		foreach ( $matters as $matter ) {
			$communications = get_post_meta( $matter->ID, '_lf_communications', true );
			if ( is_array( $communications ) ) {
				$total_comms += count( $communications );
				foreach ( $communications as $comm ) {
					$comm_date = isset( $comm['date'] ) ? sanitize_text_field( $comm['date'] ) : '';
					if ( ! empty( $comm_date ) && ( empty( $last_contact_date ) || $comm_date > $last_contact_date ) ) {
						$last_contact_date = $comm_date;
					}
					if ( isset( $comm['response_days'] ) ) {
						$total_response_days += (float) $comm['response_days'];
						++$response_count;
					}
				}
			}
		}

		$avg_response_days  = $response_count > 0 ? round( $total_response_days / $response_count, 1 ) : 0;
		$days_since_contact = 0;
		if ( ! empty( $last_contact_date ) ) {
			$days_since_contact = (int) ( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( $last_contact_date ) ) / DAY_IN_SECONDS );
		}

		// Score: fast responses = higher score.
		$comm_score = 25;
		if ( $avg_response_days > 5 ) {
			$comm_score = max( 0, 25 - (int) ( ( $avg_response_days - 5 ) * 2.5 ) );
		} elseif ( $avg_response_days > 2 ) {
			$comm_score = max( 10, 25 - (int) ( ( $avg_response_days - 2 ) * 3 ) );
		}

		// 2. Payment timeliness analysis (25 points max).
		$invoices_query = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_client_satisfaction_analyzer', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_lf_client_id',
					'value' => $client_id,
				),
				),
			)
		);

		$total_billed    = 0;
		$total_collected = 0;
		$overdue_count   = 0;
		$on_time_count   = 0;
		$total_invoices  = 0;
		$avg_days_to_pay = 0;
		$pay_days_total  = 0;
		$pay_days_count  = 0;

		foreach ( $invoices_query as $inv ) {
			$amount    = (float) get_post_meta( $inv->ID, '_lf_amount', true );
			$collected = (float) get_post_meta( $inv->ID, '_lf_collected_amount', true );
			$due_date  = get_post_meta( $inv->ID, '_lf_due_date', true );
			$paid_date = get_post_meta( $inv->ID, '_lf_paid_date', true );

			$total_billed    += $amount;
			$total_collected += $collected;

			if ( $amount > 0 ) {
				++$total_invoices;
			}

			if ( ! empty( $due_date ) && ! empty( $paid_date ) ) {
				$days_to_pay     = (int) ( ( strtotime( $paid_date ) - strtotime( $due_date ) ) / DAY_IN_SECONDS );
				$pay_days_total += $days_to_pay;
				++$pay_days_count;
				if ( $days_to_pay <= 0 ) {
					++$on_time_count;
				} else {
					++$overdue_count;
				}
			} elseif ( ! empty( $due_date ) && empty( $paid_date ) && strtotime( $due_date ) < time() ) {
				++$overdue_count;
			}
		}

		$collection_rate = $total_billed > 0 ? round( ( $total_collected / $total_billed ) * 100, 1 ) : 100;
		$avg_days_to_pay = $pay_days_count > 0 ? round( $pay_days_total / $pay_days_count, 1 ) : 0;

		// Payment score.
		$payment_score = 25;
		if ( $collection_rate < 50 ) {
			$payment_score = 5;
		} elseif ( $collection_rate < 75 ) {
			$payment_score = 10;
		} elseif ( $collection_rate < 90 ) {
			$payment_score = 18;
		}
		if ( $avg_days_to_pay > 30 ) {
			$payment_score = max( 0, $payment_score - 5 );
		}

		// 3. Matter outcomes analysis (25 points max).
		$total_matters   = count( $matters );
		$resolved_count  = 0;
		$favorable_count = 0;
		$active_count    = 0;

		foreach ( $matters as $matter ) {
			$status  = get_post_meta( $matter->ID, '_lf_status', true );
			$outcome = get_post_meta( $matter->ID, '_lf_outcome', true );

			if ( in_array( $status, array( 'closed', 'resolved', 'completed' ), true ) ) {
				++$resolved_count;
				if ( in_array( $outcome, array( 'favorable', 'settled', 'won' ), true ) ) {
					++$favorable_count;
				}
			} elseif ( in_array( $status, array( 'active', 'in_progress' ), true ) ) {
				++$active_count;
			}
		}

		$favorable_rate = $resolved_count > 0 ? round( ( $favorable_count / $resolved_count ) * 100, 1 ) : 0;
		$outcome_score  = $resolved_count > 0 ? (int) min( 25, round( ( $favorable_count / $resolved_count ) * 25 ) ) : 15;

		// 4. Retention signals (25 points max).
		$relationship_months = 0;
		$earliest_matter     = '';
		foreach ( $matters as $matter ) {
			if ( empty( $earliest_matter ) || $matter->post_date < $earliest_matter ) {
				$earliest_matter = $matter->post_date;
			}
		}
		if ( ! empty( $earliest_matter ) ) {
			$relationship_months = max( 1, (int) ( ( time() - strtotime( $earliest_matter ) ) / MONTH_IN_SECONDS ) );
		}

		$repeat_client   = $total_matters > 1;
		$retention_score = 15;
		if ( $repeat_client ) {
			$retention_score += min( 5, (int) ( $total_matters - 1 ) );
		}
		if ( $relationship_months > 12 ) {
			$retention_score = min( 25, $retention_score + 3 );
		}
		if ( $days_since_contact > 90 ) {
			$retention_score = max( 0, $retention_score - 8 );
		} elseif ( $days_since_contact > 60 ) {
			$retention_score = max( 0, $retention_score - 4 );
		}

		// Overall satisfaction score (0-100).
		$satisfaction_score = min( 100, max( 0, $comm_score + $payment_score + $outcome_score + $retention_score ) );

		// Retention risk assessment.
		$retention_risk = 'low';
		if ( $satisfaction_score < 40 ) {
			$retention_risk = 'high';
		} elseif ( $satisfaction_score < 65 ) {
			$retention_risk = 'medium';
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: client name, 2: score, 3: risk */
				__( 'Client "%1$s" satisfaction score: %2$d/100 (retention risk: %3$s). ', 'mcp-ai-wpoos-pro' ),
				$client->display_name,
				$satisfaction_score,
				$retention_risk
			) . self::DISCLAIMER,
			'data'       => array(
				'client_id'                    => $client_id,
				'client_name'                  => $client->display_name,
				'satisfaction_score'           => $satisfaction_score,
				'retention_risk'               => $retention_risk,
				'communication_responsiveness' => array(
					'score'                => $comm_score,
					'total_communications' => $total_comms,
					'avg_response_days'    => $avg_response_days,
					'last_contact_date'    => $last_contact_date,
					'days_since_contact'   => $days_since_contact,
				),
				'payment_timeliness'           => array(
					'score'            => $payment_score,
					'collection_rate'  => $collection_rate,
					'total_billed'     => round( $total_billed, 2 ),
					'total_collected'  => round( $total_collected, 2 ),
					'avg_days_to_pay'  => $avg_days_to_pay,
					'overdue_invoices' => $overdue_count,
					'on_time_invoices' => $on_time_count,
				),
				'matter_outcomes'              => array(
					'score'          => $outcome_score,
					'total_matters'  => $total_matters,
					'resolved'       => $resolved_count,
					'favorable'      => $favorable_count,
					'favorable_rate' => $favorable_rate,
					'active'         => $active_count,
				),
				'retention_signals'            => array(
					'score'               => $retention_score,
					'repeat_client'       => $repeat_client,
					'relationship_months' => $relationship_months,
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
