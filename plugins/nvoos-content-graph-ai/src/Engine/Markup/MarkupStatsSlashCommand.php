<?php
/**
 * /markup-stats slash command (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Slash_Command_Markup_Stats`
 * (`includes/slash-commands/commands/`): byte-identical TOP_N cap, the
 * verbose/json/reset flag surface, the `manage_options` reset gate, the
 * Markdown report format (metrics table, completion rate, per-tool /
 * per-mode breakdowns sorted by created→completed, the empty-state
 * copy, the last-seen list), and the human-time helper.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - Reads this addon's `MarkupTelemetry` (the base class does not
 *    exist standalone).
 *  - Registered standalone-only by `MarkupBootstrap` on the
 *    `wp_mcp_ai_default_slash_commands_loaded` action (the base init
 *    registers the base command monolith; the platform addon's
 *    dormant `SlashCommandMarkupStats` copy from the E2 blanket port
 *    stays unwired — this class is the live standalone command).
 *  - `WP_Error` is fully qualified.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * /markup-stats slash command.
 *
 * @since 1.1.0
 */
class MarkupStatsSlashCommand {

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
	 * @return array|\WP_Error Command response.
	 */
	public function execute( $args, $flags, $context ) {
		unset( $args, $context );

		$verbose = isset( $flags['verbose'] ) || isset( $flags['v'] );
		$as_json = isset( $flags['json'] );
		$reset   = isset( $flags['reset'] );

		if ( $reset ) {
			if ( ! \current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Resetting markup telemetry requires the manage_options capability.', 'nvoos-content-graph-ai' ) );
			}
			MarkupTelemetry::reset();
			return array(
				'success' => true,
				'message' => __( 'Markup telemetry counters have been reset.', 'nvoos-content-graph-ai' ),
				'data'    => MarkupTelemetry::get_summary(),
			);
		}

		$summary = MarkupTelemetry::get_summary();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => \wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
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
		$counts    = isset( $summary['counts'] ) && \is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$tools     = isset( $summary['tools'] ) && \is_array( $summary['tools'] ) ? $summary['tools'] : array();
		$modes     = isset( $summary['modes'] ) && \is_array( $summary['modes'] ) ? $summary['modes'] : array();
		$last_seen = isset( $summary['last_seen'] ) && \is_array( $summary['last_seen'] ) ? $summary['last_seen'] : array();

		$created   = isset( $counts['created'] ) ? (int) $counts['created'] : 0;
		$completed = isset( $counts['completed'] ) ? (int) $counts['completed'] : 0;
		$cancelled = isset( $counts['cancelled'] ) ? (int) $counts['cancelled'] : 0;
		$invalid   = isset( $counts['invalid'] ) ? (int) $counts['invalid'] : 0;
		$tool_err  = isset( $counts['tool_error'] ) ? (int) $counts['tool_error'] : 0;

		$completion_rate = $created > 0 ? ( $completed / $created ) * 100 : 0.0;

		$out  = "## Markup Telemetry\n\n";
		$out .= "| Metric | Count |\n";
		$out .= "|--------|------:|\n";
		$out .= \sprintf( "| Requests created | %s |\n", \number_format_i18n( $created ) );
		$out .= \sprintf( "| Submitted | %s |\n", \number_format_i18n( isset( $counts['submitted'] ) ? (int) $counts['submitted'] : 0 ) );
		$out .= \sprintf( "| Validated | %s |\n", \number_format_i18n( isset( $counts['validated'] ) ? (int) $counts['validated'] : 0 ) );
		$out .= \sprintf( "| Completed | %s |\n", \number_format_i18n( $completed ) );
		$out .= \sprintf( "| Cancelled | %s |\n", \number_format_i18n( $cancelled ) );
		$out .= \sprintf( "| Invalid | %s |\n", \number_format_i18n( $invalid ) );
		$out .= \sprintf( "| Tool error | %s |\n", \number_format_i18n( $tool_err ) );
		$out .= \sprintf( "| Completion rate | %s%% |\n", \number_format_i18n( $completion_rate, 1 ) );

		if ( 0 === $created && 0 === $completed && 0 === $cancelled ) {
			$out .= "\n_No markup events have been recorded yet._\n";
			return $out;
		}

		$out .= "\n" . $this->render_breakdown(
			/* translators: heading for the tool breakdown table. */
			__( 'By tool', 'nvoos-content-graph-ai' ),
			$tools,
			$verbose
		);

		$out .= "\n" . $this->render_breakdown(
			/* translators: heading for the mode breakdown table. */
			__( 'By mode', 'nvoos-content-graph-ai' ),
			$modes,
			$verbose
		);

		if ( ! empty( $last_seen ) ) {
			$out .= "\n**Last seen:**\n";
			foreach ( $last_seen as $outcome => $ts ) {
				if ( ! \is_numeric( $ts ) ) {
					continue;
				}
				$out .= \sprintf(
					"- %s: %s\n",
					\esc_html( (string) $outcome ),
					\esc_html( $this->human_time( (int) $ts ) )
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
		\uasort(
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
			$rows = \array_slice( $rows, 0, self::TOP_N, true );
		}

		$out  = \sprintf( "**%s:**\n\n", \esc_html( $title ) );
		$out .= "| Slug | Created | Completed | Cancelled | Invalid | Tool error |\n";
		$out .= "|------|--------:|----------:|----------:|--------:|-----------:|\n";
		foreach ( $rows as $slug => $row ) {
			$out .= \sprintf(
				"| `%s` | %s | %s | %s | %s | %s |\n",
				\esc_html( (string) $slug ),
				\number_format_i18n( isset( $row['created'] ) ? (int) $row['created'] : 0 ),
				\number_format_i18n( isset( $row['completed'] ) ? (int) $row['completed'] : 0 ),
				\number_format_i18n( isset( $row['cancelled'] ) ? (int) $row['cancelled'] : 0 ),
				\number_format_i18n( isset( $row['invalid'] ) ? (int) $row['invalid'] : 0 ),
				\number_format_i18n( isset( $row['tool_error'] ) ? (int) $row['tool_error'] : 0 )
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
			return __( 'never', 'nvoos-content-graph-ai' );
		}
		$diff = \human_time_diff( $ts, \time() );
		return \sprintf(
			/* translators: %s: human time difference, e.g. "5 minutes ago". */
			__( '%s ago', 'nvoos-content-graph-ai' ),
			$diff
		);
	}
}
