<?php
/**
 * Provider Health Tracker — real-time health scoring and failover chain.
 *
 * Tracks per-provider success/failure signals to compute a health score
 * that the ProviderRouter uses for automatic failover.
 *
 * Scoring model:
 *   +1 per successful request (with latency bonus)
 *   -5 per failed request (errors penalize more than successes reward)
 *   Scores below -10 = unhealthy (configurable)
 *
 * Storage: Transients with 5-minute TTL. On Redis-backed sites this is
 * near-real-time; on sites without object cache scores are recomputed
 * per request (lightweight calculation).
 *
 * @package  Nvoos\Core
 * @since    1.2.5
 * @license  MIT
 */

declare( strict_types=1 );

namespace Nvoos\Core\Application\Provider;

class ProviderHealthTracker {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const SCORE_KEY_PREFIX = 'nvoos_provider_score_';

	/**
	 * Default health score threshold below which a provider is marked unhealthy.
	 *
	 * @var int
	 */
	private const DEFAULT_THRESHOLD = -10;

	/**
	 * TTL for provider health scores in seconds.
	 *
	 * @var int
	 */
	private const SCORE_TTL = 300; // 5 minutes.

	/**
	 * Record a successful provider request.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @param float  $latencyMs    Request latency in milliseconds.
	 * @return void
	 */
	public function recordSuccess( string $providerSlug, float $latencyMs = 0.0 ): void {
		$score = $this->getScore( $providerSlug );

		// Bonus for fast responses (+2 if < 1s, +1 otherwise).
		$bonus = $latencyMs > 0 && $latencyMs < 1000 ? 2 : 1;
		$score = min( 100, $score + $bonus ); // Cap at 100.

		$this->setScore( $providerSlug, $score );
	}

	/**
	 * Record a failed provider request.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @param string $errorType    Error classification (e.g. 'rate_limit', 'timeout', '5xx').
	 * @return void
	 */
	public function recordFailure( string $providerSlug, string $errorType = 'unknown' ): void {
		$score = $this->getScore( $providerSlug );

		// Rate-limit and timeout errors are "softer" than 5xx errors.
		$penalty = in_array( $errorType, array( 'rate_limit', 'timeout' ), true ) ? 3 : 5;
		$score   = max( -100, $score - $penalty ); // Floor at -100.

		$this->setScore( $providerSlug, $score );
	}

	/**
	 * Check if a provider is currently healthy.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @param int    $threshold    Health threshold (default: -10).
	 * @return bool True when healthy.
	 */
	public function isHealthy( string $providerSlug, int $threshold = self::DEFAULT_THRESHOLD ): bool {
		return $this->getScore( $providerSlug ) > $threshold;
	}

	/**
	 * Get the health score for a provider.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @return int Score between -100 and 100.
	 */
	public function getScore( string $providerSlug ): int {
		$slug    = sanitize_key( $providerSlug );
		$transient = get_transient( self::SCORE_KEY_PREFIX . $slug );

		return false !== $transient ? (int) $transient : 0;
	}

	/**
	 * Set the health score for a provider.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @param int    $score        New score.
	 * @return void
	 */
	private function setScore( string $providerSlug, int $score ): void {
		$slug = sanitize_key( $providerSlug );
		set_transient( self::SCORE_KEY_PREFIX . $slug, $score, self::SCORE_TTL );
	}

	/**
	 * Get a fallback chain of healthy providers.
	 *
	 * Returns all healthy providers sorted by score, excluding the
	 * preferred provider.
	 *
	 * @since 1.2.5
	 *
	 * @param string $preferredSlug Provider to exclude (already tried).
	 * @param int    $threshold     Health threshold.
	 * @return array<array{slug: string, score: int}> Sorted fallback list.
	 */
	public function getFallbackChain( string $preferredSlug, int $threshold = self::DEFAULT_THRESHOLD ): array {
		$providers = $this->getAllProviders();
		$healthy   = array();

		foreach ( $providers as $slug => $score ) {
			if ( $slug !== $preferredSlug && $score > $threshold ) {
				$healthy[] = array(
					'slug'  => $slug,
					'score' => $score,
				);
			}
		}

		// Sort by score descending.
		usort(
			$healthy,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $healthy;
	}

	/**
	 * Get all known providers and their scores.
	 *
	 * @since 1.2.5
	 *
	 * @return array<string, int> Provider slug → score.
	 */
	public function getAllProviders(): array {
		global $wpdb;

		// Query all transient keys matching our prefix.
		$pattern = $wpdb->esc_like( '_transient_' . self::SCORE_KEY_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		$scores = array();
		foreach ( $rows as $row ) {
			$slug = str_replace( '_transient_' . self::SCORE_KEY_PREFIX, '', $row->option_name );
			if ( '' !== $slug ) {
				$scores[ $slug ] = (int) $row->option_value;
			}
		}

		return $scores;
	}

	/**
	 * Reset the health score for a provider.
	 *
	 * @since 1.2.5
	 *
	 * @param string $providerSlug Provider identifier.
	 * @return void
	 */
	public function resetScore( string $providerSlug ): void {
		delete_transient( self::SCORE_KEY_PREFIX . sanitize_key( $providerSlug ) );
	}
}
