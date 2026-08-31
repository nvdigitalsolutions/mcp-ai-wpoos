<?php
/**
 * /markup-stats slash command.
 *
 * Surfaces the aggregate counters maintained by
 * {@see WP_MCP_AI_Markup_Telemetry} as a Markdown report so operators
 * can observe completion and cancellation rates without going to the
 * database. Supports `--verbose`, `--json`, and `--reset` flags.
 *
 * Registered from `includes/markup-init.php` on the
 * `wp_mcp_ai_default_slash_commands_loaded` action so the slash-command
 * init file does not need editing.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlashCommandMarkupStats
 *
 * @since 1.3.0
 */
class SlashCommandMarkupStats {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Maximum tool / mode rows printed in non-verbose mode.
	 */
	const TOP_N = 5;

	/**
	 * Execute the command.
	 *
	 * @param array $args    Positional arguments (unused).
	 * @param array $flags   Parsed flag map.
	 * @param array $context Execution context (unused).
	 * @return array Command response.
	 */
	public function execute( $args, $flags, $context ) {
		unset( $args, $context );

		$verbose = isset( $flags['verbose'] ) || isset( $flags['v'] );
		$as_json = isset( $flags['json'] );
		$reset   = isset( $flags['reset'] );

		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Markup_Telemetry' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Markup telemetry recorder is not loaded.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( $reset ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Resetting markup telemetry requires the manage_options capability.', 'nvoos-content-graph-ai-platform' ) );
			}
			\WP_MCP_AI_Markup_Telemetry::reset();
			return array(
				'success' => true,
				'message' => __( 'Markup telemetry counters have been reset.', 'nvoos-content-graph-ai-platform' ),
				'data'    => \WP_MCP_AI_Markup_Telemetry::get_summary(),
			);
		}

		$summary = \WP_MCP_AI_Markup_Telemetry::get_summary();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'data'    => $summary,
			);
		}

		return array(
			'success' => true,
			'message' => $this->render_report( $summary, $verbose ),
			'data'    => $summary,
		);
	}

	/**
	 * Render the summary as a Markdown report.
	 *
	 * @param array $summary Telemetry summary.
	 * @param bool  $verbose Whether to print the full per-tool / per-mode tables.
	 * @return string
	 */
	protected function render_report( array $summary, $verbose ) {
		$counts    = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$tools     = isset( $summary['tools'] ) && is_array( $summary['tools'] ) ? $summary['tools'] : array();
		$modes     = isset( $summary['modes'] ) && is_array( $summary['modes'] ) ? $summary['modes'] : array();
		$last_seen = isset( $summary['last_seen'] ) && is_array( $summary['last_seen'] ) ? $summary['last_seen'] : array();

		$created   = isset( $counts['created'] ) ? (int) $counts['created'] : 0;
		$completed = isset( $counts['completed'] ) ? (int) $counts['completed'] : 0;
		$cancelled = isset( $counts['cancelled'] ) ? (int) $counts['cancelled'] : 0;
		$invalid   = isset( $counts['invalid'] ) ? (int) $counts['invalid'] : 0;
		$tool_err  = isset( $counts['tool_error'] ) ? (int) $counts['tool_error'] : 0;

		$completion_rate = $created > 0 ? ( $completed / $created ) * 100 : 0.0;

		$out  = "## Markup Telemetry\n\n";
		$out .= "| Metric | Count |\n";
		$out .= "|--------|------:|\n";
		$out .= sprintf( "| Requests created | %s |\n", number_format_i18n( $created ) );
		$out .= sprintf( "| Submitted | %s |\n", number_format_i18n( isset( $counts['submitted'] ) ? (int) $counts['submitted'] : 0 ) );
		$out .= sprintf( "| Validated | %s |\n", number_format_i18n( isset( $counts['validated'] ) ? (int) $counts['validated'] : 0 ) );
		$out .= sprintf( "| Completed | %s |\n", number_format_i18n( $completed ) );
		$out .= sprintf( "| Cancelled | %s |\n", number_format_i18n( $cancelled ) );
		$out .= sprintf( "| Invalid | %s |\n", number_format_i18n( $invalid ) );
		$out .= sprintf( "| Tool error | %s |\n", number_format_i18n( $tool_err ) );
		$out .= sprintf( "| Completion rate | %s%% |\n", number_format_i18n( $completion_rate, 1 ) );

		if ( 0 === $created && 0 === $completed && 0 === $cancelled ) {
			$out .= "\n_No markup events have been recorded yet._\n";
			return $out;
		}

		$out .= "\n" . $this->render_breakdown(
			/* translators: heading for the tool breakdown table. */
			__( 'By tool', 'nvoos-content-graph-ai-platform' ),
			$tools,
			$verbose
		);

		$out .= "\n" . $this->render_breakdown(
			/* translators: heading for the mode breakdown table. */
			__( 'By mode', 'nvoos-content-graph-ai-platform' ),
			$modes,
			$verbose
		);

		if ( ! empty( $last_seen ) ) {
			$out .= "\n**Last seen:**\n";
			foreach ( $last_seen as $outcome => $ts ) {
				if ( ! is_numeric( $ts ) ) {
					continue;
				}
				$out .= sprintf(
					"- %s: %s\n",
					esc_html( (string) $outcome ),
					esc_html( $this->human_time( (int) $ts ) )
				);
			}
		}

		return $out;
	}

	/**
	 * Render a per-tool or per-mode breakdown table.
	 *
	 * @param string $title   Section title.
	 * @param array  $rows    Map of slug => outcome counts.
	 * @param bool   $verbose Whether to show all rows or just the top N.
	 * @return string
	 */
	protected function render_breakdown( $title, array $rows, $verbose ) {
		if ( empty( $rows ) ) {
			return '';
		}

		// Sort by `created` count (descending), then by completed.
		uasort(
			$rows,
			static function ( $a, $b ) {
				$a_created = isset( $a['created'] ) ? (int) $a['created'] : 0;
				$b_created = isset( $b['created'] ) ? (int) $b['created'] : 0;
				if ( $a_created === $b_created ) {
					$a_done = isset( $a['completed'] ) ? (int) $a['completed'] : 0;
					$b_done = isset( $b['completed'] ) ? (int) $b['completed'] : 0;
					return $b_done - $a_done;
				}
				return $b_created - $a_created;
			}
		);

		if ( ! $verbose ) {
			$rows = array_slice( $rows, 0, self::TOP_N, true );
		}

		$out  = sprintf( "**%s:**\n\n", esc_html( $title ) );
		$out .= "| Slug | Created | Completed | Cancelled | Invalid | Tool error |\n";
		$out .= "|------|--------:|----------:|----------:|--------:|-----------:|\n";
		foreach ( $rows as $slug => $row ) {
			$out .= sprintf(
				"| `%s` | %s | %s | %s | %s | %s |\n",
				esc_html( (string) $slug ),
				number_format_i18n( isset( $row['created'] ) ? (int) $row['created'] : 0 ),
				number_format_i18n( isset( $row['completed'] ) ? (int) $row['completed'] : 0 ),
				number_format_i18n( isset( $row['cancelled'] ) ? (int) $row['cancelled'] : 0 ),
				number_format_i18n( isset( $row['invalid'] ) ? (int) $row['invalid'] : 0 ),
				number_format_i18n( isset( $row['tool_error'] ) ? (int) $row['tool_error'] : 0 )
			);
		}
		return $out;
	}

	/**
	 * Format a unix timestamp for display.
	 *
	 * @param int $ts Unix timestamp (UTC).
	 * @return string
	 */
	protected function human_time( $ts ) {
		if ( $ts <= 0 ) {
			return __( 'never', 'nvoos-content-graph-ai-platform' );
		}
		$diff = human_time_diff( $ts, time() );
		return sprintf(
			/* translators: %s: human time difference, e.g. "5 minutes ago". */
			__( '%s ago', 'nvoos-content-graph-ai-platform' ),
			$diff
		);
	}
}
