<?php
/**
 * CLI: OOS parity reporting — Proposal 029, Phase 4.
 *
 * Reads the shadow-run store written by WP_MCP_AI_OOS_Shadow_Runner and
 * renders parity reports: aggregate metrics across sampled runs and a
 * per-run detail view. This is the operator surface for the shadow
 * rollout gate (tool-success delta, cost delta, latency).
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * OOS parity commands.
 *
 * ## EXAMPLES
 *
 *     wp mcp-ai oos parity report
 *     wp mcp-ai oos parity report --limit=50
 *     wp mcp-ai oos parity diff oos_shadow_abc123
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_OOS_Parity_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Aggregate parity report across stored shadow runs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Number of recent runs to aggregate (default: 25, max 100).
	 * ---
	 * default: 25
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function report( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_OOS_Shadow_Runner' ) ) {
			WP_CLI::error( 'The OOS shadow runner is not loaded (lib/ directory absent).' );
		}

		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 25;
		$runs  = WP_MCP_AI_OOS_Shadow_Runner::get_runs( $limit );

		if ( empty( $runs ) ) {
			WP_CLI::success( 'No shadow runs recorded. Enable shadow mode first (enable_oos_shadow setting or wp_mcp_ai_oos_shadow_enabled filter).' );

			return;
		}

		$totals = array(
			'runs'           => 0,
			'errors'         => 0,
			'cancelled'      => 0,
			'tool_calls'     => 0,
			'tool_errors'    => 0,
			'suppressed'     => 0,
			'duration_ms'    => 0,
			'cost_usd'       => 0.0,
			'prompt_tokens'  => 0,
			'completion_tokens' => 0,
			'no_response'    => 0,
		);

		foreach ( $runs as $run ) {
			++$totals['runs'];

			if ( ! empty( $run['error'] ) ) {
				++$totals['errors'];
			}
			if ( ! empty( $run['cancelled'] ) ) {
				++$totals['cancelled'];
			}
			if ( empty( $run['has_response'] ) ) {
				++$totals['no_response'];
			}

			$totals['tool_calls']       += (int) ( $run['tool_calls'] ?? 0 );
			$totals['tool_errors']      += (int) ( $run['tool_errors'] ?? 0 );
			$totals['suppressed']       += (int) ( $run['suppressed'] ?? 0 );
			$totals['duration_ms']      += (int) ( $run['duration_ms'] ?? 0 );
			$totals['cost_usd']         += (float) ( $run['cost_usd'] ?? 0.0 );
			$totals['prompt_tokens']    += (int) ( $run['prompt_tokens'] ?? 0 );
			$totals['completion_tokens'] += (int) ( $run['completion_tokens'] ?? 0 );
		}

		$avg_duration = $totals['runs'] > 0 ? (int) round( $totals['duration_ms'] / $totals['runs'] ) : 0;
		$error_rate   = $totals['runs'] > 0 ? round( ( $totals['errors'] / $totals['runs'] ) * 100, 1 ) : 0.0;
		$tool_error_rate = $totals['tool_calls'] > 0 ? round( ( $totals['tool_errors'] / $totals['tool_calls'] ) * 100, 1 ) : 0.0;

		WP_CLI::line( sprintf( 'Runs sampled: %d (last %d stored)', $totals['runs'], $limit ) );
		WP_CLI::line( sprintf( 'Run errors:   %d (%.1f%%)', $totals['errors'], $error_rate ) );
		WP_CLI::line( sprintf( 'Cancelled:    %d', $totals['cancelled'] ) );
		WP_CLI::line( sprintf( 'No response:  %d', $totals['no_response'] ) );
		WP_CLI::line( sprintf( 'Avg duration: %d ms', $avg_duration ) );
		WP_CLI::line( sprintf( 'Tool calls:   %d (%.1f%% errored, %d write-suppressed)', $totals['tool_calls'], $tool_error_rate, $totals['suppressed'] ) );
		WP_CLI::line( sprintf( 'Shadow cost:  $%.6f (%d prompt + %d completion tokens)', $totals['cost_usd'], $totals['prompt_tokens'], $totals['completion_tokens'] ) );
	}

	/**
	 * Show one stored shadow run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run identifier from the report listing (or 'last').
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function diff( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_OOS_Shadow_Runner' ) ) {
			WP_CLI::error( 'The OOS shadow runner is not loaded (lib/ directory absent).' );
		}

		$run_id = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $run_id ) {
			WP_CLI::error( 'Provide a run id (or "last").' );
		}

		$run = 'last' === $run_id
			? WP_MCP_AI_OOS_Shadow_Runner::get_runs( 1 )[0] ?? null
			: WP_MCP_AI_OOS_Shadow_Runner::get_run( $run_id );

		if ( null === $run ) {
			WP_CLI::error( 'Run not found in the store.' );
		}

		$rows = array();
		foreach ( $run as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}

WP_CLI::add_command( 'mcp-ai oos parity', 'WP_MCP_AI_CLI_OOS_Parity_Command' );
