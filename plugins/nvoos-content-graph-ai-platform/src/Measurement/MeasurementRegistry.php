<?php
/**
 * Measurement Registry
 *
 * Central registry of metric definitions for the NV oOS measurement subsystem.
 * Each metric declares a stable identifier, numeric characteristics, privacy
 * tier, and an optional paired "counter-metric" used as a Goodhart guard so
 * that no KPI is optimized in isolation.
 *
 * This class is intentionally storage-agnostic: it only tracks definitions.
 * Recording values is the responsibility of {@see MetricCollector}.
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
 * Measurement Registry singleton.
 *
 * Metrics are registered during the `wp_mcp_ai_register_metrics` action which
 * fires once on plugin bootstrap after the registry is instantiated.
 */
class MeasurementRegistry {

	/**
	 * Valid metric types.
	 */
	const TYPE_COUNTER   = 'counter';
	const TYPE_GAUGE     = 'gauge';
	const TYPE_HISTOGRAM = 'histogram';
	const TYPE_RATE      = 'rate';

	/**
	 * Valid direction hints (whether higher or lower values are "better").
	 */
	const DIRECTION_HIGHER_IS_BETTER = 'higher_is_better';
	const DIRECTION_LOWER_IS_BETTER  = 'lower_is_better';
	const DIRECTION_NEUTRAL          = 'neutral';

	/**
	 * Privacy tiers — drive redaction, encryption, retention, and export gating.
	 */
	const PRIVACY_PUBLIC     = 'public';
	const PRIVACY_INTERNAL   = 'internal';
	const PRIVACY_SENSITIVE  = 'sensitive';
	const PRIVACY_RESTRICTED = 'restricted';

	/**
	 * Singleton instance.
	 *
	 * @var MeasurementRegistry|null
	 */
	private static $instance = null;

	/**
	 * Registered metric definitions keyed by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $metrics = array();

	/**
	 * Whether the `wp_mcp_ai_register_metrics` action has already fired.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return MeasurementRegistry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton. Intended for tests only.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Fire the metric registration action exactly once.
	 *
	 * Third-party code should `add_action( 'wp_mcp_ai_register_metrics', ... )`
	 * to add metrics rather than calling {@see register()} directly.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Fires when third parties should register measurement metrics.
		 *
		 * @since 1.3.0
		 *
		 * @param MeasurementRegistry $registry The metric registry.
		 */
		do_action( 'wp_mcp_ai_register_metrics', $this );
	}

	/**
	 * Register a single metric definition.
	 *
	 * Returns true on success, false if the definition is invalid or already
	 * registered. Duplicate registration is a no-op — first registration wins
	 * to keep behavior predictable across plugin hot-reloads.
	 *
	 * Required keys:
	 *   - id           string  Stable dotted identifier (e.g. `tool.execution.success_rate`).
	 *   - label        string  Human-readable label for admin UIs.
	 *   - type         string  One of the TYPE_* constants.
	 *   - unit         string  Unit string (e.g. `ratio`, `ms`, `tokens`, `usd`).
	 *
	 * Optional keys:
	 *   - description      string   Admin-facing description.
	 *   - direction        string   One of DIRECTION_* (defaults to neutral).
	 *   - privacy_tier     string   One of PRIVACY_* (defaults to internal).
	 *   - counter_metric   string   ID of the paired counter-metric (Goodhart guard).
	 *   - goodhart_note    string   "What could go wrong if this is optimized blindly?"
	 *   - regulated        bool     Indicates the metric touches regulated data.
	 *   - owasp_llm_risk   string   OWASP LLM Top 10 risk category this metric tracks.
	 *   - otel_attribute   string   OpenTelemetry GenAI semantic attribute name.
	 *
	 * @param array<string,mixed> $definition Metric definition.
	 * @return bool
	 */
	public function register( array $definition ) {
		$normalized = $this->normalize_definition( $definition );
		if ( null === $normalized ) {
			return false;
		}

		if ( isset( $this->metrics[ $normalized['id'] ] ) ) {
			return false;
		}

		$this->metrics[ $normalized['id'] ] = $normalized;
		return true;
	}

	/**
	 * Register many metric definitions.
	 *
	 * @param array<int,array<string,mixed>> $definitions Array of definitions.
	 * @return int Count of successfully registered metrics.
	 */
	public function register_many( array $definitions ) {
		$count = 0;
		foreach ( $definitions as $definition ) {
			if ( is_array( $definition ) && $this->register( $definition ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Validate and normalize a metric definition.
	 *
	 * Returns null if the definition is invalid.
	 *
	 * @param array<string,mixed> $definition Raw definition.
	 * @return array<string,mixed>|null
	 */
	private function normalize_definition( array $definition ) {
		if ( empty( $definition['id'] ) || ! is_string( $definition['id'] ) ) {
			return null;
		}
		$id = sanitize_key( $definition['id'] );
		// sanitize_key strips dots; preserve them for dotted metric ids.
		$raw_id = strtolower( trim( $definition['id'] ) );
		// Hyphen placed at end of character class to avoid range interpretation.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.-]*$/', $raw_id ) ) {
			return null;
		}
		$id = $raw_id;

		$type = isset( $definition['type'] ) ? (string) $definition['type'] : '';
		if ( ! in_array(
			$type,
			array( self::TYPE_COUNTER, self::TYPE_GAUGE, self::TYPE_HISTOGRAM, self::TYPE_RATE ),
			true
		) ) {
			return null;
		}

		$label = isset( $definition['label'] ) ? (string) $definition['label'] : '';
		if ( '' === trim( $label ) ) {
			return null;
		}

		$unit = isset( $definition['unit'] ) ? (string) $definition['unit'] : '';
		if ( '' === trim( $unit ) ) {
			return null;
		}

		$direction = isset( $definition['direction'] ) ? (string) $definition['direction'] : self::DIRECTION_NEUTRAL;
		if ( ! in_array(
			$direction,
			array( self::DIRECTION_HIGHER_IS_BETTER, self::DIRECTION_LOWER_IS_BETTER, self::DIRECTION_NEUTRAL ),
			true
		) ) {
			$direction = self::DIRECTION_NEUTRAL;
		}

		$privacy = isset( $definition['privacy_tier'] ) ? (string) $definition['privacy_tier'] : self::PRIVACY_INTERNAL;
		if ( ! in_array(
			$privacy,
			array( self::PRIVACY_PUBLIC, self::PRIVACY_INTERNAL, self::PRIVACY_SENSITIVE, self::PRIVACY_RESTRICTED ),
			true
		) ) {
			$privacy = self::PRIVACY_INTERNAL;
		}

		return array(
			'id'             => $id,
			'label'          => sanitize_text_field( $label ),
			'description'    => isset( $definition['description'] ) ? wp_kses_post( (string) $definition['description'] ) : '',
			'type'           => $type,
			'unit'           => sanitize_text_field( $unit ),
			'direction'      => $direction,
			'privacy_tier'   => $privacy,
			'counter_metric' => isset( $definition['counter_metric'] ) ? sanitize_text_field( (string) $definition['counter_metric'] ) : '',
			'goodhart_note'  => isset( $definition['goodhart_note'] ) ? wp_kses_post( (string) $definition['goodhart_note'] ) : '',
			'regulated'      => ! empty( $definition['regulated'] ),
			'owasp_llm_risk' => isset( $definition['owasp_llm_risk'] ) ? sanitize_text_field( (string) $definition['owasp_llm_risk'] ) : '',
			'otel_attribute' => isset( $definition['otel_attribute'] ) ? sanitize_text_field( (string) $definition['otel_attribute'] ) : '',
		);
	}

	/**
	 * Get a metric definition by id.
	 *
	 * @param string $id Metric id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		$id = is_string( $id ) ? strtolower( trim( $id ) ) : '';
		return isset( $this->metrics[ $id ] ) ? $this->metrics[ $id ] : null;
	}

	/**
	 * Whether a metric id is known.
	 *
	 * @param string $id Metric id.
	 * @return bool
	 */
	public function has( $id ) {
		return null !== $this->get( $id );
	}

	/**
	 * Get all metric definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		return $this->metrics;
	}

	/**
	 * Get metrics filtered by privacy tier.
	 *
	 * @param string $tier One of the PRIVACY_* constants.
	 * @return array<string,array<string,mixed>>
	 */
	public function by_privacy_tier( $tier ) {
		$out = array();
		foreach ( $this->metrics as $id => $def ) {
			if ( $def['privacy_tier'] === $tier ) {
				$out[ $id ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Identify metrics that have no paired counter-metric.
	 *
	 * The admin dashboard surfaces these as Goodhart risks. Policy is enforced
	 * as a warning (not a fatal) so third-party metrics remain easy to add.
	 *
	 * @return array<int,string>
	 */
	public function metrics_without_counter() {
		$out = array();
		foreach ( $this->metrics as $id => $def ) {
			if ( '' === $def['counter_metric'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
