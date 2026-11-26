<?php
/**
 * Port: the current time.
 *
 * Injected rather than called directly so that window boundaries and session
 * expiry are testable without sleeping, and so a Laravel host can supply its own
 * clock. Direct `time()` calls in service code are a purity-gate concern and a
 * testability problem.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Interfaces;

/**
 * A source of the current time.
 *
 * @since 0.1.0
 */
interface Interface_WiFi_Clock {

	/**
	 * Current time as UTC epoch seconds.
	 *
	 * @return int
	 */
	public function now(): int;
}
