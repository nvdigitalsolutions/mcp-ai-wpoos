<?php
/**
 * Port: persistence for KPI samples.
 *
 * WordPress implements this with options or a custom table; Laravel implements
 * it with Eloquent. The domain neither knows nor cares.
 *
 * Implementations must be **idempotent per (venue, key, at, source_adapter)** —
 * a retried poll must not double-count a counter KPI.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Interfaces;

use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Window;

/**
 * A store for measured KPI samples.
 *
 * @since 0.1.0
 */
interface Interface_WiFi_Kpi_Sink {

	/**
	 * Persist a batch of samples.
	 *
	 * @param array<int, Kpi_Sample> $samples Samples to persist.
	 * @return Result `ok( array{written:int} )` or `fail( <code> )`.
	 */
	public function write( array $samples ): Result;

	/**
	 * Read samples for a venue within a window, ordered by timestamp ascending.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param Window $window   Time window.
	 * @return Result `ok( array<int, Kpi_Sample> )` or `fail( <code> )`.
	 */
	public function read( string $venue_id, Window $window ): Result;
}
