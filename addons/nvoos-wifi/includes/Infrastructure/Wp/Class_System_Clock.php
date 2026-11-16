<?php
/**
 * Port implementation: the system clock.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Wp;

use NvoosWifi\Interfaces\Interface_WiFi_Clock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wall-clock time from the host.
 *
 * `time()` is used rather than `current_time()` on purpose: the domain works
 * exclusively in UTC epoch seconds, and every venue-local conversion happens
 * against `Venue::timezone()`. Mixing in a site-configured offset here is the
 * timezone-drift failure class.
 *
 * @since 0.1.0
 */
final class Class_System_Clock implements Interface_WiFi_Clock {

	/**
	 * {@inheritdoc}
	 */
	public function now(): int {
		return time();
	}
}
