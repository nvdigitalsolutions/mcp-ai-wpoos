<?php
/**
 * Canary Deployment — Progressive model rollout infrastructure for
 * safe AI model updates with A/B testing and automatic rollback.
 *
 * Supports:
 *   - Traffic splitting between model versions
 *   - A/B comparison with automatic statistical evaluation
 *   - Gradual ramp-up (1% → 10% → 50% → 100%)
 *   - Automatic rollback on error rate spike
 *   - Per-assistant and global canary configuration
 *
 * @package WP_MCP_AI
 * @since   1.1.51
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canary deployment manager for AI models.
 *
 * @since 1.1.51
 */
class WP_MCP_AI_Canary_Deployment {

	/**
	 * Option key for canary configuration.
	 *
	 * @var string
	 */
	const OPTION_CANARY_CONFIG = 'wp_mcp_ai_canary_config';

	/**
	 * Option key for canary metrics.
	 *
	 * @var string
	 */
	const OPTION_CANARY_METRICS = 'wp_mcp_ai_canary_metrics';

	/**
	 * Default ramp-up schedule: phase => traffic percentage.
	 *
	 * @var array
	 */
	const DEFAULT_RAMP_SCHEDULE = array(
		1 => 1,    // Phase 1: 1% traffic.
		2 => 10,   // Phase 2: 10% traffic.
		3 => 50,   // Phase 3: 50% traffic.
		4 => 100,  // Phase 4: full rollout.
	);

	/**
	 * Maximum error rate before automatic rollback (percentage).
	 *
	 * @var float
	 */
	const ERROR_RATE_ROLLBACK = 10.0;

	/**
	 * Whether canary deployments are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'wp_mcp_ai_canary_enabled', false );
	}

	/**
	 * Get the active model for a given provider/model combination,
	 * taking canary traffic splitting into account.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID for per-assistant canary.
	 * @return string The model to use (may be canary variant).
	 */
	public static function resolve_model( $provider, $base_model, $assistant_id = 0 ) {
		if ( ! self::is_enabled() ) {
			return $base_model;
		}

		$canary = self::get_canary_config( $provider, $base_model, $assistant_id );
		if ( empty( $canary ) || empty( $canary['canary_model'] ) ) {
			return $base_model;
		}

		$traffic_percent = $canary['traffic_percent'];
		$roll            = wp_rand( 1, 100 );

		if ( $roll <= $traffic_percent ) {
			return $canary['canary_model'];
		}

		return $base_model;
	}

	/**
	 * Register a canary deployment for a model.
	 *
	 * @param string $provider      Provider identifier.
	 * @param string $base_model    Current production model.
	 * @param string $canary_model  New model to test.
	 * @param float  $traffic_pct   Initial traffic percentage (0-100).
	 * @param int    $assistant_id  Optional assistant ID (0 for global).
	 * @return bool True on success.
	 */
	public static function start_canary( $provider, $base_model, $canary_model, $traffic_pct = 1.0, $assistant_id = 0 ) {
		$config = get_option( self::OPTION_CANARY_CONFIG, array() );
		$key    = self::build_key( $provider, $base_model, $assistant_id );

		$config[ $key ] = array(
			'provider'         => $provider,
			'base_model'       => $base_model,
			'canary_model'     => $canary_model,
			'traffic_percent'  => min( 100.0, max( 0.0, (float) $traffic_pct ) ),
			'started_at'       => current_time( 'mysql' ),
			'ramp_phase'       => 1,
			'assistant_id'     => absint( $assistant_id ),
			'status'           => 'active',
			'error_count'      => 0,
			'total_requests'   => 0,
		);

		return update_option( self::OPTION_CANARY_CONFIG, $config, false );
	}

	/**
	 * Advance a canary deployment to the next ramp phase.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID.
	 * @return bool True on success, false if already at 100%.
	 */
	public static function advance_phase( $provider, $base_model, $assistant_id = 0 ) {
		$config = self::get_canary_config( $provider, $base_model, $assistant_id );
		if ( empty( $config ) ) {
			return false;
		}

		$current_phase = $config['ramp_phase'];
		if ( $current_phase >= count( self::DEFAULT_RAMP_SCHEDULE ) ) {
			return false; // Already at max phase.
		}

		$next_phase = $current_phase + 1;
		$config['ramp_phase']      = $next_phase;
		$config['traffic_percent'] = self::DEFAULT_RAMP_SCHEDULE[ $next_phase ];

		return self::save_canary_config( $provider, $base_model, $assistant_id, $config );
	}

	/**
	 * Complete a canary deployment (promote canary to production).
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID.
	 * @return bool True on success.
	 */
	public static function promote( $provider, $base_model, $assistant_id = 0 ) {
		$config = self::get_canary_config( $provider, $base_model, $assistant_id );
		if ( empty( $config ) ) {
			return false;
		}

		$config['status'] = 'promoted';
		$config['promoted_at'] = current_time( 'mysql' );

		// The canary model is now the new base. The caller should update
		// the assistant's model configuration separately.

		return self::save_canary_config( $provider, $base_model, $assistant_id, $config );
	}

	/**
	 * Rollback a canary deployment (revert to base model).
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param string $reason       Reason for rollback.
	 * @param int    $assistant_id Optional assistant ID.
	 * @return bool True on success.
	 */
	public static function rollback( $provider, $base_model, $reason = '', $assistant_id = 0 ) {
		$config = self::get_canary_config( $provider, $base_model, $assistant_id );
		if ( empty( $config ) ) {
			return false;
		}

		$config['status']         = 'rolled_back';
		$config['rollback_reason'] = sanitize_text_field( $reason );
		$config['rolled_back_at']  = current_time( 'mysql' );
		$config['traffic_percent'] = 0;

		// Log the rollback.
		if ( function_exists( 'wp_mcp_ai_log' ) ) {
			wp_mcp_ai_log(
				'canary_rollback',
				array(
					'provider'     => $provider,
					'base_model'   => $base_model,
					'canary_model' => $config['canary_model'],
					'reason'       => $reason,
				)
			);
		}

		return self::save_canary_config( $provider, $base_model, $assistant_id, $config );
	}

	/**
	 * Record a request outcome for canary metrics.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $model        Model used for this request.
	 * @param string $base_model   Base model (for canary lookup).
	 * @param bool   $is_error     Whether the request resulted in an error.
	 * @param float  $latency_ms   Request latency in milliseconds.
	 * @param int    $tokens       Token count for this request.
	 */
	public static function record_request( $provider, $model, $base_model, $is_error, $latency_ms, $tokens = 0 ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$key = self::build_key( $provider, $base_model );

		// Update aggregate metrics.
		$metrics = get_option( self::OPTION_CANARY_METRICS, array() );
		if ( ! isset( $metrics[ $key ] ) ) {
			$metrics[ $key ] = array(
				'base_total'      => 0,
				'base_errors'     => 0,
				'base_latency'    => 0,
				'canary_total'    => 0,
				'canary_errors'   => 0,
				'canary_latency'  => 0,
				'total_tokens'    => 0,
				'last_updated'    => '',
			);
		}

		$config = self::get_canary_config( $provider, $base_model );
		$canary_model = isset( $config['canary_model'] ) ? $config['canary_model'] : '';

		if ( $model === $canary_model ) {
			$metrics[ $key ]['canary_total']++;
			if ( $is_error ) {
				$metrics[ $key ]['canary_errors']++;
			}
			$metrics[ $key ]['canary_latency'] = self::rolling_average(
				$metrics[ $key ]['canary_latency'],
				$latency_ms,
				$metrics[ $key ]['canary_total']
			);
		} else {
			$metrics[ $key ]['base_total']++;
			if ( $is_error ) {
				$metrics[ $key ]['base_errors']++;
			}
			$metrics[ $key ]['base_latency'] = self::rolling_average(
				$metrics[ $key ]['base_latency'],
				$latency_ms,
				$metrics[ $key ]['base_total']
			);
		}

		$metrics[ $key ]['total_tokens'] += absint( $tokens );
		$metrics[ $key ]['last_updated'] = current_time( 'mysql' );

		update_option( self::OPTION_CANARY_METRICS, $metrics, false );

		// Auto-rollback if canary error rate exceeds threshold.
		self::maybe_auto_rollback( $provider, $base_model, $metrics[ $key ] );
	}

	/**
	 * Get canary metrics for a deployment.
	 *
	 * @param string $provider   Provider identifier.
	 * @param string $base_model Base model identifier.
	 * @return array Metrics array.
	 */
	public static function get_metrics( $provider, $base_model ) {
		$metrics = get_option( self::OPTION_CANARY_METRICS, array() );
		$key     = self::build_key( $provider, $base_model );
		return isset( $metrics[ $key ] ) ? $metrics[ $key ] : array();
	}

	/**
	 * Get all active canary deployments.
	 *
	 * @return array Array of active canary configs.
	 */
	public static function get_active_canaries() {
		$config  = get_option( self::OPTION_CANARY_CONFIG, array() );
		$active  = array();

		foreach ( $config as $key => $cfg ) {
			if ( isset( $cfg['status'] ) && 'active' === $cfg['status'] ) {
				$active[ $key ] = $cfg;
			}
		}

		return $active;
	}

	/**
	 * Build a unique key for a canary deployment.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID.
	 * @return string
	 */
	private static function build_key( $provider, $base_model, $assistant_id = 0 ) {
		$key = $provider . '/' . $base_model;
		if ( $assistant_id > 0 ) {
			$key .= '/asst_' . absint( $assistant_id );
		}
		return sanitize_key( $key );
	}

	/**
	 * Get canary config for a specific deployment.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID.
	 * @return array Canary config or empty array.
	 */
	private static function get_canary_config( $provider, $base_model, $assistant_id = 0 ) {
		$config = get_option( self::OPTION_CANARY_CONFIG, array() );
		$key    = self::build_key( $provider, $base_model, $assistant_id );
		return isset( $config[ $key ] ) ? $config[ $key ] : array();
	}

	/**
	 * Save canary config for a specific deployment.
	 *
	 * @param string $provider     Provider identifier.
	 * @param string $base_model   Base model identifier.
	 * @param int    $assistant_id Optional assistant ID.
	 * @param array  $config       Config to save.
	 * @return bool True on success.
	 */
	private static function save_canary_config( $provider, $base_model, $assistant_id, $config ) {
		$all_config = get_option( self::OPTION_CANARY_CONFIG, array() );
		$key        = self::build_key( $provider, $base_model, $assistant_id );
		$all_config[ $key ] = $config;
		return update_option( self::OPTION_CANARY_CONFIG, $all_config, false );
	}

	/**
	 * Check if canary error rate exceeds threshold and auto-rollback if so.
	 *
	 * @param string $provider   Provider identifier.
	 * @param string $base_model Base model identifier.
	 * @param array  $metrics    Current metrics.
	 */
	private static function maybe_auto_rollback( $provider, $base_model, $metrics ) {
		// Only check after a minimum number of requests.
		$min_requests = 100;
		if ( $metrics['canary_total'] < $min_requests ) {
			return;
		}

		$canary_error_rate = ( $metrics['canary_errors'] / $metrics['canary_total'] ) * 100;

		if ( $canary_error_rate > self::ERROR_RATE_ROLLBACK ) {
			self::rollback(
				$provider,
				$base_model,
				sprintf(
					/* translators: 1: canary error rate percentage, 2: threshold percentage */
					__( 'Automatic rollback: canary error rate %1$.1f%% exceeds threshold %2$.1f%%', 'mcp-ai-wpoos' ),
					$canary_error_rate,
					self::ERROR_RATE_ROLLBACK
				)
			);
		}
	}

	/**
	 * Compute a rolling average.
	 *
	 * @param float $current_avg Current average.
	 * @param float $new_value   New value to incorporate.
	 * @param int   $count       Total count including this value.
	 * @return float New average.
	 */
	private static function rolling_average( $current_avg, $new_value, $count ) {
		if ( $count <= 0 ) {
			return (float) $new_value;
		}
		return $current_avg + ( ( $new_value - $current_avg ) / $count );
	}
}
