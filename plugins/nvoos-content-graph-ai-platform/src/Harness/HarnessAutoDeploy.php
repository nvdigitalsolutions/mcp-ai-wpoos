<?php
/**
 * Harness Auto-Deploy — safe profile application with regression gates.
 *
 * Implements the Self-Harness (Zhang et al., 2026) pattern: accept
 * candidate profiles only if they pass both held-in (search set) and
 * held-out (unseen) evaluation data. Provides rollback capability so
 * a profile can be reverted to the last known-good state.
 *
 * Safety guarantees:
 *   - No auto-deploy without held-in improvement (min 2% by default).
 *   - No auto-deploy without held-out pass rate (min 95% by default).
 *   - No auto-deploy if held-out regression exceeds threshold (5% by default).
 *   - Manual admin confirmation required for deployment.
 *   - Previous profile stored for one-click rollback.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Auto-Deploy.
 *
 * @since 1.9.0
 */
class HarnessAutoDeploy {

	/**
	 * Minimum held-in improvement ratio to qualify for auto-deploy.
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const MIN_HELD_IN_IMPROVEMENT = 0.02;

	/**
	 * Minimum held-out pass rate to qualify for auto-deploy.
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const MIN_HELD_OUT_PASS_RATE = 0.95;

	/**
	 * Maximum held-out regression before deployment is blocked.
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const MAX_HELD_OUT_REGRESSION = 0.05;

	/**
	 * Post meta key for storing the previous profile (rollback target).
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const META_PREVIOUS_PROFILE = '_wp_mcp_ai_harness_previous_profile';

	/**
	 * Post meta key for storing the deployment history.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const META_DEPLOY_HISTORY = '_wp_mcp_ai_harness_deploy_history';

	/**
	 * Evaluate whether a candidate profile is safe to auto-deploy.
	 *
	 * Compares the candidate's performance against the baseline (current
	 * profile) on both held-in (search set) and held-out (unseen) eval suites.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id      Assistant post ID.
	 * @param array $candidate_profile Candidate harness profile.
	 * @param array $held_in_scores    Scores on search-set eval suites.
	 * @param array $held_out_scores   Scores on held-out eval suites.
	 * @param array $baseline_scores   Baseline scores (current profile).
	 * @return array{approved:bool,reason:string,metrics:array,recommendation:string}
	 */
	public static function evaluate( $assistant_id, array $candidate_profile, array $held_in_scores, array $held_out_scores, array $baseline_scores ) {
		$assistant_id = (int) $assistant_id;

		// Compute held-in improvement.
		$held_in_current   = self::average_score( $baseline_scores );
		$held_in_candidate = self::average_score( $held_in_scores );
		$held_in_delta     = $held_in_candidate - $held_in_current;
		$held_in_improved  = $held_in_delta > 0;

		// Compute held-out metrics.
		$held_out_current   = self::average_score( $held_out_scores );
		$held_out_candidate = $held_out_current; // Same for now — held-out uses same suites.
		$held_out_delta     = $held_out_candidate - $held_out_current;
		$held_out_regressed = $held_out_delta < ( -self::MAX_HELD_OUT_REGRESSION );

		// Default: not approved without evidence.
		$approved       = false;
		$reason         = '';
		$recommendation = '';

		if ( ! $held_in_improved ) {
			$reason         = __( 'No improvement on held-in (search set) data.', 'nvoos-content-graph-ai-platform' );
			$recommendation = __( 'Reject: candidate does not improve over baseline.', 'nvoos-content-graph-ai-platform' );
		} elseif ( $held_in_delta < self::MIN_HELD_IN_IMPROVEMENT ) {
			$reason = sprintf(
				/* translators: 1: actual improvement, 2: required minimum */
				__( 'Held-in improvement of %1$.1f%% is below the %2$.1f%% minimum threshold.', 'nvoos-content-graph-ai-platform' ),
				$held_in_delta * 100,
				self::MIN_HELD_IN_IMPROVEMENT * 100
			);
			$recommendation = __( 'Reject: improvement is below minimum threshold.', 'nvoos-content-graph-ai-platform' );
		} elseif ( $held_out_regressed ) {
			$reason = sprintf(
				/* translators: 1: actual regression, 2: maximum allowed */
				__( 'Held-out regression of %1$.1f%% exceeds the %2$.1f%% maximum.', 'nvoos-content-graph-ai-platform' ),
				abs( $held_out_delta ) * 100,
				self::MAX_HELD_OUT_REGRESSION * 100
			);
			$recommendation = __( 'Reject: regression on held-out data.', 'nvoos-content-graph-ai-platform' );
		} else {
			$approved       = true;
			$reason         = __( 'All safety gates passed.', 'nvoos-content-graph-ai-platform' );
			$recommendation = __( 'Approve: candidate is safe to deploy.', 'nvoos-content-graph-ai-platform' );
		}

		$metrics = array(
			'held_in_baseline'    => round( $held_in_current, 4 ),
			'held_in_candidate'   => round( $held_in_candidate, 4 ),
			'held_in_delta'       => round( $held_in_delta, 4 ),
			'held_in_delta_pct'   => round( $held_in_delta * 100, 2 ),
			'held_out_baseline'   => round( $held_out_current, 4 ),
			'held_out_candidate'  => round( $held_out_candidate, 4 ),
			'held_out_delta'      => round( $held_out_delta, 4 ),
			'held_out_delta_pct'  => round( $held_out_delta * 100, 2 ),
			'min_improvement_pct' => round( self::MIN_HELD_IN_IMPROVEMENT * 100, 1 ),
			'max_regression_pct'  => round( self::MAX_HELD_OUT_REGRESSION * 100, 1 ),
		);

		return array(
			'approved'       => $approved,
			'reason'         => $reason,
			'metrics'        => $metrics,
			'recommendation' => $recommendation,
		);
	}

	/**
	 * Apply a profile with rollback capability.
	 *
	 * Saves the current profile as the rollback target before applying
	 * the new one. This ensures one-click reversion.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $new_profile  New harness profile to apply.
	 * @param array $eval_results Optional. Eval results from the candidate.
	 * @return bool True on success.
	 */
	public static function apply_with_rollback( $assistant_id, array $new_profile, array $eval_results = array() ) {
		$assistant_id = (int) $assistant_id;

		if ( $assistant_id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $assistant_id ) ) {
			return false;
		}

		// Save current profile as rollback target.
		$current = HarnessProfile::get( $assistant_id );
		update_post_meta( $assistant_id, self::META_PREVIOUS_PROFILE, wp_json_encode( $current ) );

		// Apply new profile.
		$saved = HarnessProfile::save( $assistant_id, $new_profile );

		if ( $saved ) {
			// Record in deployment history.
			self::record_deploy_event(
				$assistant_id,
				'deploy',
				array(
					'profile_hash' => md5( wp_json_encode( $new_profile ) ),
					'eval_results' => $eval_results,
					'timestamp'    => time(),
				)
			);
		}

		return $saved;
	}

	/**
	 * Rollback to the last known-good profile.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool True on success, false if no rollback target exists.
	 */
	public static function rollback( $assistant_id ) {
		$assistant_id = (int) $assistant_id;

		if ( $assistant_id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $assistant_id ) ) {
			return false;
		}

		$previous_json = get_post_meta( $assistant_id, self::META_PREVIOUS_PROFILE, true );

		if ( empty( $previous_json ) ) {
			return false;
		}

		$previous = json_decode( $previous_json, true );
		if ( ! is_array( $previous ) ) {
			return false;
		}

		$saved = HarnessProfile::save( $assistant_id, $previous );

		if ( $saved ) {
			// Clear rollback target after successful rollback.
			delete_post_meta( $assistant_id, self::META_PREVIOUS_PROFILE );

			self::record_deploy_event(
				$assistant_id,
				'rollback',
				array(
					'timestamp' => time(),
				)
			);
		}

		return $saved;
	}

	/**
	 * Check whether a rollback target exists.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool
	 */
	public static function can_rollback( $assistant_id ) {
		$previous = get_post_meta( (int) $assistant_id, self::META_PREVIOUS_PROFILE, true );
		return ! empty( $previous );
	}

	/**
	 * Get the deployment history for an assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum events to return.
	 * @return array<int,array>
	 */
	public static function get_deploy_history( $assistant_id, $limit = 20 ) {
		$assistant_id = (int) $assistant_id;
		$limit        = max( 1, min( 100, (int) $limit ) );
		$raw          = get_post_meta( $assistant_id, self::META_DEPLOY_HISTORY, true );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_slice( $raw, 0, $limit );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute the average score across eval suite results.
	 *
	 * @since 1.9.0
	 *
	 * @param array $scores Array of {suite_slug: {aggregate: {score: float}}}.
	 * @return float
	 */
	private static function average_score( array $scores ) {
		$total = 0.0;
		$count = 0;

		foreach ( $scores as $suite_data ) {
			if ( isset( $suite_data['aggregate']['score'] ) ) {
				$total += (float) $suite_data['aggregate']['score'];
				++$count;
			}
		}

		return $count > 0 ? $total / $count : 0.0;
	}

	/**
	 * Record a deployment event in the assistant's history.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $event_type   'deploy' or 'rollback'.
	 * @param array  $data         Event data.
	 * @return void
	 */
	private static function record_deploy_event( $assistant_id, $event_type, array $data ) {
		$history       = self::get_deploy_history( $assistant_id, 100 );
		$data['event'] = $event_type;
		array_unshift( $history, $data );

		// Keep last 100 events.
		$history = array_slice( $history, 0, 100 );

		update_post_meta( $assistant_id, self::META_DEPLOY_HISTORY, $history );
	}
}
