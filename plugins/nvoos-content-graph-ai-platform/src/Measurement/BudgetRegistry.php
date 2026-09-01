<?php
/**
 * Budget Registry
 *
 * Tracks consumption against {@see BudgetEnvelope}s and fires
 * observable signals when warn and exceed thresholds are crossed. The
 * registry itself is a singleton; it attaches a listener to the collector's
 * `wp_mcp_ai_metric_recorded` action exactly once so envelopes tick on
 * every recorded event without extra wiring from call sites.
 *
 * Signals fired (both idempotent per envelope per scope-window):
 *   - `wp_mcp_ai_budget_warned`   — utilization crossed the warn ratio.
 *   - `wp_mcp_ai_budget_exceeded` — utilization reached/crossed the limit.
 *
 * Neither signal is a veto: the registry does not block recording or
 * short-circuit the collector. It only surfaces the breach so downstream
 * systems (log sinks, APM, a future Pro guardrail) can react. This keeps
 * the core dependency-free and predictable.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
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
 * Budget Registry singleton.
 */
class BudgetRegistry {

	/**
	 * Option key for persistent-scope accumulators. A single option holds
	 * all persistent envelope state keyed by slug to avoid N autoloaded
	 * options per deployment.
	 */
	const PERSISTENT_OPTION = 'wp_mcp_ai_budget_accumulators';

	/**
	 * Singleton instance.
	 *
	 * @var BudgetRegistry|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Whether the listener has been attached.
	 *
	 * @var bool
	 */
	private $listener_attached = false;

	/**
	 * Envelopes keyed by slug.
	 *
	 * @var array<string,BudgetEnvelope>
	 */
	private $envelopes = array();

	/**
	 * Per-request accumulators keyed by slug.
	 *
	 * @var array<string,float>
	 */
	private $request_accumulators = array();

	/**
	 * Persistent accumulators keyed by slug.
	 * Each entry: [ 'value' => float, 'window_started_at' => int ].
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $persistent_accumulators = array();

	/**
	 * Track which signals have fired this request to keep them idempotent.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $fired = array();

	/**
	 * Whether persistent accumulators have been loaded from options.
	 *
	 * @var bool
	 */
	private $persistent_loaded = false;

	/**
	 * Get singleton.
	 *
	 * @return BudgetRegistry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		if ( null !== self::$instance ) {
			remove_action( 'wp_mcp_ai_metric_recorded', array( self::$instance, 'on_metric_recorded' ), 5 );
		}
		self::$instance = null;
	}

	/**
	 * Boot: fires the registration hook and attaches the listener.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->listener_attached ) {
			add_action( 'wp_mcp_ai_metric_recorded', array( $this, 'on_metric_recorded' ), 5, 3 );
			$this->listener_attached = true;
		}

		/**
		 * Fires when the budget registry is ready to accept envelopes.
		 *
		 * @since 1.3.0
		 *
		 * @param BudgetRegistry $registry Registry instance.
		 */
		do_action( 'wp_mcp_ai_register_budgets', $this );
	}

	/**
	 * Register an envelope. Accepts either an envelope instance or an
	 * array definition — same pattern as the other measurement registries.
	 *
	 * @param BudgetEnvelope|array $envelope Envelope or definition.
	 * @return BudgetEnvelope|WP_Error
	 */
	public function register( $envelope ) {
		if ( is_array( $envelope ) ) {
			try {
				$envelope = new BudgetEnvelope( $envelope );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'wp_mcp_ai_budget_invalid', $e->getMessage() );
			}
		}
		if ( ! $envelope instanceof BudgetEnvelope ) {
			return new \WP_Error(
				'wp_mcp_ai_budget_invalid',
				__( 'Budget must be an array definition or a BudgetEnvelope instance.', 'nvoos-content-graph-ai-platform' )
			);
		}
		$this->envelopes[ $envelope->get_slug() ] = $envelope;
		return $envelope;
	}

	/**
	 * Unregister.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function unregister( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( isset( $this->envelopes[ $slug ] ) ) {
			unset( $this->envelopes[ $slug ], $this->request_accumulators[ $slug ], $this->fired[ $slug ] );
			return true;
		}
		return false;
	}

	/**
	 * Get envelope by slug.
	 *
	 * @param string $slug Slug.
	 * @return BudgetEnvelope|null
	 */
	public function get( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return isset( $this->envelopes[ $slug ] ) ? $this->envelopes[ $slug ] : null;
	}

	/**
	 * All envelopes.
	 *
	 * @return array<string,BudgetEnvelope>
	 */
	public function all() {
		return $this->envelopes;
	}

	/**
	 * Listener for `wp_mcp_ai_metric_recorded`. Intentionally defensive:
	 * any unexpected payload shape is silently ignored so a bug in an
	 * envelope never takes down metric recording.
	 *
	 * @param mixed $event      Event payload.
	 * @param mixed $definition Metric definition (unused; kept for signature).
	 * @param mixed $collector  Collector (unused; kept for signature).
	 * @return void
	 */
	public function on_metric_recorded( $event, $definition = null, $collector = null ) {
		unset( $definition, $collector );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['value'] ) ) {
			return;
		}
		if ( ! is_numeric( $event['value'] ) ) {
			return;
		}
		$metric_id = (string) $event['id'];
		$value     = (float) $event['value'];
		if ( 0.0 === $value ) {
			// Zero-valued observations don't consume budget.
			return;
		}

		foreach ( $this->envelopes as $slug => $envelope ) {
			if ( ! $envelope->observes( $metric_id ) ) {
				continue;
			}
			$this->accumulate( $envelope, $value );
		}
	}

	/**
	 * Record consumption manually (e.g. for envelopes that aggregate
	 * values that don't flow through the metric collector).
	 *
	 * @param string $slug  Envelope slug.
	 * @param float  $value Value to add.
	 * @return float|WP_Error New accumulator value.
	 */
	public function consume( $slug, $value ) {
		$envelope = $this->get( $slug );
		if ( null === $envelope ) {
			return new \WP_Error( 'wp_mcp_ai_budget_not_found', 'Unknown budget envelope.' );
		}
		if ( ! is_numeric( $value ) ) {
			return new \WP_Error( 'wp_mcp_ai_budget_invalid_value', 'Budget consumption must be numeric.' );
		}
		return $this->accumulate( $envelope, (float) $value );
	}

	/**
	 * Current consumption for an envelope.
	 *
	 * @param string $slug Slug.
	 * @return float
	 */
	public function get_consumption( $slug ) {
		$envelope = $this->get( $slug );
		if ( null === $envelope ) {
			return 0.0;
		}
		if ( BudgetEnvelope::SCOPE_PERSISTENT === $envelope->get_scope() ) {
			$this->ensure_persistent_loaded();
			$this->maybe_roll_window( $envelope );
			return isset( $this->persistent_accumulators[ $envelope->get_slug() ]['value'] )
				? (float) $this->persistent_accumulators[ $envelope->get_slug() ]['value']
				: 0.0;
		}
		return isset( $this->request_accumulators[ $envelope->get_slug() ] )
			? $this->request_accumulators[ $envelope->get_slug() ]
			: 0.0;
	}

	/**
	 * Snapshot of all envelopes + consumption (for dashboard).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function snapshot() {
		$out = array();
		foreach ( $this->envelopes as $slug => $envelope ) {
			$consumed = $this->get_consumption( $slug );
			$limit    = $envelope->get_limit();
			$ratio    = $limit > 0.0 ? $consumed / $limit : 0.0;
			$out[]    = array(
				'envelope' => $envelope->to_array(),
				'consumed' => $consumed,
				'ratio'    => $ratio,
				'state'    => $this->state_for( $envelope, $consumed ),
			);
		}
		return $out;
	}

	/**
	 * Reset request-scoped accumulators (tests / end-of-request).
	 *
	 * @param string|null $slug Optional specific slug.
	 * @return void
	 */
	public function reset_request( $slug = null ) {
		if ( null === $slug ) {
			$this->request_accumulators = array();
			$this->fired                = array();
			return;
		}
		$slug = sanitize_key( (string) $slug );
		unset( $this->request_accumulators[ $slug ], $this->fired[ $slug ] );
	}

	/**
	 * Reset persistent accumulator for a slug (admin "reset budget" action).
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function reset_persistent( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! isset( $this->envelopes[ $slug ] ) ) {
			return false;
		}
		$this->ensure_persistent_loaded();
		$this->persistent_accumulators[ $slug ] = array(
			'value'             => 0.0,
			'window_started_at' => time(),
		);
		$this->save_persistent();
		unset( $this->fired[ $slug ] );
		return true;
	}

	/**
	 * Accumulate and fire signals as needed.
	 *
	 * @param BudgetEnvelope $envelope Envelope.
	 * @param float                     $value    Value to add.
	 * @return float New accumulator value.
	 */
	private function accumulate( BudgetEnvelope $envelope, $value ) {
		$slug = $envelope->get_slug();

		if ( BudgetEnvelope::SCOPE_PERSISTENT === $envelope->get_scope() ) {
			$this->ensure_persistent_loaded();
			$this->maybe_roll_window( $envelope );
			if ( ! isset( $this->persistent_accumulators[ $slug ] ) ) {
				$this->persistent_accumulators[ $slug ] = array(
					'value'             => 0.0,
					'window_started_at' => time(),
				);
			}
			$this->persistent_accumulators[ $slug ]['value'] += $value;
			$new_value                                        = (float) $this->persistent_accumulators[ $slug ]['value'];
			$this->save_persistent();
		} else {
			if ( ! isset( $this->request_accumulators[ $slug ] ) ) {
				$this->request_accumulators[ $slug ] = 0.0;
			}
			$this->request_accumulators[ $slug ] += $value;
			$new_value                            = $this->request_accumulators[ $slug ];
		}

		$this->maybe_fire_signals( $envelope, $new_value );
		return $new_value;
	}

	/**
	 * Fire warn/exceed signals (idempotent per scope-window).
	 *
	 * @param BudgetEnvelope $envelope Envelope.
	 * @param float                     $consumed Current consumption.
	 * @return void
	 */
	private function maybe_fire_signals( BudgetEnvelope $envelope, $consumed ) {
		$slug  = $envelope->get_slug();
		$limit = $envelope->get_limit();
		$warn  = $envelope->get_warn_threshold();

		$fired = isset( $this->fired[ $slug ] ) ? $this->fired[ $slug ] : array();

		if ( empty( $fired['warned'] ) && $consumed >= $warn && $consumed < $limit ) {
			$fired['warned'] = true;

			/**
			 * Fires when a budget envelope crosses its warn ratio.
			 *
			 * @since 1.3.0
			 *
			 * @param BudgetEnvelope $envelope  Envelope.
			 * @param float                     $consumed  Consumption so far.
			 * @param float                     $limit     Envelope limit.
			 */
			do_action( 'wp_mcp_ai_budget_warned', $envelope, $consumed, $limit );
		}

		if ( empty( $fired['exceeded'] ) && $consumed >= $limit ) {
			$fired['exceeded'] = true;
			// If we jumped straight past the warn threshold, fire that too
			// so subscribers that only listen for warn still observe it.
			if ( empty( $fired['warned'] ) ) {
				$fired['warned'] = true;
				do_action( 'wp_mcp_ai_budget_warned', $envelope, $consumed, $limit );
			}

			/**
			 * Fires when a budget envelope crosses its limit.
			 *
			 * @since 1.3.0
			 *
			 * @param BudgetEnvelope $envelope  Envelope.
			 * @param float                     $consumed  Consumption so far.
			 * @param float                     $limit     Envelope limit.
			 */
			do_action( 'wp_mcp_ai_budget_exceeded', $envelope, $consumed, $limit );
		}

		$this->fired[ $slug ] = $fired;
	}

	/**
	 * Human-readable state for a given consumption.
	 *
	 * @param BudgetEnvelope $envelope Envelope.
	 * @param float                     $consumed Consumption.
	 * @return string `ok` | `warn` | `exceeded`.
	 */
	private function state_for( BudgetEnvelope $envelope, $consumed ) {
		if ( $consumed >= $envelope->get_limit() ) {
			return 'exceeded';
		}
		if ( $consumed >= $envelope->get_warn_threshold() ) {
			return 'warn';
		}
		return 'ok';
	}

	/**
	 * Ensure persistent accumulators are loaded from the option.
	 *
	 * @return void
	 */
	private function ensure_persistent_loaded() {
		if ( $this->persistent_loaded ) {
			return;
		}
		$this->persistent_loaded = true;
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$raw = get_option( self::PERSISTENT_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return;
		}
		foreach ( $raw as $slug => $row ) {
			if ( ! is_string( $slug ) || ! is_array( $row ) ) {
				continue;
			}
			$this->persistent_accumulators[ $slug ] = array(
				'value'             => isset( $row['value'] ) && is_numeric( $row['value'] ) ? (float) $row['value'] : 0.0,
				'window_started_at' => isset( $row['window_started_at'] ) ? (int) $row['window_started_at'] : time(),
			);
		}
	}

	/**
	 * Save persistent accumulators back to the option.
	 *
	 * @return void
	 */
	private function save_persistent() {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		update_option( self::PERSISTENT_OPTION, $this->persistent_accumulators, false );
	}

	/**
	 * Roll the persistent window if its duration has elapsed.
	 *
	 * @param BudgetEnvelope $envelope Envelope.
	 * @return void
	 */
	private function maybe_roll_window( BudgetEnvelope $envelope ) {
		$window = $envelope->get_window_seconds();
		if ( $window <= 0 ) {
			return;
		}
		$slug = $envelope->get_slug();
		if ( empty( $this->persistent_accumulators[ $slug ] ) ) {
			return;
		}
		$started = (int) $this->persistent_accumulators[ $slug ]['window_started_at'];
		if ( ( time() - $started ) >= $window ) {
			$this->persistent_accumulators[ $slug ] = array(
				'value'             => 0.0,
				'window_started_at' => time(),
			);
			unset( $this->fired[ $slug ] );
			$this->save_persistent();
		}
	}
}
