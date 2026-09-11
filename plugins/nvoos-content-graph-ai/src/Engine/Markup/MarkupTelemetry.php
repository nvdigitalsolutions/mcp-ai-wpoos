<?php
/**
 * Markup telemetry recorder (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Telemetry`
 * (`includes/markup/`): byte-identical option key
 * (`wp_mcp_ai_markup_telemetry`, non-autoloaded), the seven-outcome
 * bucket set, the default-summary shape, the four lifecycle-action
 * subscriptions, the bounded per-tool (100) / per-mode (32) breakdowns
 * with the `_other` overflow buckets, the read-only `get_summary()`
 * merge, `reset()`, and the O(1) `record()` path.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The structured-log bridge is monolith-only
 *    (`WP_MCP_AI_Logger`), dormant standalone.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Markup subsystem telemetry recorder.
 *
 * @since 1.1.0
 */
class MarkupTelemetry {

	/**
	 * Option key for the aggregate counter store.
	 */
	const OPTION_NAME = 'wp_mcp_ai_markup_telemetry';

	/**
	 * Per-tool / per-mode breakdown caps. Prevents the option from
	 * growing unbounded on installs that exercise lots of distinct
	 * slugs (e.g. dozens of pro tools or experimental modes).
	 */
	const MAX_TOOLS = 100;
	const MAX_MODES = 32;

	/**
	 * Outcome buckets tracked at the top level.
	 *
	 * @return array<string>
	 */
	public static function outcomes() {
		return array( 'created', 'submitted', 'validated', 'completed', 'cancelled', 'invalid', 'tool_error' );
	}

	/**
	 * Empty / default telemetry shape.
	 *
	 * @return array
	 */
	public static function default_summary() {
		$counts = array();
		foreach ( self::outcomes() as $outcome ) {
			$counts[ $outcome ] = 0;
		}
		return array(
			'counts'    => $counts,
			'last_seen' => array(),
			'tools'     => array(),
			'modes'     => array(),
		);
	}

	/**
	 * Hook the lifecycle actions.
	 *
	 * @return void
	 */
	public function register() {
		\add_action( 'wp_mcp_ai_markup_request_created', array( $this, 'on_request_created' ), 10, 2 );
		\add_action( 'wp_mcp_ai_markup_submitted', array( $this, 'on_submitted' ), 10, 1 );
		\add_action( 'wp_mcp_ai_markup_validated', array( $this, 'on_validated' ), 10, 2 );
		\add_action( 'wp_mcp_ai_markup_resolved', array( $this, 'on_resolved' ), 10, 2 );
	}

	/**
	 * Read-only summary for slash commands / admin panels.
	 *
	 * @return array
	 */
	public static function get_summary() {
		$stored = \get_option( self::OPTION_NAME, array() );
		if ( ! \is_array( $stored ) ) {
			return self::default_summary();
		}
		// Merge with defaults so callers always get the full shape.
		$summary = self::default_summary();
		if ( isset( $stored['counts'] ) && \is_array( $stored['counts'] ) ) {
			foreach ( $summary['counts'] as $key => $_ ) {
				if ( isset( $stored['counts'][ $key ] ) ) {
					$summary['counts'][ $key ] = (int) $stored['counts'][ $key ];
				}
			}
		}
		foreach ( array( 'last_seen', 'tools', 'modes' ) as $key ) {
			if ( isset( $stored[ $key ] ) && \is_array( $stored[ $key ] ) ) {
				$summary[ $key ] = $stored[ $key ];
			}
		}
		return $summary;
	}

	/**
	 * Reset all counters. Primarily useful for tests.
	 *
	 * @return void
	 */
	public static function reset() {
		\delete_option( self::OPTION_NAME );
	}

	/**
	 * Handler: a new markup request entered the store.
	 *
	 * @param mixed $request Markup request (expected MarkupRequest).
	 * @param mixed $tool    Tool instance that produced the request.
	 * @return void
	 */
	public function on_request_created( $request, $tool = null ) {
		unset( $tool );
		$tool_slug = $this->extract_tool_slug( $request );
		$mode      = $this->extract_mode( $request );
		$this->record( 'created', $tool_slug, $mode );
	}

	/**
	 * Handler: the user submitted a payload (before validation).
	 *
	 * @param mixed $request Markup request.
	 * @return void
	 */
	public function on_submitted( $request ) {
		$tool_slug = $this->extract_tool_slug( $request );
		$mode      = $this->extract_mode( $request );
		$this->record( 'submitted', $tool_slug, $mode );
	}

	/**
	 * Handler: payload passed validation.
	 *
	 * @param mixed $request Markup request.
	 * @param mixed $cleaned Cleaned payload (unused).
	 * @return void
	 */
	public function on_validated( $request, $cleaned = null ) {
		unset( $cleaned );
		$tool_slug = $this->extract_tool_slug( $request );
		$mode      = $this->extract_mode( $request );
		$this->record( 'validated', $tool_slug, $mode );
	}

	/**
	 * Handler: terminal state reached.
	 *
	 * Maps the second argument (status string) onto the matching outcome
	 * bucket. Recognised values: completed, cancelled, invalid, tool_error.
	 * Unknown statuses are ignored to keep the option clean.
	 *
	 * @param mixed  $request Markup request.
	 * @param string $status  Resolution status.
	 * @return void
	 */
	public function on_resolved( $request, $status = '' ) {
		$status = \is_string( $status ) ? \sanitize_key( $status ) : '';
		if ( ! \in_array( $status, array( 'completed', 'cancelled', 'invalid', 'tool_error' ), true ) ) {
			return;
		}
		$tool_slug = $this->extract_tool_slug( $request );
		$mode      = $this->extract_mode( $request );
		$this->record( $status, $tool_slug, $mode );
	}

	/**
	 * Record a single event: bump aggregate counters and bridge to the logger.
	 *
	 * @param string $outcome   Outcome bucket.
	 * @param string $tool_slug Tool slug ('' if unknown).
	 * @param string $mode      Mode slug ('' if unknown).
	 * @return void
	 */
	protected function record( $outcome, $tool_slug, $mode ) {
		if ( ! \in_array( $outcome, self::outcomes(), true ) ) {
			return;
		}

		$summary = self::get_summary();

		$summary['counts'][ $outcome ] = isset( $summary['counts'][ $outcome ] )
			? (int) $summary['counts'][ $outcome ] + 1
			: 1;

		$summary['last_seen'][ $outcome ] = \time();

		if ( '' !== $tool_slug ) {
			if ( ! isset( $summary['tools'][ $tool_slug ] ) ) {
				if ( \count( $summary['tools'] ) >= self::MAX_TOOLS ) {
					// Bucket overflow into a single "_other" key to preserve totals.
					$tool_slug = '_other';
					if ( ! isset( $summary['tools'][ $tool_slug ] ) ) {
						$summary['tools'][ $tool_slug ] = \array_fill_keys( self::outcomes(), 0 );
					}
				} else {
					$summary['tools'][ $tool_slug ] = \array_fill_keys( self::outcomes(), 0 );
				}
			}
			$summary['tools'][ $tool_slug ][ $outcome ] = (int) $summary['tools'][ $tool_slug ][ $outcome ] + 1;
		}

		if ( '' !== $mode ) {
			if ( ! isset( $summary['modes'][ $mode ] ) ) {
				if ( \count( $summary['modes'] ) >= self::MAX_MODES ) {
					$mode = '_other';
					if ( ! isset( $summary['modes'][ $mode ] ) ) {
						$summary['modes'][ $mode ] = \array_fill_keys( self::outcomes(), 0 );
					}
				} else {
					$summary['modes'][ $mode ] = \array_fill_keys( self::outcomes(), 0 );
				}
			}
			$summary['modes'][ $mode ][ $outcome ] = (int) $summary['modes'][ $mode ][ $outcome ] + 1;
		}

		// Persist without autoload to avoid bloating the alloptions cache.
		\update_option( self::OPTION_NAME, $summary, false );

		// Bridge to the structured logger; this is a no-op when logging is disabled.
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'markup_' . $outcome,
				/* translators: 1: outcome, 2: tool slug. */
				\sprintf( '[Markup] %1$s (tool: %2$s)', $outcome, '' === $tool_slug ? 'unknown' : $tool_slug ),
				array(
					'outcome'   => $outcome,
					'tool_slug' => $tool_slug,
					'mode'      => $mode,
				)
			);
		}
	}

	/**
	 * Extract the tool slug from a markup request value object.
	 *
	 * @param mixed $request Markup request.
	 * @return string
	 */
	protected function extract_tool_slug( $request ) {
		if ( $request instanceof MarkupRequest ) {
			$slug = $request->get_tool_slug();
			return \is_string( $slug ) ? \sanitize_key( $slug ) : '';
		}
		return '';
	}

	/**
	 * Extract the mode from a markup request value object.
	 *
	 * @param mixed $request Markup request.
	 * @return string
	 */
	protected function extract_mode( $request ) {
		if ( $request instanceof MarkupRequest ) {
			$mode = $request->get_mode();
			return \is_string( $mode ) ? \sanitize_key( $mode ) : '';
		}
		return '';
	}
}
