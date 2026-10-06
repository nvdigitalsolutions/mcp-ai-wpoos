<?php
/**
 * Analyze Data Patterns Tool - Find trends and patterns in datasets
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns tool.
 */
class WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns {
		/**
		 * Get the tool slug.
		 *
		 * @return string
		 */
	public function get_slug() {
		return 'analyze_data_patterns';
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'analyze_data_patterns',
			'description'         => 'Analyze datasets to identify trends, patterns, anomalies, and insights using statistical analysis.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'dataset'       => array(
						'type'        => 'array',
						'description' => 'Array of data points to analyze',
						'items'       => array(
							'type' => array( 'number', 'string' ),
						),
					),
					'analysis_type' => array(
						'type'        => 'string',
						'enum'        => array( 'trend', 'frequency', 'correlation', 'outliers' ),
						'description' => 'Type of analysis to perform',
						'default'     => 'trend',
					),
				),
				'required'   => array( 'dataset' ),
			),
			'required_capability' => 'edit_posts',
			'category'            => array( 'research', 'orchestration', 'analytics' ),
		);
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Detecting trends, outliers, frequency, or correlation signals in a numeric dataset.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Use extract_structured_data to pull fields out of raw content before running numeric analysis.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'extract_structured_data', 'aggregate_research_data' ),
			'notes'           => __( 'Non-numeric values are filtered out and an error is returned when no numeric data remains.', 'mcp-ai-wpoos-pro' ),
		);
	}


	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $context );

		$dataset = isset( $arguments['dataset'] ) && is_array( $arguments['dataset'] ) ? $arguments['dataset'] : array();
		$type    = isset( $arguments['analysis_type'] ) ? $arguments['analysis_type'] : 'trend';

		$numeric_data = array_filter( $dataset, 'is_numeric' );
		if ( empty( $numeric_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_analyze_data_patterns_no_numeric_data',
				'Dataset must contain numeric values'
			);
		}

		$analysis = array(
			'count'  => count( $numeric_data ),
			'min'    => min( $numeric_data ),
			'max'    => max( $numeric_data ),
			'mean'   => array_sum( $numeric_data ) / count( $numeric_data ),
			'median' => $this->calculate_median( $numeric_data ),
			'range'  => max( $numeric_data ) - min( $numeric_data ),
		);

		if ( 'trend' === $type ) {
			$analysis['trend'] = $this->detect_trend( $numeric_data );
		} elseif ( 'frequency' === $type ) {
			$analysis['frequency'] = $this->detect_frequency( $numeric_data );
		} elseif ( 'correlation' === $type ) {
			$analysis['correlation'] = $this->detect_correlation( $numeric_data );
		} elseif ( 'outliers' === $type ) {
			$analysis['outliers'] = $this->detect_outliers( $numeric_data );
		}

		return array(
			'success'  => true,
			'analysis' => $analysis,
			'insights' => $this->generate_insights( $analysis ),
		);
	}

	/**
	 * Calculate median.
	 *
	 * @param array $data Numeric data array.
	 * @return float
	 */
	private function calculate_median( $data ) {
		sort( $data );
		$count = count( $data );
		$mid   = floor( $count / 2 );
		return 0 === $count % 2 ? ( $data[ $mid - 1 ] + $data[ $mid ] ) / 2 : $data[ $mid ];
	}

	/**
	 * Detect_trend.
	 *
	 * @param mixed $data Parameter.
	 * @return array|WP_Error Result.
	 */
	private function detect_trend( $data ) {
		$first_half  = array_slice( $data, 0, ceil( count( $data ) / 2 ) );
		$second_half = array_slice( $data, ceil( count( $data ) / 2 ) );
		$avg_first   = array_sum( $first_half ) / count( $first_half );
		$avg_second  = array_sum( $second_half ) / count( $second_half );

		if ( $avg_second > $avg_first * 1.1 ) {
			return 'increasing';
		} elseif ( $avg_second < $avg_first * 0.9 ) {
			return 'decreasing';
		}
		return 'stable';
	}

	/**
	 * Detect frequency.
	 *
	 * Counts value frequencies with near-equal floats sharing a bucket.
	 *
	 * @param array $data Numeric data array.
	 * @return array Sorted most-common-first list of { value, count } entries.
	 */
	private function detect_frequency( $data ) {
		$counts = array();
		foreach ( $data as $value ) {
			$key = (string) round( (float) $value, 2 );
			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = array(
					'value' => (float) $value,
					'count' => 0,
				);
			}
			++$counts[ $key ]['count'];
		}

		usort(
			$counts,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array_slice( $counts, 0, 10 );
	}

	/**
	 * Detect correlation.
	 *
	 * Pearson correlation between each value and its position in the series.
	 *
	 * @param array $data Numeric data array.
	 * @return array { coefficient, interpretation }.
	 */
	private function detect_correlation( $data ) {
		$values = array_values( array_map( 'floatval', $data ) );
		$n      = count( $values );

		if ( $n < 2 ) {
			return array(
				'coefficient'    => 0.0,
				'interpretation' => 'insufficient data',
			);
		}

		$sum_x  = 0.0;
		$sum_y  = 0.0;
		$sum_xy = 0.0;
		$sum_x2 = 0.0;
		$sum_y2 = 0.0;
		foreach ( $values as $i => $y ) {
			$x       = $i + 1;
			$sum_x  += $x;
			$sum_y  += $y;
			$sum_xy += $x * $y;
			$sum_x2 += $x * $x;
			$sum_y2 += $y * $y;
		}

		$denominator = sqrt( ( $n * $sum_x2 - $sum_x * $sum_x ) * ( $n * $sum_y2 - $sum_y * $sum_y ) );
		$coefficient = $denominator > 0 ? ( $n * $sum_xy - $sum_x * $sum_y ) / $denominator : 0.0;

		if ( $coefficient > 0.7 ) {
			$interpretation = 'strong positive correlation';
		} elseif ( $coefficient > 0.3 ) {
			$interpretation = 'moderate positive correlation';
		} elseif ( $coefficient < -0.7 ) {
			$interpretation = 'strong negative correlation';
		} elseif ( $coefficient < -0.3 ) {
			$interpretation = 'moderate negative correlation';
		} else {
			$interpretation = 'weak or no correlation';
		}

		return array(
			'coefficient'    => round( $coefficient, 4 ),
			'interpretation' => $interpretation,
		);
	}

	/**
	 * Detect outliers.
	 *
	 * Flags values outside the 1.5xIQR fences.
	 *
	 * @param array $data Numeric data array.
	 * @return array { outliers, lower_bound, upper_bound }.
	 */
	private function detect_outliers( $data ) {
		$values = array_map( 'floatval', array_values( $data ) );
		sort( $values );

		$q1 = $this->percentile( $values, 0.25 );
		$q3 = $this->percentile( $values, 0.75 );
		$iqr = $q3 - $q1;

		$lower_bound = $q1 - 1.5 * $iqr;
		$upper_bound = $q3 + 1.5 * $iqr;

		$outliers = array();
		foreach ( $values as $value ) {
			if ( $value < $lower_bound || $value > $upper_bound ) {
				$outliers[] = $value;
			}
		}

		return array(
			'outliers'    => $outliers,
			'lower_bound' => round( $lower_bound, 4 ),
			'upper_bound' => round( $upper_bound, 4 ),
		);
	}

	/**
	 * Percentile.
	 *
	 * Nearest-rank percentile over a sorted numeric array.
	 *
	 * @param array $sorted Sorted numeric values.
	 * @param float $p      Percentile between 0 and 1.
	 * @return float Percentile value.
	 */
	private function percentile( $sorted, $p ) {
		$count = count( $sorted );
		if ( 0 === $count ) {
			return 0.0;
		}
		$index = (int) ceil( $p * $count );
		$index = max( 1, min( $index, $count ) );
		return (float) $sorted[ $index - 1 ];
	}

	/**
	 * Generate_insights.
	 *
	 * @param mixed $analysis Parameter.
	 * @return array|WP_Error Result.
	 */
	private function generate_insights( $analysis ) {
		$insights = array();
		if ( isset( $analysis['trend'] ) ) {
			$insights[] = "Data shows a {$analysis['trend']} trend";
		}
		if ( $analysis['range'] > $analysis['mean'] * 2 ) {
			$insights[] = 'High variance detected - data is widely spread';
		}
		return $insights;
	}
}
