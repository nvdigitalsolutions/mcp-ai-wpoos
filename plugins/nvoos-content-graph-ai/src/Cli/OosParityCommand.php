<?php
/**
 * OOS parity CLI (Wave E6, sub-cluster 1).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_CLI_OOS_Parity_Command` (`includes/cli/`): byte-identical
 * aggregate totals (`runs`, `errors`, `cancelled`, `tool_calls`,
 * `tool_errors`, `suppressed`, `duration_ms`, `cost_usd`,
 * `prompt_tokens`, `completion_tokens`, `no_response`), the computed
 * rates (average duration, run-error %, tool-error %), the report line
 * format, the per-run `diff` table (field/value rows, scalars as
 * strings, compounds as JSON), and the `last` run-id shorthand.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - Command path `wp nvoos-cg-ai oos parity` (the base owns
 *    `wp mcp-ai oos parity` in monolith installs); registered
 *    standalone-only via `Cli::registerCommands()`.
	 *  - The data logic lives in `aggregate()` / `diff_rows()` (no WP-CLI
	 *    dependency) so the characterization suite can exercise it without
	 *    the WP-CLI runtime; `report()` / `diff()` are the thin wrappers —
	 *    same pattern as the addon's other CLI commands. Per the
	 *    `src/Cli/README.md` convention the class never references
	 *    `WP_CLI_*` symbols at class-load time (no `WP_CLI_Command`
	 *    parent — only the wrapper method bodies touch `WP_CLI`).
	 *  - The store reads resolve through this addon's `OosShadowRunner`
	 *    (the base class does not exist standalone).
	 *
	 * @since 1.1.0
	 * @package NvoosContentGraphAi\Cli
	 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Cli;

use NvoosContentGraphAi\Engine\OosShadowRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OOS parity commands.
 *
 * ## EXAMPLES
 *
 *     wp nvoos-cg-ai oos parity
 *     wp nvoos-cg-ai oos parity --limit=50
 *     wp nvoos-cg-ai oos parity diff oos_shadow_abc123
 *
 * @since 1.1.0
 */
final class OosParityCommand {

	/**
	 * Aggregate parity metrics across stored shadow runs.
	 *
	 * @param int $limit Number of recent runs to aggregate (clamped 1–100).
	 * @return array Aggregate totals + computed rates.
	 */
	public static function aggregate( int $limit = 25 ): array {
		$runs = OosShadowRunner::get_runs( $limit );

		$totals = array(
			'runs'              => 0,
			'errors'            => 0,
			'cancelled'         => 0,
			'tool_calls'        => 0,
			'tool_errors'       => 0,
			'suppressed'        => 0,
			'duration_ms'       => 0,
			'cost_usd'          => 0.0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'no_response'       => 0,
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

			$totals['tool_calls']        += (int) ( $run['tool_calls'] ?? 0 );
			$totals['tool_errors']       += (int) ( $run['tool_errors'] ?? 0 );
			$totals['suppressed']        += (int) ( $run['suppressed'] ?? 0 );
			$totals['duration_ms']       += (int) ( $run['duration_ms'] ?? 0 );
			$totals['cost_usd']          += (float) ( $run['cost_usd'] ?? 0.0 );
			$totals['prompt_tokens']     += (int) ( $run['prompt_tokens'] ?? 0 );
			$totals['completion_tokens'] += (int) ( $run['completion_tokens'] ?? 0 );
		}

		$totals['avg_duration_ms'] = $totals['runs'] > 0 ? (int) round( $totals['duration_ms'] / $totals['runs'] ) : 0;
		$totals['error_rate']      = $totals['runs'] > 0 ? round( ( $totals['errors'] / $totals['runs'] ) * 100, 1 ) : 0.0;
		$totals['tool_error_rate'] = $totals['tool_calls'] > 0 ? round( ( $totals['tool_errors'] / $totals['tool_calls'] ) * 100, 1 ) : 0.0;

		return $totals;
	}

	/**
	 * Build the field/value table rows for one stored run.
	 *
	 * @param array $run Stored run record.
	 * @return array[]
	 */
	public static function diff_rows( array $run ): array {
		$rows = array();
		foreach ( $run as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => \is_scalar( $value ) ? (string) $value : \wp_json_encode( $value ),
			);
		}

		return $rows;
	}

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
	 * @return void
	 */
	public function report( $args, $assoc_args ): void {
		$limit = isset( $assoc_args['limit'] ) ? \absint( $assoc_args['limit'] ) : 25;
		$runs  = OosShadowRunner::get_runs( $limit );

		if ( empty( $runs ) ) {
			\WP_CLI::success( 'No shadow runs recorded. Enable shadow mode first (enable_oos_shadow setting or wp_mcp_ai_oos_shadow_enabled filter).' );

			return;
		}

		$totals = self::aggregate( $limit );

		\WP_CLI::line( sprintf( 'Runs sampled: %d (last %d stored)', $totals['runs'], $limit ) );
		\WP_CLI::line( sprintf( 'Run errors:   %d (%.1f%%)', $totals['errors'], $totals['error_rate'] ) );
		\WP_CLI::line( sprintf( 'Cancelled:    %d', $totals['cancelled'] ) );
		\WP_CLI::line( sprintf( 'No response:  %d', $totals['no_response'] ) );
		\WP_CLI::line( sprintf( 'Avg duration: %d ms', $totals['avg_duration_ms'] ) );
		\WP_CLI::line( sprintf( 'Tool calls:   %d (%.1f%% errored, %d write-suppressed)', $totals['tool_calls'], $totals['tool_error_rate'], $totals['suppressed'] ) );
		\WP_CLI::line( sprintf( 'Shadow cost:  $%.6f (%d prompt + %d completion tokens)', $totals['cost_usd'], $totals['prompt_tokens'], $totals['completion_tokens'] ) );
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
	 * @return void
	 */
	public function diff( $args, $assoc_args ): void {
		$run_id = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $run_id ) {
			\WP_CLI::error( 'Provide a run id (or "last").' );
		}

		$run = 'last' === $run_id
			? OosShadowRunner::get_runs( 1 )[0] ?? null
			: OosShadowRunner::get_run( $run_id );

		if ( null === $run ) {
			\WP_CLI::error( 'Run not found in the store.' );
		}

		\WP_CLI\Utils\format_items( 'table', self::diff_rows( $run ), array( 'field', 'value' ) );
	}
}
