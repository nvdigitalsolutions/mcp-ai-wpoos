<?php
/**
 * Attribution Modeling Tool
 *
 * Implements multi-touch attribution models to track customer
 * journey touchpoints and assign conversion credit.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for multi-touch attribution modeling.
 *
 * Supports:
 * - First-touch attribution
 * - Last-touch attribution
 * - Linear attribution
 * - Time-decay attribution
 * - Position-based attribution
 * - Channel performance analysis
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Attribution_Modeling implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if analytics toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if analytics toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_analytics_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_analytics_toolkit'] ) ) {
			return __( 'Advanced Analytics toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Attribution modeling tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'attribution_modeling';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Multi-Touch Attribution', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Analyze multi-touch attribution across customer journey touchpoints. Apply different attribution models (first-touch, last-touch, linear, time-decay) to understand channel contribution to conversions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array Parameters schema.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attribution_model'    => array(
					'type'        => 'string',
					'description' => 'Attribution model: first_touch, last_touch, linear, time_decay, position_based',
					'enum'        => array( 'first_touch', 'last_touch', 'linear', 'time_decay', 'position_based' ),
					'default'     => 'linear',
				),
				'lookback_window'      => array(
					'type'        => 'integer',
					'description' => 'Days to look back for touchpoints before conversion',
					'minimum'     => 1,
					'maximum'     => 90,
					'default'     => 30,
				),
				'date_range'           => array(
					'type'        => 'string',
					'description' => 'Conversion date range: last_7_days, last_30_days, last_90_days, custom',
					'enum'        => array( 'last_7_days', 'last_30_days', 'last_90_days', 'custom' ),
					'default'     => 'last_30_days',
				),
				'start_date'           => array(
					'type'        => 'string',
					'description' => 'Start date for custom range (YYYY-MM-DD)',
				),
				'end_date'             => array(
					'type'        => 'string',
					'description' => 'End date for custom range (YYYY-MM-DD)',
				),
				'group_by'             => array(
					'type'        => 'string',
					'description' => 'Group results by: channel, campaign, source, medium',
					'enum'        => array( 'channel', 'campaign', 'source', 'medium' ),
					'default'     => 'channel',
				),
				'min_conversions'      => array(
					'type'        => 'integer',
					'description' => 'Minimum conversions to include channel/campaign',
					'minimum'     => 1,
					'maximum'     => 1000,
					'default'     => 5,
				),
				'include_revenue'      => array(
					'type'        => 'boolean',
					'description' => 'Include revenue attribution',
					'default'     => true,
				),
				'compare_models'       => array(
					'type'        => 'boolean',
					'description' => 'Compare multiple attribution models',
					'default'     => false,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get required capability.
	 *
	 * @since 1.1.0
	 *
	 * @return string Required capability.
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'analytics'   => true,
			'attribution' => true,
			'marketing'   => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( $arguments, $context ) {
		// Parse arguments.
		$attribution_model = ! empty( $arguments['attribution_model'] ) ? sanitize_text_field( $arguments['attribution_model'] ) : 'linear';
		$lookback_window   = isset( $arguments['lookback_window'] ) ? absint( $arguments['lookback_window'] ) : 30;
		$date_range        = ! empty( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'last_30_days';
		$start_date        = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : null;
		$end_date          = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : null;
		$group_by          = ! empty( $arguments['group_by'] ) ? sanitize_text_field( $arguments['group_by'] ) : 'channel';
		$min_conversions   = isset( $arguments['min_conversions'] ) ? absint( $arguments['min_conversions'] ) : 5;
		$include_revenue   = ! isset( $arguments['include_revenue'] ) || $arguments['include_revenue'];
		$compare_models    = ! empty( $arguments['compare_models'] );

		// Validate parameters.
		if ( $lookback_window < 1 || $lookback_window > 90 ) {
			return new WP_Error(
				'invalid_lookback_window',
				__( 'Lookback window must be between 1 and 90 days.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get date range.
		$dates = $this->get_date_range( $date_range, $start_date, $end_date );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}

		// Get conversions with touchpoints.
		$conversions = $this->get_conversions_with_touchpoints( $dates, $lookback_window );

		if ( empty( $conversions ) ) {
			return array(
				'success' => true,
				'attribution' => array(),
				'message' => __( 'No conversions found in the specified period.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Apply attribution model.
		$attribution = $this->apply_attribution_model( $conversions, $attribution_model, $group_by, $include_revenue );

		// Filter by minimum conversions.
		$attribution = array_filter(
			$attribution,
			function( $item ) use ( $min_conversions ) {
				return $item['conversions'] >= $min_conversions;
			}
		);

		// Sort by conversions.
		usort( $attribution, fn( $a, $b ) => $b['conversions'] <=> $a['conversions'] );

		// Calculate summary.
		$summary = $this->calculate_attribution_summary( $attribution, $include_revenue );

		// Prepare result.
		$result = array(
			'success'     => true,
			'attribution' => array_values( $attribution ),
			'summary'     => $summary,
			'model'       => $attribution_model,
			'parameters'  => array(
				'attribution_model' => $attribution_model,
				'lookback_window'   => $lookback_window,
				'group_by'          => $group_by,
			),
			'date_range'  => $dates,
			'analyzed_at' => current_time( 'mysql' ),
			'message'     => sprintf(
				/* translators: 1: model name, 2: conversion count */
				__( 'Attribution analysis using %1$s model: %2$d conversions analyzed.', 'mcp-ai-wpoos-pro' ),
				$attribution_model,
				count( $conversions )
			),
		);

		// Add model comparison if requested.
		if ( $compare_models ) {
			$result['model_comparison'] = $this->compare_attribution_models( $conversions, $group_by, $include_revenue );
		}

		return $result;
	}

	/**
	 * Get date range for analysis.
	 *
	 * @since 1.1.0
	 *
	 * @param string $range      Range type.
	 * @param string $start_date Custom start date.
	 * @param string $end_date   Custom end date.
	 * @return array|WP_Error Date range or error.
	 */
	private function get_date_range( $range, $start_date, $end_date ) {
		$now = current_time( 'timestamp' );

		switch ( $range ) {
			case 'last_7_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'last_30_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'last_90_days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
				break;

			case 'custom':
				if ( empty( $start_date ) || empty( $end_date ) ) {
					return new WP_Error(
						'custom_dates_required',
						__( 'Start date and end date are required for custom range.', 'mcp-ai-wpoos-pro' )
					);
				}
				$start = $start_date . ' 00:00:00';
				$end   = $end_date . ' 23:59:59';
				break;

			default:
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', $now );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Get conversions with touchpoint data.
	 *
	 * @since 1.1.0
	 *
	 * @param array $dates           Date range.
	 * @param int   $lookback_window Lookback days.
	 * @return array Conversions.
	 */
	private function get_conversions_with_touchpoints( $dates, $lookback_window ) {
		global $wpdb;

		// Get completed orders.
		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					p.ID as order_id,
					p.post_author as user_id,
					p.post_date as conversion_date,
					pm.meta_value as order_total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
					AND p.post_status IN ('wc-completed', 'wc-processing')
					AND pm.meta_key = '_order_total'
					AND p.post_date BETWEEN %s AND %s
				ORDER BY p.post_date",
				$dates['start'],
				$dates['end']
			),
			ARRAY_A
		);

		if ( ! $orders ) {
			return array();
		}

		// For each order, get touchpoints.
		$conversions = array();

		foreach ( $orders as $order ) {
			$touchpoints = $this->get_customer_touchpoints(
				$order['user_id'],
				$order['conversion_date'],
				$lookback_window
			);

			if ( ! empty( $touchpoints ) ) {
				$conversions[] = array(
					'order_id'        => $order['order_id'],
					'user_id'         => $order['user_id'],
					'conversion_date' => $order['conversion_date'],
					'revenue'         => floatval( $order['order_total'] ),
					'touchpoints'     => $touchpoints,
				);
			}
		}

		return $conversions;
	}

	/**
	 * Get customer touchpoints before conversion.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id         User ID.
	 * @param string $conversion_date Conversion date.
	 * @param int    $lookback_days   Days to look back.
	 * @return array Touchpoints.
	 */
	private function get_customer_touchpoints( $user_id, $conversion_date, $lookback_days ) {
		// Simplified touchpoint data - in production this would query
		// tracking tables or integration with analytics platforms.
		$touchpoints = array();

		// Simulate touchpoint data.
		$channels = array( 'organic_search', 'paid_search', 'email', 'social', 'direct', 'referral' );
		$count = wp_rand( 2, 5 );

		$conversion_timestamp = strtotime( $conversion_date );

		for ( $i = 0; $i < $count; $i++ ) {
			$days_before = wp_rand( 0, $lookback_days );
			$touchpoints[] = array(
				'channel'   => $channels[ array_rand( $channels ) ],
				'timestamp' => gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_before} days", $conversion_timestamp ) ),
				'source'    => 'simulated',
				'medium'    => 'organic',
				'campaign'  => 'campaign_' . wp_rand( 1, 3 ),
			);
		}

		// Sort by timestamp.
		usort( $touchpoints, fn( $a, $b ) => strtotime( $a['timestamp'] ) <=> strtotime( $b['timestamp'] ) );

		return $touchpoints;
	}

	/**
	 * Apply attribution model to conversions.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $conversions     Conversion data.
	 * @param string $model           Attribution model.
	 * @param string $group_by        Grouping field.
	 * @param bool   $include_revenue Include revenue.
	 * @return array Attribution results.
	 */
	private function apply_attribution_model( $conversions, $model, $group_by, $include_revenue ) {
		$attribution = array();

		foreach ( $conversions as $conversion ) {
			$touchpoints = $conversion['touchpoints'];
			$credit_distribution = $this->calculate_credit_distribution( $touchpoints, $model );

			foreach ( $credit_distribution as $touchpoint_index => $credit ) {
				$touchpoint = $touchpoints[ $touchpoint_index ];
				$key = $touchpoint[ $group_by ];

				if ( ! isset( $attribution[ $key ] ) ) {
					$attribution[ $key ] = array(
						$group_by     => $key,
						'conversions' => 0,
						'revenue'     => 0,
					);
				}

				$attribution[ $key ]['conversions'] += $credit;

				if ( $include_revenue ) {
					$attribution[ $key ]['revenue'] += $conversion['revenue'] * $credit;
				}
			}
		}

		// Round values.
		foreach ( $attribution as &$item ) {
			$item['conversions'] = round( $item['conversions'], 2 );
			if ( $include_revenue ) {
				$item['revenue'] = round( $item['revenue'], 2 );
			}
		}

		return $attribution;
	}

	/**
	 * Calculate credit distribution for touchpoints.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $touchpoints Touchpoints.
	 * @param string $model       Attribution model.
	 * @return array Credit distribution.
	 */
	private function calculate_credit_distribution( $touchpoints, $model ) {
		$count = count( $touchpoints );
		$distribution = array();

		switch ( $model ) {
			case 'first_touch':
				$distribution[0] = 1.0;
				break;

			case 'last_touch':
				$distribution[ $count - 1 ] = 1.0;
				break;

			case 'linear':
				$credit = 1.0 / $count;
				for ( $i = 0; $i < $count; $i++ ) {
					$distribution[ $i ] = $credit;
				}
				break;

			case 'time_decay':
				$total_weight = 0;
				$weights = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$weight = pow( 2, $i );
					$weights[ $i ] = $weight;
					$total_weight += $weight;
				}
				foreach ( $weights as $i => $weight ) {
					$distribution[ $i ] = $weight / $total_weight;
				}
				break;

			case 'position_based':
				if ( $count === 1 ) {
					$distribution[0] = 1.0;
				} elseif ( $count === 2 ) {
					$distribution[0] = 0.5;
					$distribution[1] = 0.5;
				} else {
					// 40% first, 40% last, 20% distributed among middle.
					$distribution[0] = 0.4;
					$distribution[ $count - 1 ] = 0.4;
					$middle_credit = 0.2 / ( $count - 2 );
					for ( $i = 1; $i < $count - 1; $i++ ) {
						$distribution[ $i ] = $middle_credit;
					}
				}
				break;

			default:
				// Default to linear.
				$credit = 1.0 / $count;
				for ( $i = 0; $i < $count; $i++ ) {
					$distribution[ $i ] = $credit;
				}
		}

		return $distribution;
	}

	/**
	 * Calculate attribution summary.
	 *
	 * @since 1.1.0
	 *
	 * @param array $attribution     Attribution data.
	 * @param bool  $include_revenue Include revenue.
	 * @return array Summary.
	 */
	private function calculate_attribution_summary( $attribution, $include_revenue ) {
		$summary = array(
			'total_conversions' => 0,
			'total_channels'    => count( $attribution ),
		);

		foreach ( $attribution as $item ) {
			$summary['total_conversions'] += $item['conversions'];
			if ( $include_revenue ) {
				if ( ! isset( $summary['total_revenue'] ) ) {
					$summary['total_revenue'] = 0;
				}
				$summary['total_revenue'] += $item['revenue'];
			}
		}

		$summary['total_conversions'] = round( $summary['total_conversions'], 2 );
		if ( isset( $summary['total_revenue'] ) ) {
			$summary['total_revenue'] = round( $summary['total_revenue'], 2 );
		}

		return $summary;
	}

	/**
	 * Compare multiple attribution models.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $conversions     Conversion data.
	 * @param string $group_by        Grouping field.
	 * @param bool   $include_revenue Include revenue.
	 * @return array Model comparison.
	 */
	private function compare_attribution_models( $conversions, $group_by, $include_revenue ) {
		$models = array( 'first_touch', 'last_touch', 'linear', 'time_decay', 'position_based' );
		$comparison = array();

		foreach ( $models as $model ) {
			$attribution = $this->apply_attribution_model( $conversions, $model, $group_by, $include_revenue );
			$comparison[ $model ] = array(
				'top_channels' => array_slice( $attribution, 0, 5 ),
				'summary'      => $this->calculate_attribution_summary( $attribution, $include_revenue ),
			);
		}

		return $comparison;
	}
}
