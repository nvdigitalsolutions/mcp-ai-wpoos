<?php
/**
 * Port implementation: KPI sample persistence, backed by an option.
 *
 * Phase 0 uses a non-autoloaded option per venue. That is deliberately the
 * cheapest thing that works: the Phase 0 question is whether the *domain* is
 * portable, not whether the storage is optimal. Phase 2 replaces this with a
 * custom table once the KPI vocabulary is frozen (implementation plan T-2.4).
 *
 * The cap below exists so a long-running site cannot grow an unbounded option.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Wp;

use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Window;
use NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option-backed KPI sample store.
 *
 * @since 0.1.0
 */
final class Class_Wp_Kpi_Sink implements Interface_WiFi_Kpi_Sink {

	/**
	 * Option name prefix. The venue id is hashed so the option name stays short
	 * and inside WordPress's 191-character index limit regardless of venue id.
	 */
	const OPTION_PREFIX = 'nvoos_wifi_kpi_';

	/**
	 * Maximum samples retained per venue.
	 */
	const MAX_SAMPLES = 500;

	/**
	 * {@inheritdoc}
	 *
	 * Writes are idempotent per `(key, at, source_adapter)`, so a retried poll
	 * cannot double-count a counter KPI.
	 */
	public function write( array $samples ): Result {
		if ( array() === $samples ) {
			return Result::ok( array( 'written' => 0 ) );
		}

		$grouped = array();

		foreach ( $samples as $sample ) {
			if ( ! $sample instanceof Kpi_Sample ) {
				return Result::fail(
					'invalid_sample',
					'The KPI sink only accepts Kpi_Sample instances.'
				);
			}

			$grouped[ $sample->venue_id() ][] = $sample;
		}

		$written = 0;

		foreach ( $grouped as $venue_id => $batch ) {
			$stored = $this->load( (string) $venue_id );
			$index  = array();

			foreach ( $stored as $row ) {
				$index[ $this->fingerprint( $row ) ] = true;
			}

			foreach ( $batch as $sample ) {
				$row = $sample->to_array();
				$key = $this->fingerprint( $row );

				if ( isset( $index[ $key ] ) ) {
					continue;
				}

				$stored[]    = $row;
				$index[ $key ] = true;
				++$written;
			}

			if ( count( $stored ) > self::MAX_SAMPLES ) {
				$stored = array_slice( $stored, -self::MAX_SAMPLES );
			}

			// Third argument false = do not autoload; this option is only read on
			// explicit KPI requests.
			update_option( $this->option_name( (string) $venue_id ), $stored, false );
		}

		return Result::ok( array( 'written' => $written ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function read( string $venue_id, Window $window ): Result {
		$stored  = $this->load( $venue_id );
		$samples = array();

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['key'], $row['at'], $row['venue_id'], $row['source_adapter'] ) ) {
				continue;
			}

			$at = (int) $row['at'];

			if ( ! $window->contains( $at ) ) {
				continue;
			}

			$value = array_key_exists( 'value', $row ) ? $row['value'] : null;

			$sample = Kpi_Sample::make(
				(string) $row['key'],
				$value,
				$at,
				(string) $row['venue_id'],
				isset( $row['method'] ) && is_string( $row['method'] ) ? $row['method'] : null,
				(string) $row['source_adapter']
			);

			// Skip rows that no longer validate — e.g. a KPI removed from the
			// schema between releases. Reading must never fatal on old data.
			if ( $sample->is_ok() ) {
				$samples[] = $sample->value();
			}
		}

		usort(
			$samples,
			static function ( Kpi_Sample $a, Kpi_Sample $b ): int {
				if ( $a->at() === $b->at() ) {
					return strcmp( $a->key(), $b->key() );
				}

				return $a->at() <=> $b->at();
			}
		);

		return Result::ok( $samples );
	}

	/**
	 * Load and sanity-check the stored rows for a venue.
	 *
	 * @param string $venue_id Venue identifier.
	 * @return array<int, array<string, mixed>>
	 */
	private function load( string $venue_id ): array {
		$stored = get_option( $this->option_name( $venue_id ), array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Option name for a venue.
	 *
	 * @param string $venue_id Venue identifier.
	 * @return string
	 */
	private function option_name( string $venue_id ): string {
		return self::OPTION_PREFIX . md5( $venue_id );
	}

	/**
	 * Identity fingerprint for idempotency.
	 *
	 * @param array<string, mixed> $row Stored or fresh sample row.
	 * @return string
	 */
	private function fingerprint( array $row ): string {
		return sprintf(
			'%s|%s|%s',
			isset( $row['key'] ) ? (string) $row['key'] : '',
			isset( $row['at'] ) ? (string) $row['at'] : '',
			isset( $row['source_adapter'] ) ? (string) $row['source_adapter'] : ''
		);
	}
}
