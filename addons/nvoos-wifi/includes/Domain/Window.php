<?php
/**
 * A half-open time window expressed as UTC epoch seconds.
 *
 * Windows are always UTC. Anything venue-local (a "today" boundary, a DST
 * transition) is resolved by the caller against `Venue::$timezone` before the
 * window is constructed — the domain never guesses a timezone. This is the
 * guard against the timezone-drift failure class (implementation plan §7,
 * row 12).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

/**
 * An immutable `[from, to)` window in epoch seconds.
 *
 * @since 0.1.0
 */
final class Window {

	/**
	 * Inclusive start, epoch seconds.
	 *
	 * @var int
	 */
	private int $from;

	/**
	 * Exclusive end, epoch seconds.
	 *
	 * @var int
	 */
	private int $to;

	/**
	 * Private constructor — use {@see self::make()}.
	 *
	 * @param int $from Inclusive start.
	 * @param int $to   Exclusive end.
	 */
	private function __construct( int $from, int $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	/**
	 * Build a validated window.
	 *
	 * @param int $from Inclusive start, epoch seconds.
	 * @param int $to   Exclusive end, epoch seconds.
	 * @return Result `ok( Window )` or `fail( 'invalid_window' )`.
	 */
	public static function make( int $from, int $to ): Result {
		if ( $from < 0 || $to < 0 ) {
			return Result::fail(
				'invalid_window',
				'Window bounds must be non-negative epoch seconds.'
			);
		}

		if ( $to <= $from ) {
			return Result::fail(
				'invalid_window',
				'Window end must be greater than its start.'
			);
		}

		return Result::ok( new self( $from, $to ) );
	}

	/**
	 * Inclusive start, epoch seconds.
	 *
	 * @return int
	 */
	public function from(): int {
		return $this->from;
	}

	/**
	 * Exclusive end, epoch seconds.
	 *
	 * @return int
	 */
	public function to(): int {
		return $this->to;
	}

	/**
	 * Window length in seconds.
	 *
	 * @return int
	 */
	public function duration(): int {
		return $this->to - $this->from;
	}

	/**
	 * Whether a timestamp falls inside the window (half-open).
	 *
	 * @param int $timestamp Epoch seconds.
	 * @return bool
	 */
	public function contains( int $timestamp ): bool {
		return $timestamp >= $this->from && $timestamp < $this->to;
	}

	/**
	 * Canonical serialisation.
	 *
	 * @return array{from:int, to:int, duration:int}
	 */
	public function to_array(): array {
		return array(
			'from'     => $this->from,
			'to'       => $this->to,
			'duration' => $this->duration(),
		);
	}
}
