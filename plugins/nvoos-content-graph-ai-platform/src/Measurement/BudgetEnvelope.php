<?php
/**
 * Budget Envelope
 *
 * Declarative cap on a measurable quantity (cost USD, tokens, error rate,
 * latency budget). Envelopes are the anti-Goodhart guard for reward
 * functions: a reward can optimize for "pass more cases" without bound,
 * but a budget envelope makes the operator's constraints explicit so
 * breaches fire observable alarms rather than getting buried in a
 * quarterly invoice.
 *
 * An envelope is a plain value object; accumulation is tracked separately
 * by {@see BudgetRegistry} so the same envelope definition can
 * be re-used across requests.
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
 * Budget Envelope value object.
 */
class BudgetEnvelope {

	/**
	 * Reset policy: breaches are sticky for the whole PHP request. Useful
	 * for request-scoped caps like "this HTTP response may spend ≤ $0.05".
	 */
	const SCOPE_REQUEST = 'request';

	/**
	 * Reset policy: persisted across requests via the provided accumulator
	 * option. Useful for daily / weekly budgets.
	 */
	const SCOPE_PERSISTENT = 'persistent';

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Set of metric ids this envelope observes. Values recorded against any
	 * listed metric increment the envelope's accumulator.
	 *
	 * @var array<int,string>
	 */
	private $metric_ids;

	/**
	 * Upper bound. Crossing this triggers `wp_mcp_ai_budget_exceeded`.
	 *
	 * @var float
	 */
	private $limit;

	/**
	 * Warning threshold (fraction of limit, 0..1). `0.8` means the warn
	 * hook fires at 80 % utilization.
	 *
	 * @var float
	 */
	private $warn_ratio;

	/**
	 * Unit label (for the dashboard).
	 *
	 * @var string
	 */
	private $unit;

	/**
	 * Scope ({@see self::SCOPE_REQUEST} or {@see self::SCOPE_PERSISTENT}).
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Optional window in seconds — only meaningful for persistent scope.
	 * Used by the registry to decide when to roll over the accumulator.
	 *
	 * @var int
	 */
	private $window_seconds;

	/**
	 * Optional static context tags (environment, deployment) propagated
	 * into the exceeded/warned events. Not indexed, not queryable — just
	 * labels on the signal.
	 *
	 * @var array<string,string>
	 */
	private $tags;

	/**
	 * Constructor.
	 *
	 * @param array $args Envelope args.
	 *
	 * @throws InvalidArgumentException When slug, metric_ids, or limit is invalid.
	 */
	public function __construct( array $args ) {
		$slug = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		if ( '' === $slug ) {
			throw new \InvalidArgumentException( 'Budget envelope requires a non-empty slug.' );
		}

		$metric_ids = array();
		if ( isset( $args['metric_ids'] ) && is_array( $args['metric_ids'] ) ) {
			foreach ( $args['metric_ids'] as $metric_id ) {
				if ( is_string( $metric_id ) && '' !== trim( $metric_id ) ) {
					$metric_ids[] = strtolower( trim( $metric_id ) );
				}
			}
		}
		if ( empty( $metric_ids ) ) {
			throw new \InvalidArgumentException( 'Budget envelope requires at least one metric id.' );
		}

		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (float) $args['limit'] : 0.0;
		if ( $limit <= 0.0 ) {
			throw new \InvalidArgumentException( 'Budget envelope limit must be positive.' );
		}

		$warn_ratio = isset( $args['warn_ratio'] ) && is_numeric( $args['warn_ratio'] )
			? (float) $args['warn_ratio']
			: 0.8;
		if ( $warn_ratio < 0.0 ) {
			$warn_ratio = 0.0;
		}
		if ( $warn_ratio > 1.0 ) {
			$warn_ratio = 1.0;
		}

		$scope = isset( $args['scope'] ) ? (string) $args['scope'] : self::SCOPE_REQUEST;
		if ( ! in_array( $scope, array( self::SCOPE_REQUEST, self::SCOPE_PERSISTENT ), true ) ) {
			$scope = self::SCOPE_REQUEST;
		}

		$window_seconds = isset( $args['window_seconds'] ) ? (int) $args['window_seconds'] : 0;
		if ( $window_seconds < 0 ) {
			$window_seconds = 0;
		}

		$tags = array();
		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			foreach ( $args['tags'] as $k => $v ) {
				if ( is_string( $k ) && is_scalar( $v ) ) {
					$key = sanitize_key( $k );
					if ( '' !== $key ) {
						$tags[ $key ] = sanitize_text_field( (string) $v );
					}
				}
			}
		}

		$this->slug           = $slug;
		$this->label          = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : $slug;
		$this->metric_ids     = array_values( array_unique( $metric_ids ) );
		$this->limit          = $limit;
		$this->warn_ratio     = $warn_ratio;
		$this->unit           = isset( $args['unit'] ) ? sanitize_text_field( (string) $args['unit'] ) : '';
		$this->scope          = $scope;
		$this->window_seconds = $window_seconds;
		$this->tags           = $tags;
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label() {
		return $this->label;
	}

	/**
	 * Metric ids observed.
	 *
	 * @return array<int,string>
	 */
	public function get_metric_ids() {
		return $this->metric_ids;
	}

	/**
	 * Does this envelope observe the given metric id?
	 *
	 * @param string $metric_id Metric id.
	 * @return bool
	 */
	public function observes( $metric_id ) {
		return in_array( strtolower( trim( (string) $metric_id ) ), $this->metric_ids, true );
	}

	/**
	 * Upper bound.
	 *
	 * @return float
	 */
	public function get_limit() {
		return $this->limit;
	}

	/**
	 * Warn threshold in absolute units.
	 *
	 * @return float
	 */
	public function get_warn_threshold() {
		return $this->limit * $this->warn_ratio;
	}

	/**
	 * Warn ratio.
	 *
	 * @return float
	 */
	public function get_warn_ratio() {
		return $this->warn_ratio;
	}

	/**
	 * Unit.
	 *
	 * @return string
	 */
	public function get_unit() {
		return $this->unit;
	}

	/**
	 * Scope.
	 *
	 * @return string
	 */
	public function get_scope() {
		return $this->scope;
	}

	/**
	 * Window in seconds (0 for request-scoped).
	 *
	 * @return int
	 */
	public function get_window_seconds() {
		return $this->window_seconds;
	}

	/**
	 * Tags.
	 *
	 * @return array<string,string>
	 */
	public function get_tags() {
		return $this->tags;
	}

	/**
	 * Serialize to array (for dashboards / exports — never contains raw
	 * consumption values; see the registry's `snapshot()` for that).
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'slug'           => $this->slug,
			'label'          => $this->label,
			'metric_ids'     => $this->metric_ids,
			'limit'          => $this->limit,
			'warn_ratio'     => $this->warn_ratio,
			'unit'           => $this->unit,
			'scope'          => $this->scope,
			'window_seconds' => $this->window_seconds,
			'tags'           => $this->tags,
		);
	}
}
