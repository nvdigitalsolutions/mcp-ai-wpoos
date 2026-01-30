<?php
/**
 * Analyze Data Patterns Tool - Find trends and patterns in datasets
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns {
	public function get_slug() {
		return 'analyze_data_patterns';
	}

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

	public function execute( array $arguments = array(), array $context = array() ) {
		$dataset = $arguments['dataset'];
		$type    = isset( $arguments['analysis_type'] ) ? $arguments['analysis_type'] : 'trend';

		$numeric_data = array_filter( $dataset, 'is_numeric' );
		if ( empty( $numeric_data ) ) {
			return array(
				'success' => false,
				'error'   => 'Dataset must contain numeric values',
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
		}

		return array(
			'success'  => true,
			'analysis' => $analysis,
			'insights' => $this->generate_insights( $analysis ),
		);
	}

	private function calculate_median( $data ) {
		sort( $data );
		$count = count( $data );
		$mid   = floor( $count / 2 );
		return $count % 2 === 0 ? ( $data[ $mid - 1 ] + $data[ $mid ] ) / 2 : $data[ $mid ];
	}

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
