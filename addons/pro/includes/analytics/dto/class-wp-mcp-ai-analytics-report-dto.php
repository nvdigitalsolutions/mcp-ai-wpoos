<?php
/**
 * Analytics Report DTO — Aggregate report container.
 *
 * Top-level data carrier for a complete analytics report. Contains accounts,
 * summary metrics, time-series trends, top-performing posts, period-over-period
 * comparison, and Chart.js visualization data. Construct via from_array().
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
 * Normalized analytics report DTO.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Report_DTO {

	/**
	 * Unique report identifier.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $report_id;

	/**
	 * Report type (social, ecommerce, seo, cloudways, custom).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $report_type;

	/**
	 * ISO 8601 timestamp when the report was generated.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $generated_at;

	/**
	 * Report period.
	 *
	 * @since 1.7.0
	 * @var array{from:string,to:string}
	 */
	private $period;

	/**
	 * Analyzed accounts.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Analytics_Account_DTO[]
	 */
	private $accounts;

	/**
	 * Aggregated summary metrics.
	 *
	 * @since 1.7.0
	 * @var array<string,int|float>
	 */
	private $summary;

	/**
	 * Time-series trends.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Analytics_TimeSeries_DTO[]
	 */
	private $trends;

	/**
	 * Top-performing posts/content.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Analytics_Post_DTO[]
	 */
	private $top_posts;

	/**
	 * Period-over-period comparison data.
	 *
	 * @since 1.7.0
	 * @var array<string,mixed>
	 */
	private $comparison;

	/**
	 * Chart.js compatible visualization data.
	 *
	 * @since 1.7.0
	 * @var array<string,mixed>
	 */
	private $charts;

	/**
	 * Private constructor — use from_array().
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Hydrated data.
	 */
	private function __construct( array $data ) {
		$this->report_id    = (string) ( $data['report_id'] ?? wp_generate_uuid4() );
		$this->report_type  = (string) ( $data['report_type'] ?? 'custom' );
		$this->generated_at = (string) ( $data['generated_at'] ?? gmdate( 'c' ) );
		$this->period       = array(
			'from' => (string) ( $data['period']['from'] ?? '' ),
			'to'   => (string) ( $data['period']['to'] ?? '' ),
		);
		$this->summary      = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
		$this->comparison   = isset( $data['comparison'] ) && is_array( $data['comparison'] ) ? $data['comparison'] : array();
		$this->charts       = isset( $data['charts'] ) && is_array( $data['charts'] ) ? $data['charts'] : array();

		$this->accounts  = $this->hydrate_dto_list(
			isset( $data['accounts'] ) ? $data['accounts'] : array(),
			'WP_MCP_AI_Analytics_Account_DTO'
		);
		$this->trends    = $this->hydrate_dto_list(
			isset( $data['trends'] ) ? $data['trends'] : array(),
			'WP_MCP_AI_Analytics_TimeSeries_DTO'
		);
		$this->top_posts = $this->hydrate_dto_list(
			isset( $data['top_posts'] ) ? $data['top_posts'] : array(),
			'WP_MCP_AI_Analytics_Post_DTO'
		);
	}

	/**
	 * Hydrate an array of DTOs from raw arrays or existing instances.
	 *
	 * @since 1.7.0
	 *
	 * @param array<int,array<string,mixed>|object> $raw_list   Raw data list.
	 * @param string                                $dto_class Fully qualified DTO class name.
	 * @return array<int,object>
	 */
	private function hydrate_dto_list( $raw_list, $dto_class ) {
		$result = array();
		if ( ! is_array( $raw_list ) ) {
			return $result;
		}
		foreach ( $raw_list as $item ) {
			if ( $item instanceof $dto_class ) {
				$result[] = $item;
			} elseif ( is_array( $item ) ) {
				$result[] = call_user_func( array( $dto_class, 'from_array' ), $item );
			}
		}
		return $result;
	}

	/**
	 * Create from an associative array.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return WP_MCP_AI_Analytics_Report_DTO
	 */
	public static function from_array( array $data ) {
		return new self( $data );
	}

	/**
	 * Get the report ID.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_report_id() {
		return $this->report_id;
	}

	/**
	 * Get the report type.
	 *
	 * @since 1.7.0
	 * @return string
	 */
	public function get_report_type() {
		return $this->report_type;
	}

	/**
	 * Get the generation timestamp.
	 *
	 * @since 1.7.0
	 * @return string ISO 8601.
	 */
	public function get_generated_at() {
		return $this->generated_at;
	}

	/**
	 * Get the report period.
	 *
	 * @since 1.7.0
	 * @return array{from:string,to:string}
	 */
	public function get_period() {
		return $this->period;
	}

	/**
	 * Get the accounts.
	 *
	 * @since 1.7.0
	 * @return WP_MCP_AI_Analytics_Account_DTO[]
	 */
	public function get_accounts() {
		return $this->accounts;
	}

	/**
	 * Get the summary metrics.
	 *
	 * @since 1.7.0
	 * @return array<string,int|float>
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Get a specific summary metric.
	 *
	 * @since 1.7.0
	 *
	 * @param string $key     Metric key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get_summary_metric( $key, $default = 0 ) {
		return isset( $this->summary[ $key ] ) ? $this->summary[ $key ] : $default;
	}

	/**
	 * Get the time-series trends.
	 *
	 * @since 1.7.0
	 * @return WP_MCP_AI_Analytics_TimeSeries_DTO[]
	 */
	public function get_trends() {
		return $this->trends;
	}

	/**
	 * Get the top posts.
	 *
	 * @since 1.7.0
	 * @return WP_MCP_AI_Analytics_Post_DTO[]
	 */
	public function get_top_posts() {
		return $this->top_posts;
	}

	/**
	 * Get the comparison data.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function get_comparison() {
		return $this->comparison;
	}

	/**
	 * Get the chart visualization data.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function get_charts() {
		return $this->charts;
	}

	/**
	 * Convert to an associative array.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'report_id'    => $this->report_id,
			'report_type'  => $this->report_type,
			'generated_at' => $this->generated_at,
			'period'       => $this->period,
			'accounts'     => array_map(
				function ( $dto ) {
					return $dto->to_array();
				},
				$this->accounts
			),
			'summary'      => $this->summary,
			'trends'       => array_map(
				function ( $dto ) {
					return $dto->to_array();
				},
				$this->trends
			),
			'top_posts'    => array_map(
				function ( $dto ) {
					return $dto->to_array();
				},
				$this->top_posts
			),
			'comparison'   => $this->comparison,
			'charts'       => $this->charts,
		);
	}

	/**
	 * Serialize to JSON for REST API responses.
	 *
	 * @since 1.7.0
	 *
	 * @param int $options JSON encoding options.
	 * @return string|false JSON string or false on failure.
	 */
	public function to_json( $options = 0 ) {
		return wp_json_encode( $this->to_array(), $options );
	}
}
