<?php
/**
 * Analytics TimeSeries DTO — Ordered collection of metric data points.
 *
 * Immutable container holding a sequence of Metric_DTO instances for charting
 * and trend analysis. Construct via from_array().
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
 * Normalized analytics time-series DTO.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_TimeSeries_DTO {

	/**
	 * Series label (e.g. "Instagram Followers").
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $label;

	/**
	 * Metric name for this series.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $metric_name;

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
	 * Aggregation granularity.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $granularity;

	/**
	 * Ordered metric data points.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Analytics_Metric_DTO[]
	 */
	private $data_points;

	/**
	 * Private constructor — use from_array().
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Hydrated data.
	 */
	private function __construct( array $data ) {
		$this->label       = (string) ( $data['label'] ?? '' );
		$this->metric_name = (string) ( $data['metric_name'] ?? '' );
		$this->platform    = (string) ( $data['platform'] ?? '' );
		$this->account_id  = (string) ( $data['account_id'] ?? '' );
		$this->granularity = (string) ( $data['granularity'] ?? 'day' );

		$this->data_points = array();
		if ( isset( $data['data_points'] ) && is_array( $data['data_points'] ) ) {
			foreach ( $data['data_points'] as $point ) {
				if ( is_array( $point ) ) {
					$this->data_points[] = WP_MCP_AI_Analytics_Metric_DTO::from_array( $point );
				} elseif ( $point instanceof WP_MCP_AI_Analytics_Metric_DTO ) {
					$this->data_points[] = $point;
				}
			}
		}
	}

	/**
	 * Create from an associative array.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Raw data including 'data_points' array of metric arrays/DTOs.
	 * @return WP_MCP_AI_Analytics_TimeSeries_DTO
	 */
	public static function from_array( array $data ) {
		return new self( $data );
	}

	/**
	 * Get the series label.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_label() {
		return $this->label;
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
	 * Get the granularity.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_granularity() {
		return $this->granularity;
	}

	/**
	 * Get all data points.
	 *
	 * @since 1.7.0
	 * @return WP_MCP_AI_Analytics_Metric_DTO[]
	 */
	public function get_data_points() {
		return $this->data_points;
	}

	/**
	 * Add a data point to the series.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_MCP_AI_Analytics_Metric_DTO $point The metric point to append.
	 * @return void
	 */
	public function add_data_point( WP_MCP_AI_Analytics_Metric_DTO $point ) {
		$this->data_points[] = $point;
	}

	/**
	 * Get the number of data points.
	 *
	 * @since 1.7.0
	 * @return int
	 */
	public function get_count() {
		return count( $this->data_points );
	}

	/**
	 * Get values as a flat array (for Chart.js datasets).
	 *
	 * @since 1.7.0
	 * @return float[]
	 */
	public function get_values() {
		return array_map(
			function ( $point ) {
				return $point->get_metric_value();
			},
			$this->data_points
		);
	}

	/**
	 * Get period labels as a flat array (for Chart.js labels).
	 *
	 * @since 1.7.0
	 * @return string[]
	 */
	public function get_period_labels() {
		return array_map(
			function ( $point ) {
				return $point->get_period_start();
			},
			$this->data_points
		);
	}

	/**
	 * Convert to Chart.js compatible dataset.
	 *
	 * @since 1.7.0
	 *
	 * @param string|null $color Optional color override.
	 * @return array<string,mixed> Chart.js dataset object.
	 */
	public function to_chartjs_dataset( $color = null ) {
		$border_color = $color ? $color : $this->get_default_color();
		return array(
			'label'           => $this->label,
			'data'            => $this->get_values(),
			'borderColor'     => $border_color,
			'backgroundColor' => $border_color . '20',
			'fill'            => false,
			'tension'         => 0.3,
		);
	}

	/**
	 * Get a default color based on platform.
	 *
	 * @since 1.7.0
	 * @return string Hex color.
	 */
	private function get_default_color() {
		$colors = array(
			'instagram' => '#E4405F',
			'facebook'  => '#1877F2',
			'twitter'   => '#1DA1F2',
			'linkedin'  => '#0A66C2',
			'tiktok'    => '#000000',
		);
		return isset( $colors[ $this->platform ] ) ? $colors[ $this->platform ] : '#6366F1';
	}

	/**
	 * Convert to an associative array.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'label'       => $this->label,
			'metric_name' => $this->metric_name,
			'platform'    => $this->platform,
			'account_id'  => $this->account_id,
			'granularity' => $this->granularity,
			'data_points' => array_map(
				function ( $point ) {
					return $point->to_array();
				},
				$this->data_points
			),
		);
	}
}
