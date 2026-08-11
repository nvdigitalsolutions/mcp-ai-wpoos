<?php
/**
 * Analytics Metric DTO — Single time-series metric data point.
 *
 * Immutable carrier for one metric observation with platform, period, and
 * optional comparison data. Construct via from_array().
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized analytics metric DTO.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Metric_DTO {

	/**
	 * Unified metric name (impressions, engagement, followers, etc.).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $metric_name;

	/**
	 * Numeric value.
	 *
	 * @since 1.7.0
	 * @var float
	 */
	private $metric_value;

	/**
	 * Platform identifier.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $platform;

	/**
	 * Account ID.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $account_id;

	/**
	 * Period start (ISO 8601).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $period_start;

	/**
	 * Period end (ISO 8601).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $period_end;

	/**
	 * Aggregation granularity (day, week, month).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $granularity;

	/**
	 * Previous period value for period-over-period comparison.
	 *
	 * @since 1.7.0
	 * @var float|null
	 */
	private $previous_value;

	/**
	 * Percentage change vs previous period.
	 *
	 * @since 1.7.0
	 * @var float|null
	 */
	private $change_pct;

	/**
	 * Private constructor — use from_array().
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Hydrated data.
	 */
	private function __construct( array $data ) {
		$this->metric_name    = (string) $data['metric_name'];
		$this->metric_value   = (float) $data['metric_value'];
		$this->platform       = (string) ( $data['platform'] ?? '' );
		$this->account_id     = (string) ( $data['account_id'] ?? '' );
		$this->period_start   = (string) ( $data['period_start'] ?? '' );
		$this->period_end     = (string) ( $data['period_end'] ?? '' );
		$this->granularity    = (string) ( $data['granularity'] ?? 'day' );
		$this->previous_value = isset( $data['previous_value'] ) ? (float) $data['previous_value'] : null;
		$this->change_pct     = isset( $data['change_pct'] ) ? (float) $data['change_pct'] : null;
	}

	/**
	 * Create from an associative array.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return WP_MCP_AI_Analytics_Metric_DTO
	 */
	public static function from_array( array $data ) {
		return new self( $data );
	}

	/**
	 * Get the metric name.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_metric_name() {
		return $this->metric_name;
	}

	/**
	 * Get the metric value.
	 *
	 * @since 1.7.0
	 * @return float
	 */
	public function get_metric_value() {
		return $this->metric_value;
	}

	/**
	 * Get the platform.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_platform() {
		return $this->platform;
	}

	/**
	 * Get the account ID.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_account_id() {
		return $this->account_id;
	}

	/**
	 * Get the period start.
	 *
	 * @since 1.7.0
	 * @return string ISO 8601.
	 */
	public function get_period_start() {
		return $this->period_start;
	}

	/**
	 * Get the period end.
	 *
	 * @since 1.7.0
	 * @return string ISO 8601.
	 */
	public function get_period_end() {
		return $this->period_end;
	}

	/**
	 * Get the granularity.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_granularity() {
		return $this->granularity;
	}

	/**
	 * Get the previous period value.
	 *
	 * @since 1.7.0
	 * @return float|null
	 */
	public function get_previous_value() {
		return $this->previous_value;
	}

	/**
	 * Get the percentage change.
	 *
	 * @since 1.7.0
	 * @return float|null
	 */
	public function get_change_pct() {
		return $this->change_pct;
	}

	/**
	 * Convert to an associative array.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'metric_name'    => $this->metric_name,
			'metric_value'   => $this->metric_value,
			'platform'       => $this->platform,
			'account_id'     => $this->account_id,
			'period_start'   => $this->period_start,
			'period_end'     => $this->period_end,
			'granularity'    => $this->granularity,
			'previous_value' => $this->previous_value,
			'change_pct'     => $this->change_pct,
		);
	}
}
