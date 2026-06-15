<?php
/**
 * Retainer Balance Monitor Tool
 *
 * Monitors client retainer balances and flags low-balance matters.
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
 * Monitors retainer balances and alerts when replenishment is needed.
 */
class WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_retainer_balance_monitor'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Retainer Balance Monitor', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Monitors retainer balances against original retainer amounts and alerts when balance falls below threshold.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Specific matter to check.', 'mcp-ai-wpoos-pro' ),
				),
				'threshold_percentage' => array(
					'type'        => 'number',
					'description' => __( 'Alert threshold as decimal (default 0.25 = 25%).', 'mcp-ai-wpoos-pro' ),
				),
				'alert_below'          => array(
					'type'        => 'number',
					'description' => __( 'Alert when balance below this dollar amount.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' ); }

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

		$matter_id   = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$threshold   = isset( $arguments['threshold_percentage'] ) ? floatval( $arguments['threshold_percentage'] ) : 0.25;
		$alert_below = isset( $arguments['alert_below'] ) ? floatval( $arguments['alert_below'] ) : 0;

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'posts_per_page' => $matter_id ? 1 : 100,
			'meta_query'     => array(
				array(
					'key'     => '_lf_retainer_amount',
					'compare' => 'EXISTS',
				),
			), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( $matter_id ) {
			$query_args['p'] = $matter_id;
		}

		$matters = get_posts( $query_args );
		$results = array();
		$alerts  = 0;

		foreach ( $matters as $m ) {
			$original = (float) get_post_meta( $m->ID, '_lf_retainer_amount', true );
			if ( $original <= 0 ) {
				continue;
			}

			// Calculate current trust balance for this matter.
			$txns = get_posts(
				array(
					'post_type'      => 'mcp_ai_lf_trust_txn',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_retainer_balance_monitor', 0, 1000 ) : 1000,
					'meta_query'     => array(
						array(
							'key'   => '_lf_matter_id',
							'value' => $m->ID,
						),
					), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);

			$balance = 0;
			foreach ( $txns as $txn ) {
				$type     = get_post_meta( $txn->ID, '_lf_txn_type', true );
				$amt      = (float) get_post_meta( $txn->ID, '_lf_amount', true );
				$balance += ( 'deposit' === $type ) ? $amt : -$amt;
			}
			$balance = round( $balance, 2 );

			$pct_remaining       = $original > 0 ? round( $balance / $original, 4 ) : 0;
			$needs_replenishment = $pct_remaining <= $threshold || ( $alert_below > 0 && $balance <= $alert_below );

			if ( $needs_replenishment ) {
				++$alerts;
			}

			$results[] = array(
				'matter_id'            => $m->ID,
				'matter_title'         => $m->post_title,
				'original_retainer'    => $original,
				'current_balance'      => $balance,
				'percentage_remaining' => round( $pct_remaining * 100, 1 ),
				'needs_replenishment'  => $needs_replenishment,
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %1$d: total matters monitored, %2$d: matters needing replenishment */
				__( 'Monitored %1$d matters, %2$d need replenishment. ', 'mcp-ai-wpoos-pro' ),
				count( $results ),
				$alerts
			) . self::DISCLAIMER,
			'data'       => array(
				'matters'         => $results,
				'total_monitored' => count( $results ),
				'alerts'          => $alerts,
				'threshold'       => $threshold,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
