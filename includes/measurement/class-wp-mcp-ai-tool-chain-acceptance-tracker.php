<?php
/**
 * Tool Chain Acceptance Tracker
 *
 * Measures how often predicted tool sequences are actually used by the
 * orchestrator, feeding acceptance data back into chain predictor weights.
 * Inspired by DSpark's acceptance length metric — longer consecutive
 * accepted predictions indicate higher-quality chains that can be
 * speculatively pre-warmed with greater confidence.
 *
 * Stores rolling history in a WordPress option (soft-capped at
 * HISTORY_LIMIT entries) and provides aggregate statistics and
 * improvement suggestions for downstream services.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks prediction-vs-actual tool chain acceptance.
 *
 * Each recorded entry captures which tools were predicted, which were
 * actually executed, and the resulting acceptance length, so the chain
 * predictor can adjust its weights over time.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Tool_Chain_Acceptance_Tracker {

	/**
	 * WordPress option key used to persist acceptance metrics.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const METRIC_KEY = 'wp_mcp_ai_chain_acceptance_metrics';

	/**
	 * Maximum number of historical entries to retain. Older entries are
	 * pruned when the limit is exceeded.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const HISTORY_LIMIT = 200;

	/**
	 * Time-to-live (in seconds) for cached aggregated stats. After this
	 * window the stats are recalculated from raw history.
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const METRICS_TTL = 86400;

	/**
	 * In-memory cache of the stored metrics, populated on first read.
	 *
	 * @since 1.3.0
	 * @var array<string,mixed>|null
	 */
	private $cached_metrics = null;

	/**
	 * Constructor.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		// No external dependencies required — everything is self-contained.
	}

	/**
	 * Record a prediction-vs-actual acceptance entry.
	 *
	 * Compares the predicted tool chain against the tools actually executed
	 * and stores the result for later aggregation.
	 *
	 * @since 1.3.0
	 *
	 * @param string   $task_id   Unique identifier for the orchestration task.
	 * @param string[] $predicted Ordered list of predicted tool slugs.
	 * @param string[] $executed  Ordered list of actually executed tool slugs.
	 * @param string   $task_type Optional task category (e.g. 'content',
	 *                            'ecommerce', 'general'). Default 'general'.
	 * @return array{
	 *     'accepted_count': int,
	 *     'total_predicted': int,
	 *     'acceptance_rate': float,
	 *     'acceptance_length': int
	 * }|WP_Error Acceptance data, or WP_Error on invalid input.
	 */
	public function record_acceptance( $task_id, $predicted, $executed, $task_type = 'general' ) {
		// Sanitise inputs.
		$task_id = sanitize_text_field( (string) $task_id );

		if ( empty( $task_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_task_id',
				esc_html__( 'A non-empty task ID is required to record acceptance.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! is_array( $predicted ) ) {
			$predicted = array();
		}
		if ( ! is_array( $executed ) ) {
			$executed = array();
		}

		// Sanitise each slug.
		$predicted = array_map( 'sanitize_key', $predicted );
		$executed  = array_map( 'sanitize_key', $executed );
		$task_type = sanitize_key( (string) $task_type );
		if ( empty( $task_type ) ) {
			$task_type = 'general';
		}

		$total_predicted = count( $predicted );
		if ( 0 === $total_predicted ) {
			return new WP_Error(
				'wp_mcp_ai_empty_predicted',
				esc_html__( 'The predicted chain cannot be empty.', 'mcp-ai-wpoos' )
			);
		}

		// Compute acceptance length — how many consecutive predictions matched.
		$acceptance_length = $this->compute_acceptance_length( $predicted, $executed );
		$accepted_count    = min( $acceptance_length, $total_predicted );
		$acceptance_rate   = $accepted_count / $total_predicted;

		$entry = array(
			'task_id'           => $task_id,
			'task_type'         => $task_type,
			'predicted'         => $predicted,
			'executed'          => $executed,
			'accepted_count'    => $accepted_count,
			'total_predicted'   => $total_predicted,
			'acceptance_rate'   => $acceptance_rate,
			'acceptance_length' => $acceptance_length,
			'recorded_at'       => time(),
		);

		$metrics            = $this->get_stored_metrics();
		$metrics['history'] = isset( $metrics['history'] ) && is_array( $metrics['history'] )
			? $metrics['history']
			: array();

		// Prepend so newest entries appear first.
		array_unshift( $metrics['history'], $entry );

		// Invalidate cached aggregate stats so they are recalculated.
		unset( $metrics['cached_stats'] );

		$this->store_metrics( $metrics );

		// Prune if we exceeded the limit.
		$this->prune_history();

		// Return the acceptance data for immediate use.
		return array(
			'accepted_count'    => $accepted_count,
			'total_predicted'   => $total_predicted,
			'acceptance_rate'   => $acceptance_rate,
			'acceptance_length' => $acceptance_length,
		);
	}

	/**
	 * Get aggregated acceptance statistics.
	 *
	 * Computes overall rate, average acceptance length, per-task-type
	 * breakdown, and a simple trend indicator based on recent history.
	 *
	 * @since 1.3.0
	 *
	 * @param string|null $task_type       Optional task type to filter by.
	 *                                     If null, returns all types.
	 * @param int         $lookback_seconds How far back to consider (default
	 *                                     86400 = 24 hours).
	 * @return array{
	 *     'overall_rate': float,
	 *     'avg_acceptance_length': float,
	 *     'by_task_type': array<string,array{rate:float,count:int}>,
	 *     'trend': string,
	 *     'total_entries': int
	 * } Aggregated stats.
	 */
	public function get_acceptance_stats( $task_type = null, $lookback_seconds = 86400 ) {
		$task_type       = null !== $task_type ? sanitize_key( (string) $task_type ) : null;
		$lookback_seconds = absint( $lookback_seconds );
		if ( $lookback_seconds < 1 ) {
			$lookback_seconds = self::METRICS_TTL;
		}

		$metrics = $this->get_stored_metrics();

		// Return cached stats when still fresh and no type filter is applied.
		if ( null === $task_type
			&& isset( $metrics['cached_stats']['computed_at'] )
			&& ( time() - $metrics['cached_stats']['computed_at'] ) < self::METRICS_TTL
		) {
			return $metrics['cached_stats'];
		}

		$history = isset( $metrics['history'] ) && is_array( $metrics['history'] )
			? $metrics['history']
			: array();

		$cutoff       = time() - $lookback_seconds;
		$rates        = array();
		$lengths      = array();
		$by_task_type = array();
		$trend_data   = array(); // For computing trend.

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['recorded_at'] ) ) {
				continue;
			}

			$recorded_at = (int) $entry['recorded_at'];
			if ( $recorded_at < $cutoff ) {
				continue;
			}

			$entry_type = isset( $entry['task_type'] ) ? sanitize_key( $entry['task_type'] ) : 'general';
			if ( null !== $task_type && $entry_type !== $task_type ) {
				continue;
			}

			$rate   = isset( $entry['acceptance_rate'] ) ? (float) $entry['acceptance_rate'] : 0.0;
			$length = isset( $entry['acceptance_length'] ) ? (int) $entry['acceptance_length'] : 0;

			$rates[]   = $rate;
			$lengths[] = $length;

			// Per-type aggregation.
			if ( ! isset( $by_task_type[ $entry_type ] ) ) {
				$by_task_type[ $entry_type ] = array(
					'rate'  => 0.0,
					'count' => 0,
				);
			}
			$by_task_type[ $entry_type ]['rate']   += $rate;
			$by_task_type[ $entry_type ]['count']  += 1;

			$trend_data[] = array(
				'time' => $recorded_at,
				'rate' => $rate,
			);
		}

		// Compute per-type averages.
		foreach ( $by_task_type as $type => &$data ) {
			if ( $data['count'] > 0 ) {
				$data['rate'] = round( $data['rate'] / $data['count'], 4 );
			}
		}
		unset( $data );

		// Overall rate.
		$total_entries = count( $rates );
		$overall_rate  = $total_entries > 0
			? round( array_sum( $rates ) / $total_entries, 4 )
			: 0.0;

		// Average acceptance length.
		$avg_acceptance_length = count( $lengths ) > 0
			? round( array_sum( $lengths ) / count( $lengths ), 1 )
			: 0.0;

		// Trend: compare the most recent half of entries against the older half.
		$trend = 'stable';
		if ( count( $trend_data ) >= 4 ) {
			// Sort by time ascending.
			usort(
				$trend_data,
				function ( $a, $b ) {
					return $a['time'] <=> $b['time'];
				}
			);

			$mid           = (int) floor( count( $trend_data ) / 2 );
			$recent_half   = array_slice( $trend_data, $mid );
			$older_half    = array_slice( $trend_data, 0, $mid );

			$recent_avg = array_sum( array_column( $recent_half, 'rate' ) ) / count( $recent_half );
			$older_avg  = array_sum( array_column( $older_half, 'rate' ) ) / count( $older_half );

			$delta = $recent_avg - $older_avg;

			if ( $delta > 0.05 ) {
				$trend = 'improving';
			} elseif ( $delta < -0.05 ) {
				$trend = 'declining';
			}
		}

		$stats = array(
			'overall_rate'          => $overall_rate,
			'avg_acceptance_length' => $avg_acceptance_length,
			'by_task_type'          => $by_task_type,
			'trend'                 => $trend,
			'total_entries'         => $total_entries,
		);

		// Cache aggregated stats for unfiltered lookups.
		if ( null === $task_type ) {
			$stats['computed_at']    = time();
			$metrics['cached_stats'] = $stats;
			$this->store_metrics( $metrics );
		}

		return $stats;
	}

	/**
	 * Generate improvement suggestions based on historical acceptance data.
	 *
	 * Analyses patterns in the recorded history and returns actionable
	 * suggestions that can be surfaced to the chain predictor or admin UI.
	 *
	 * @since 1.3.0
	 *
	 * @return string[] Array of human-readable suggestion strings.
	 */
	public function get_improvement_suggestions() {
		$stats   = $this->get_acceptance_stats();
		$metrics = $this->get_stored_metrics();
		$history = isset( $metrics['history'] ) && is_array( $metrics['history'] )
			? $metrics['history']
			: array();

		$suggestions = array();

		// Suggestion 1: overall rate alert.
		if ( $stats['overall_rate'] < 0.5 ) {
			$suggestions[] = esc_html__(
				'Overall chain acceptance rate is below 50%. Consider reducing prediction length or increasing the minimum confidence threshold for speculative pre-warming.',
				'mcp-ai-wpoos'
			);
		}

		// Suggestion 2: negative trend.
		if ( 'declining' === $stats['trend'] ) {
			$suggestions[] = esc_html__(
				'Acceptance rate is trending downward. Review recent tool usage patterns — new tools or changed workflows may need re-patterned in the predictor.',
				'mcp-ai-wpoos'
			);
		}

		// Suggestion 3: identify task types with low acceptance.
		if ( ! empty( $stats['by_task_type'] ) ) {
			foreach ( $stats['by_task_type'] as $type => $data ) {
				if ( $data['count'] >= 3 && $data['rate'] < 0.4 ) {
					$suggestions[] = sprintf(
						/* translators: 1: task type, 2: acceptance rate percentage */
						esc_html__(
							'Task type "%1$s" has a low acceptance rate (%2$d%%). The chain predictor may need type-specific tuning or additional training data.',
							'mcp-ai-wpoos'
						),
						esc_html( $type ),
						(int) round( $data['rate'] * 100 )
					);
				}
			}
		}

		// Suggestion 4: if we have enough data but average length is very short.
		if ( $stats['total_entries'] >= 10 && $stats['avg_acceptance_length'] < 1.5 ) {
			$suggestions[] = esc_html__(
				'Average acceptance length is below 1.5 tools. The predictor may be over-predicting — try shortening the predicted chain or only predicting high-confidence tools.',
				'mcp-ai-wpoos'
			);
		}

		// Suggestion 5: if we have very little data.
		if ( $stats['total_entries'] < 5 ) {
			$suggestions[] = esc_html__(
				'Not enough acceptance data to make recommendations. Continue running orchestrated tasks to build a meaningful history.',
				'mcp-ai-wpoos'
			);
		}

		return $suggestions;
	}

	/**
	 * Remove entries beyond HISTORY_LIMIT.
	 *
	 * Keeps the option storage bounded by dropping the oldest entries when
	 * the history grows past the configured limit.
	 *
	 * @since 1.3.0
	 *
	 * @return int Number of entries pruned.
	 */
	public function prune_history() {
		$metrics = $this->get_stored_metrics();
		$history = isset( $metrics['history'] ) && is_array( $metrics['history'] )
			? $metrics['history']
			: array();

		$total  = count( $history );
		$excess = $total - self::HISTORY_LIMIT;

		if ( $excess <= 0 ) {
			return 0;
		}

		// Entries are prepended, so the oldest are at the end.
		$metrics['history'] = array_slice( $history, 0, self::HISTORY_LIMIT );

		// Invalidate cached stats.
		unset( $metrics['cached_stats'] );

		$this->store_metrics( $metrics );

		return $excess;
	}

	/**
	 * Count the number of consecutive accepted predictions.
	 *
	 * Starting from the first predicted tool, counts how many in sequence
	 * match the actually executed tools (in the same order).
	 *
	 * @since 1.3.0
	 *
	 * @param string[] $predicted Ordered list of predicted tool slugs.
	 * @param string[] $executed  Ordered list of executed tool slugs.
	 * @return int Number of consecutive matches from the start of the chain.
	 */
	protected function compute_acceptance_length( $predicted, $executed ) {
		$length = 0;
		$max    = min( count( $predicted ), count( $executed ) );

		for ( $i = 0; $i < $max; $i++ ) {
			if ( $predicted[ $i ] === $executed[ $i ] ) {
				$length++;
			} else {
				break;
			}
		}

		return $length;
	}

	/**
	 * Retrieve the stored metrics array from the WordPress options table.
	 *
	 * Returns a normalised array even if the option does not yet exist.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string,mixed> The metrics data.
	 */
	protected function get_stored_metrics() {
		if ( null !== $this->cached_metrics ) {
			return $this->cached_metrics;
		}

		$metrics = get_option( self::METRIC_KEY, array() );

		if ( ! is_array( $metrics ) ) {
			$metrics = array();
		}

		// Ensure expected keys exist.
		if ( ! isset( $metrics['history'] ) || ! is_array( $metrics['history'] ) ) {
			$metrics['history'] = array();
		}

		$this->cached_metrics = $metrics;

		return $this->cached_metrics;
	}

	/**
	 * Write the metrics array to the WordPress options table.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string,mixed> $metrics The full metrics data to persist.
	 * @return void
	 */
	protected function store_metrics( $metrics ) {
		if ( ! is_array( $metrics ) ) {
			return;
		}

		$this->cached_metrics = $metrics;
		update_option( self::METRIC_KEY, $metrics, false );
	}
}
