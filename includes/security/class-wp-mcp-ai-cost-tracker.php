<?php
/**
 * Cost Tracker — Monitors and enforces AI API spending budgets.
 *
 * Tracks estimated API costs per assistant per time window against
 * admin-configured budgets. Prevents runaway spending on paid AI providers.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cost_Tracker' ) ) {
	/**
	 * AI cost tracking and budget enforcement.
	 *
	 * Usage (before making an API call):
	 *   $estimate = WP_MCP_AI_Cost_Tracker::estimate( $tool_slug, $arguments );
	 *   $check    = WP_MCP_AI_Cost_Tracker::check_budget( $assistant_id, $estimate );
	 *   if ( is_wp_error( $check ) ) { return $check; }
	 *   // ... make API call ...
	 *   WP_MCP_AI_Cost_Tracker::record( $assistant_id, $actual_cost );
	 */
	class WP_MCP_AI_Cost_Tracker {

		/**
		 * Option key for storing cumulative spend data.
		 */
		const SPEND_OPTION = 'wp_mcp_ai_cost_tracker_spend';

		/**
		 * Transient prefix for per-assistant hourly tracking.
		 */
		const HOURLY_PREFIX = 'wp_mcp_ai_cost_hourly_';

		/**
		 * Model pricing per 1M tokens (input, output). Prices in USD.
		 *
		 * Updated periodically; filterable for custom models.
		 *
		 * @var array<string, array{input: float, output: float}>
		 */
		const MODEL_PRICING = array(
			// OpenAI.
			'gpt-4o'            => array(
				'input'  => 2.50,
				'output' => 10.00,
			),
			'gpt-4o-mini'       => array(
				'input'  => 0.15,
				'output' => 0.60,
			),
			'gpt-4-turbo'       => array(
				'input'  => 10.00,
				'output' => 30.00,
			),
			'gpt-4'             => array(
				'input'  => 30.00,
				'output' => 60.00,
			),
			'gpt-3.5-turbo'     => array(
				'input'  => 0.50,
				'output' => 1.50,
			),
			'o1'                => array(
				'input'  => 15.00,
				'output' => 60.00,
			),
			'o1-mini'           => array(
				'input'  => 1.10,
				'output' => 4.40,
			),
			'o3-mini'           => array(
				'input'  => 1.10,
				'output' => 4.40,
			),
			// Anthropic.
			'claude-3-opus'     => array(
				'input'  => 15.00,
				'output' => 75.00,
			),
			'claude-3.5-sonnet' => array(
				'input'  => 3.00,
				'output' => 15.00,
			),
			'claude-3.5-haiku'  => array(
				'input'  => 0.80,
				'output' => 4.00,
			),
			// Gemini.
			'gemini-1.5-pro'    => array(
				'input'  => 1.25,
				'output' => 5.00,
			),
			'gemini-1.5-flash'  => array(
				'input'  => 0.075,
				'output' => 0.30,
			),
			// Image generation (flat cost per image).
			'dall-e-3'          => array(
				'per_image_1024'      => 0.040,
				'per_image_1792x1024' => 0.080,
			),
			'dall-e-2'          => array( 'per_image_1024' => 0.020 ),
			// Default fallback.
			'default'           => array(
				'input'  => 5.00,
				'output' => 20.00,
			),
		);

		/**
		 * Estimate the cost of a tool execution before making the API call.
		 *
		 * @param string $tool_slug Tool identifier.
		 * @param array  $arguments Tool arguments.
		 * @return float Estimated cost in USD.
		 */
		public static function estimate( $tool_slug, $arguments = array() ) {
			$estimate = 0.0;

			// Image generation tools.
			if ( false !== stripos( $tool_slug, 'image' ) || false !== stripos( $tool_slug, 'dall-e' ) ) {
				$size = isset( $arguments['size'] ) ? $arguments['size'] : '1024x1024';
				if ( false !== stripos( $size, '1792' ) || false !== stripos( $size, '1024x1792' ) ) {
					$estimate = 0.08;
				} else {
					$estimate = 0.04;
				}
				$count = isset( $arguments['n'] ) ? absint( $arguments['n'] ) : 1;
				return $estimate * max( 1, $count );
			}

			// Video generation — expensive, flat estimates.
			if ( false !== stripos( $tool_slug, 'video' ) || false !== stripos( $tool_slug, 'sora' ) || false !== stripos( $tool_slug, 'veo' ) ) {
				return 0.50; // Conservative estimate.
			}

			// Music generation.
			if ( false !== stripos( $tool_slug, 'music' ) || false !== stripos( $tool_slug, 'audio' ) ) {
				return 0.10;
			}

			// Speech generation.
			if ( false !== stripos( $tool_slug, 'speech' ) || false !== stripos( $tool_slug, 'tts' ) ) {
				return 0.015; // $15/1M chars ≈ 1.5¢ per request.
			}

			// Embeddings.
			if ( false !== stripos( $tool_slug, 'embedding' ) || false !== stripos( $tool_slug, 'vector' ) ) {
				return 0.0001; // ~$0.10/1M tokens.
			}

			// Default for text-generation tools — estimate from token count.
			$token_estimate = self::estimate_tokens( $arguments );
			$model          = isset( $arguments['model'] ) ? strtolower( $arguments['model'] ) : 'default';
			$pricing        = self::get_pricing( $model );

			return ( $token_estimate / 1000000 ) * $pricing['output'];
		}

		/**
		 * Check whether an estimated cost would exceed the assistant's budget.
		 *
		 * @param int   $assistant_id Assistant post ID.
		 * @param float $estimated    Estimated cost in USD.
		 * @return true|WP_Error True if within budget, WP_Error if exceeded.
		 */
		public static function check_budget( $assistant_id, $estimated ) {
			$budget = self::get_budget( $assistant_id );

			// No budget configured — allow.
			if ( $budget <= 0 ) {
				return true;
			}

			$spent = self::get_hourly_spend( $assistant_id );

			if ( ( $spent + $estimated ) > $budget ) {
				return new WP_Error(
					'cost_budget_exceeded',
					sprintf(
						/* translators: 1=spent, 2=budget, 3=estimated */
						__( 'Budget limit reached. Spent: $%1$.2f of $%2$.2f. Estimated cost of this operation: $%3$.4f.', 'mcp-ai-wpoos' ),
						$spent,
						$budget,
						$estimated
					)
				);
			}

			return true;
		}

		/**
		 * Record actual spend after an API call completes.
		 *
		 * @param int   $assistant_id Assistant post ID.
		 * @param float $actual_cost  Actual cost in USD.
		 * @return void
		 */
		public static function record( $assistant_id, $actual_cost ) {
			$assistant_id = absint( $assistant_id );
			if ( $assistant_id <= 0 || $actual_cost <= 0 ) {
				return;
			}

			// Update hourly transient.
			$key     = self::HOURLY_PREFIX . $assistant_id;
			$current = (float) get_transient( $key );
			set_transient( $key, $current + $actual_cost, HOUR_IN_SECONDS );

			// Update cumulative storage.
			$spend         = get_option( self::SPEND_OPTION, array() );
			$date_key      = gmdate( 'Y-m-d' );
			$assistant_key = (string) $assistant_id;

			if ( ! isset( $spend[ $date_key ] ) ) {
				$spend[ $date_key ] = array();
			}
			if ( ! isset( $spend[ $date_key ][ $assistant_key ] ) ) {
				$spend[ $date_key ][ $assistant_key ] = 0.0;
			}

			$spend[ $date_key ][ $assistant_key ] += $actual_cost;

			// Prune old entries (keep last 90 days).
			$cutoff = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
			foreach ( array_keys( $spend ) as $d ) {
				if ( $d < $cutoff ) {
					unset( $spend[ $d ] );
				}
			}

			update_option( self::SPEND_OPTION, $spend, false );
		}

		/**
		 * Get the hourly budget for an assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return float Budget in USD, or 0 for unlimited.
		 */
		public static function get_budget( $assistant_id ) {
			$budget = get_post_meta( $assistant_id, 'wp_mcp_ai_hourly_budget', true );
			return $budget ? (float) $budget : (float) apply_filters( 'wp_mcp_ai_default_hourly_budget', 0 );
		}

		/**
		 * Get the hourly spend for an assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return float USD spent this hour.
		 */
		public static function get_hourly_spend( $assistant_id ) {
			$key = self::HOURLY_PREFIX . absint( $assistant_id );
			return (float) get_transient( $key );
		}

		/**
		 * Get cumulative spend report.
		 *
		 * @param int|null $assistant_id Specific assistant or null for all.
		 * @param string   $since        Date string (Y-m-d).
		 * @return array Spend data.
		 */
		public static function get_report( $assistant_id = null, $since = null ) {
			$spend = get_option( self::SPEND_OPTION, array() );

			if ( null !== $since ) {
				$spend = array_filter(
					$spend,
					function ( $date ) use ( $since ) {
						return $date >= $since;
					},
					ARRAY_FILTER_USE_KEY
				);
			}

			if ( null !== $assistant_id ) {
				$key   = (string) absint( $assistant_id );
				$total = 0.0;
				foreach ( $spend as $day ) {
					$total += isset( $day[ $key ] ) ? (float) $day[ $key ] : 0.0;
				}
				return array(
					'assistant_id' => $assistant_id,
					'total'        => $total,
					'days'         => count( $spend ),
				);
			}

			$totals = array();
			foreach ( $spend as $day ) {
				foreach ( $day as $aid => $amount ) {
					if ( ! isset( $totals[ $aid ] ) ) {
						$totals[ $aid ] = 0.0;
					}
					$totals[ $aid ] += (float) $amount;
				}
			}

			return array(
				'totals' => $totals,
				'days'   => count( $spend ),
			);
		}

		/**
		 * Get pricing info for a model.
		 *
		 * @param string $model Model identifier.
		 * @return array{input: float, output: float}
		 */
		private static function get_pricing( $model ) {
			$pricing = apply_filters( 'wp_mcp_ai_model_pricing', self::MODEL_PRICING );

			// Exact match.
			if ( isset( $pricing[ $model ] ) ) {
				return $pricing[ $model ];
			}

			// Fuzzy match — check if model name contains a known key.
			foreach ( $pricing as $key => $price ) {
				if ( 'default' === $key ) {
					continue;
				}
				if ( false !== stripos( $model, $key ) ) {
					return $price;
				}
			}

			return $pricing['default'];
		}

		/**
		 * Estimate token count from tool arguments.
		 *
		 * @param array $arguments Tool arguments.
		 * @return int Estimated token count.
		 */
		private static function estimate_tokens( $arguments ) {
			$text = '';

			foreach ( $arguments as $key => $value ) {
				if ( is_string( $value ) ) {
					$text .= $value . ' ';
				}
			}

			// Rough estimate: 1 token ≈ 4 characters.
			$chars = strlen( $text );
			return max( 100, (int) ceil( $chars / 4 ) );
		}
	}
}
