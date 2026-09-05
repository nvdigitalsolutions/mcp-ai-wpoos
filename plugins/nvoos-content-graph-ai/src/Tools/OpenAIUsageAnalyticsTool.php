<?php
/**
 * OpenAI Usage Analytics tool (D8 Cluster 2c-5 port of the base plugin's
 * WP_MCP_AI_Tool_OpenAI_Usage_Analytics — byte-identical slug, schema,
 * error codes, envelope, cost table, and analytics math).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Provides analytics on OpenAI API usage.
 */
class OpenAIUsageAnalyticsTool extends AbstractAiTool {

	/**
	 * Cost per 1K tokens for different models (input/output).
	 *
	 * @var array
	 */
	const MODEL_COSTS = array(
		'gpt-4o'                 => array(
			'input'  => 0.0025,
			'output' => 0.01,
		),
		'gpt-4o-mini'            => array(
			'input'  => 0.00015,
			'output' => 0.0006,
		),
		'gpt-4-turbo'            => array(
			'input'  => 0.01,
			'output' => 0.03,
		),
		'gpt-4'                  => array(
			'input'  => 0.03,
			'output' => 0.06,
		),
		'gpt-3.5-turbo'          => array(
			'input'  => 0.0005,
			'output' => 0.0015,
		),
		'dall-e-3'               => array( 'per_image' => 0.04 ), // Standard 1024x1024.
		'dall-e-2'               => array( 'per_image' => 0.02 ), // 1024x1024.
		'text-embedding-3-small' => array( 'input' => 0.00002 ),
		'text-embedding-3-large' => array( 'input' => 0.00013 ),
		'text-embedding-ada-002' => array( 'input' => 0.0001 ),
		'tts-1'                  => array( 'per_character' => 0.000015 ),
		'tts-1-hd'               => array( 'per_character' => 0.00003 ),
		'whisper-1'              => array( 'per_minute' => 0.006 ),
	);

	public function getSlug(): string {
		return 'openai_usage_analytics';
	}

	public function getName(): string {
		return __( 'OpenAI Usage Analytics', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Provides analytics on OpenAI API usage including total requests, tokens used, and estimated costs. Helps monitor and optimize API usage.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'       => array(
					'type'        => 'string',
					'description' => __( 'Time period for analytics.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'today', 'week', 'month', 'custom' ),
					'default'     => 'month',
				),
				'start_date'   => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom period (YYYY-MM-DD).', 'nvoos-content-graph-ai' ),
				),
				'end_date'     => array(
					'type'        => 'string',
					'description' => __( 'End date for custom period (YYYY-MM-DD).', 'nvoos-content-graph-ai' ),
				),
				'group_by'     => array(
					'type'        => 'string',
					'description' => __( 'How to group results.', 'nvoos-content-graph-ai' ),
					'enum'        => array( 'model', 'tool', 'date', 'user' ),
					'default'     => 'model',
				),
				'include_cost' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to calculate estimated costs.', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array(
			'read-only',
			'requires-capability',
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$period       = isset( $arguments['period'] ) ? sanitize_key( $arguments['period'] ) : 'month';
		$group_by     = isset( $arguments['group_by'] ) ? sanitize_key( $arguments['group_by'] ) : 'model';
		$include_cost = isset( $arguments['include_cost'] ) ? (bool) $arguments['include_cost'] : true;

		// Determine date range.
		$date_range = $this->get_date_range( $period, $arguments );
		if ( is_wp_error( $date_range ) ) {
			return $date_range;
		}

		// Get usage statistics from WordPress logs/metadata.
		$stats = $this->get_usage_statistics( $date_range, $group_by );

		// Calculate costs if requested.
		if ( $include_cost ) {
			$stats = $this->calculate_costs( $stats, $group_by );
		}

		return $this->ensure_response_message(
			array(
				'success' => true,
				'data'    => array(
					'period'         => $period,
					'start_date'     => $date_range['start'],
					'end_date'       => $date_range['end'],
					'total_requests' => $stats['total_requests'],
					'total_tokens'   => $stats['total_tokens'],
					'estimated_cost' => $include_cost ? $stats['estimated_cost'] : null,
					'breakdown'      => $stats['breakdown'],
					'top_models'     => $stats['top_models'],
					'top_tools'      => isset( $stats['top_tools'] ) ? $stats['top_tools'] : array(),
				),
			),
			__( 'Successfully retrieved OpenAI usage analytics', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Get date range based on period.
	 *
	 * @param string $period    Period type.
	 * @param array  $arguments Arguments.
	 * @return array|\WP_Error Date range array or error.
	 */
	private function get_date_range( $period, $arguments ) {
		$now = time();

		switch ( $period ) {
			case 'today':
				$start = strtotime( 'today', $now );
				$end   = $now;
				break;

			case 'week':
				$start = strtotime( '-7 days', $now );
				$end   = $now;
				break;

			case 'month':
				$start = strtotime( '-30 days', $now );
				$end   = $now;
				break;

			case 'custom':
				if ( empty( $arguments['start_date'] ) || empty( $arguments['end_date'] ) ) {
					return new \WP_Error( 'missing_dates', __( 'Start and end dates are required for custom period.', 'nvoos-content-graph-ai' ) );
				}

				$start = strtotime( sanitize_text_field( $arguments['start_date'] ) );
				$end   = strtotime( sanitize_text_field( $arguments['end_date'] ) );

				if ( false === $start || false === $end ) {
					return new \WP_Error( 'invalid_dates', __( 'Invalid date format. Use YYYY-MM-DD.', 'nvoos-content-graph-ai' ) );
				}
				break;

			default:
				$start = strtotime( '-30 days', $now );
				$end   = $now;
		}

		return array(
			'start' => gmdate( 'Y-m-d H:i:s', $start ),
			'end'   => gmdate( 'Y-m-d H:i:s', $end ),
		);
	}

	/**
	 * Get usage statistics from activity logs.
	 *
	 * @param array  $date_range Date range.
	 * @param string $group_by   Grouping method.
	 * @return array Statistics.
	 */
	private function get_usage_statistics( $date_range, $group_by ) {
		global $wpdb;

		// This is a simplified implementation. In a real scenario, you'd query
		// actual usage data from a custom table or transients.
		// For now, we'll return mock data structure.

		$stats = array(
			'total_requests' => 0,
			'total_tokens'   => 0,
			'estimated_cost' => 0,
			'breakdown'      => array(),
			'top_models'     => array(),
			'top_tools'      => array(),
		);

		// Try to get activity logs from NV oOS logger.
		$logs = get_option( 'wp_mcp_ai_recent_activity', array() );

		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$model_usage = array();
		$tool_usage  = array();

		foreach ( $logs as $log ) {
			if ( ! isset( $log['timestamp'] ) ) {
				continue;
			}

			// Check if log is within date range.
			$log_time = $log['timestamp'];
			if ( $log_time < strtotime( $date_range['start'] ) || $log_time > strtotime( $date_range['end'] ) ) {
				continue;
			}

			// Count requests.
			++$stats['total_requests'];

			// Track model usage.
			if ( isset( $log['model'] ) ) {
				$model = $log['model'];
				if ( ! isset( $model_usage[ $model ] ) ) {
					$model_usage[ $model ] = array(
						'requests' => 0,
						'tokens'   => 0,
					);
				}
				++$model_usage[ $model ]['requests'];

				if ( isset( $log['tokens'] ) ) {
					$tokens                           = absint( $log['tokens'] );
					$model_usage[ $model ]['tokens'] += $tokens;
					$stats['total_tokens']           += $tokens;
				}
			}

			// Track tool usage.
			if ( isset( $log['tool'] ) ) {
				$tool = $log['tool'];
				if ( ! isset( $tool_usage[ $tool ] ) ) {
					$tool_usage[ $tool ] = 0;
				}
				++$tool_usage[ $tool ];
			}
		}

		// Sort and limit top models.
		arsort( $model_usage );
		$stats['top_models'] = array_slice( $model_usage, 0, 5, true );

		// Sort and limit top tools.
		arsort( $tool_usage );
		$stats['top_tools'] = array_slice( $tool_usage, 0, 5, true );

		// Set breakdown based on grouping.
		if ( 'model' === $group_by ) {
			$stats['breakdown'] = $model_usage;
		} elseif ( 'tool' === $group_by ) {
			$stats['breakdown'] = $tool_usage;
		}

		return $stats;
	}

	/**
	 * Calculate estimated costs.
	 *
	 * @param array  $stats    Statistics array.
	 * @param string $group_by Grouping method.
	 * @return array Statistics with costs.
	 */
	private function calculate_costs( $stats, $group_by ) {
		$total_cost = 0;

		if ( 'model' === $group_by && ! empty( $stats['breakdown'] ) ) {
			foreach ( $stats['breakdown'] as $model => $usage ) {
				$cost                                 = $this->estimate_model_cost( $model, $usage );
				$stats['breakdown'][ $model ]['cost'] = $cost;
				$total_cost                          += $cost;
			}
		}

		// Calculate costs for top models.
		if ( ! empty( $stats['top_models'] ) ) {
			foreach ( $stats['top_models'] as $model => $usage ) {
				$cost                                  = $this->estimate_model_cost( $model, $usage );
				$stats['top_models'][ $model ]['cost'] = $cost;
			}
		}

		$stats['estimated_cost'] = $total_cost;

		return $stats;
	}

	/**
	 * Estimate cost for a model's usage.
	 *
	 * @param string $model Model identifier.
	 * @param array  $usage Usage data.
	 * @return float Estimated cost.
	 */
	private function estimate_model_cost( $model, $usage ) {
		if ( ! isset( self::MODEL_COSTS[ $model ] ) ) {
			return 0;
		}

		$costs      = self::MODEL_COSTS[ $model ];
		$total_cost = 0;

		if ( isset( $costs['input'] ) && isset( $usage['tokens'] ) ) {
			// Token-based pricing (per 1K tokens).
			$token_cost  = ( $usage['tokens'] / 1000 ) * $costs['input'];
			$total_cost += $token_cost;
		}

		if ( isset( $costs['per_image'] ) && isset( $usage['requests'] ) ) {
			// Image-based pricing.
			$total_cost += $usage['requests'] * $costs['per_image'];
		}

		return round( $total_cost, 4 );
	}

	/**
	 * Ensure a response array has a message field (base-identical helper).
	 *
	 * @param array  $response         Existing response array.
	 * @param string $fallback_message Message to use if none exists.
	 * @return array Response with guaranteed message field.
	 */
	private function ensure_response_message( $response, $fallback_message = '' ) {
		if ( ! is_array( $response ) ) {
			$response = array( 'data' => $response );
		}

		$message_keys  = array( 'message', 'text', 'summary', 'description' );
		$found_message = '';

		foreach ( $message_keys as $key ) {
			if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) && ! empty( $response[ $key ] ) ) {
				$found_message = $response[ $key ];
				break;
			}
		}

		if ( empty( $found_message ) ) {
			$response['message'] = $fallback_message;
		} else {
			$response['message'] = $found_message;
		}

		return $response;
	}
}
