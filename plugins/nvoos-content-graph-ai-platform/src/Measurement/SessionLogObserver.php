<?php
/**
 * Session-log observer — the telemetry single-path subscriber (Proposal
 * 029, Phase 5.8).
 *
 * Consumes the session-log event stream (do_action
 * 'wp_mcp_ai_session_log_event', fired by the OOS SessionTelemetryBridge
 * for every appended log entry) and projects tool_result + turn events
 * into the metric collector. This replaces loop re-wrapping for
 * telemetry: the same durable stream that derives model history feeds
 * metrics, so both chat paths produce identical telemetry and Phase 6
 * can delete the legacy loop without touching observers.
 *
 * Flag-gated and OFF by default (wp_mcp_ai_session_log_observer_enabled)
 * until the session log itself is promoted; the legacy hook observers
 * remain the default path.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects session-log entries onto the metric collector.
 *
 * @since 1.3.0
 */
class SessionLogObserver {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether the observer hooks are attached.
	 *
	 * @var bool
	 */
	private $attached = false;

	/**
	 * Turn start timestamps keyed by sanitized assistant id.
	 *
	 * @var array<string, float>
	 */
	private $turn_started_at = array();

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the singleton. Tests only.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Attach the session-log-event hook.
	 *
	 * @return bool True when attached, false when disabled.
	 */
	public function attach() {
		if ( $this->attached ) {
			return true;
		}

		/**
		 * Filters whether the session-log observer is installed.
		 *
		 * Default OFF — the legacy hook observers remain the telemetry
		 * path until the session log is promoted (Phase 4.2 canary).
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_session_log_observer_enabled', false ) ) {
			return false;
		}

		add_action( 'wp_mcp_ai_session_log_event', array( $this, 'on_event' ), 10, 4 );

		$this->attached = true;
		return true;
	}

	/**
	 * Detach hooks. Primarily for tests.
	 *
	 * @return void
	 */
	public function detach() {
		if ( ! $this->attached ) {
			return;
		}
		remove_action( 'wp_mcp_ai_session_log_event', array( $this, 'on_event' ), 10 );
		$this->attached        = false;
		$this->turn_started_at = array();
	}

	/**
	 * Session-log-event handler.
	 *
	 * Signature matches the SessionTelemetryBridge hook:
	 * ( $type, $data, $seq, $time ).
	 *
	 * @param string $type Entry type (SessionLog::TYPE_*).
	 * @param array  $data Type-specific payload.
	 * @param int    $seq  Monotonic entry sequence (unused here).
	 * @param float  $time Entry timestamp (microtime).
	 * @return void
	 */
	public function on_event( $type, $data, $seq, $time ) {
		unset( $seq );

		if ( ! is_array( $data ) ) {
			return;
		}

		if ( 'tool_result' === $type ) {
			$this->project_tool_result( $data );
			return;
		}

		if ( 'turn_started' === $type ) {
			$this->remember_turn_start( $data, (float) $time );
			return;
		}

		if ( 'turn_ended' === $type ) {
			$this->project_turn_ended( $data, (float) $time );
		}
	}

	/**
	 * Project a tool_result entry onto the tool-execution metrics.
	 *
	 * @param array $data tool_result payload.
	 * @return void
	 */
	private function project_tool_result( array $data ) {
		// The stock tool-execution metric ids live on the base plugin's
		// WP_MCP_AI_Stock_Metrics, which is not ported. In standalone mode
		// there are no tool-execution definitions to record against, so the
		// projection degrades to a no-op instead of raising on the missing
		// class.
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Stock_Metrics' ) ) {
			return;
		}

		$slug = self::sanitize_slug( isset( $data['name'] ) ? $data['name'] : '' );
		if ( '' === $slug ) {
			return;
		}

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$outcome = isset( $data['outcome'] ) && 'error' === $data['outcome'] ? 'error' : 'success';
		$context = $this->tool_context( $slug, $data, $outcome );

		$collector->record( \WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_COUNT, 1, $context );

		if ( 'success' === $outcome ) {
			$collector->record( \WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_SUCCESS_COUNT, 1, $context );
		} else {
			$collector->record( \WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_ERROR_COUNT, 1, $context );
		}

		if ( isset( $data['duration_ms'] ) && is_numeric( $data['duration_ms'] ) ) {
			$collector->record( \WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_DURATION_MS, (float) $data['duration_ms'], $context );
		}
	}

	/**
	 * Remember a turn boundary for duration projection.
	 *
	 * @param array $data turn_started payload.
	 * @param float $time Entry timestamp.
	 * @return void
	 */
	private function remember_turn_start( array $data, float $time ) {
		$assistant_id = isset( $data['assistant_id'] ) ? self::sanitize_scalar_id( $data['assistant_id'] ) : '';

		if ( '' === $assistant_id ) {
			return;
		}

		$this->turn_started_at[ $assistant_id ] = $time;
	}

	/**
	 * Project a turn_ended entry onto the chat-turn metrics.
	 *
	 * @param array $data turn_ended payload.
	 * @param float $time Entry timestamp.
	 * @return void
	 */
	private function project_turn_ended( array $data, float $time ) {
		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$assistant_id = isset( $data['assistant_id'] ) ? self::sanitize_scalar_id( $data['assistant_id'] ) : '';
		$reason       = isset( $data['reason'] ) ? (string) $data['reason'] : 'completed';
		$outcome      = 'rejected' === $reason ? 'error' : 'success';

		$context = array(
			'attributes' => array(
				'outcome' => $outcome,
				'reason'  => $reason,
			),
		);
		if ( '' !== $assistant_id && '0' !== $assistant_id ) {
			$context['assistant_id'] = $assistant_id;
		}

		$collector->record( ChatTurnMetrics::CHAT_TURN_COUNT, 1, $context );

		if ( 'error' === $outcome ) {
			$collector->record( ChatTurnMetrics::CHAT_TURN_ERROR_COUNT, 1, $context );
		}

		$started_at = '' !== $assistant_id && isset( $this->turn_started_at[ $assistant_id ] )
			? $this->turn_started_at[ $assistant_id ]
			: null;

		if ( null !== $started_at ) {
			unset( $this->turn_started_at[ $assistant_id ] );
			$collector->record(
				ChatTurnMetrics::CHAT_TURN_DURATION_MS,
				max( 0.0, ( $time - $started_at ) * 1000.0 ),
				$context
			);
		}

		if ( isset( $data['iterations'] ) && is_numeric( $data['iterations'] ) && (int) $data['iterations'] > 0 ) {
			$collector->record( ChatTurnMetrics::CHAT_AGENTIC_ITERATIONS, (int) $data['iterations'], $context );
		}
	}

	/**
	 * Tool-metric context built from the log payload.
	 *
	 * @param string $slug    Tool slug.
	 * @param array  $data    tool_result payload.
	 * @param string $outcome 'success' or 'error'.
	 * @return array<string,mixed>
	 */
	private function tool_context( string $slug, array $data, string $outcome ): array {
		// The collector allowlists the `tool` key and `attributes`;
		// outcome rides in attributes so attribution survives.
		$out = array(
			'tool'       => $slug,
			'attributes' => array( 'outcome' => $outcome ),
		);

		if ( ! empty( $data['assistant_id'] ) ) {
			$out['assistant_id'] = self::sanitize_scalar_id( $data['assistant_id'] );
		}

		if ( isset( $data['user_id'] ) ) {
			$out['user_id'] = (int) $data['user_id'];
		}

		return $out;
	}

	/**
	 * The metric collector.
	 *
	 * @return MetricCollector
	 */
	private function collector() {
		return MetricCollector::get_instance();
	}

	/**
	 * Sanitize a tool slug for context use.
	 *
	 * @param mixed $slug Raw slug.
	 * @return string
	 */
	private static function sanitize_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		return preg_replace( '/[^a-z0-9._\-]/i', '', $slug );
	}

	/**
	 * Sanitize an identifier for context use.
	 *
	 * @param mixed $value Raw identifier.
	 * @return string
	 */
	private static function sanitize_scalar_id( $value ) {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return '';
		}
		return (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', trim( $value ) );
	}
}
