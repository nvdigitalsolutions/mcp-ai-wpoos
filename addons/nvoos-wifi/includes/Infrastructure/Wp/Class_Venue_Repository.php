<?php
/**
 * Venue lookup, backed by an option.
 *
 * Phase 0 keeps venue records in a single non-autoloaded option. Phase 1 moves
 * them into a real store alongside consent and session records once the venue
 * admin surface exists.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Wp;

use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option-backed venue repository.
 *
 * @since 0.1.0
 */
final class Class_Venue_Repository {

	/**
	 * Option holding all venue records, keyed by venue id.
	 */
	const OPTION_NAME = 'nvoos_wifi_venues';

	/**
	 * Find a venue by identifier.
	 *
	 * @param string $venue_id Venue identifier.
	 * @return Result `ok( Venue )` or `fail( 'venue_not_found' | 'invalid_venue' )`.
	 */
	public function find( string $venue_id ): Result {
		$venues = $this->all();
		$key    = trim( $venue_id );

		if ( ! isset( $venues[ $key ] ) || ! is_array( $venues[ $key ] ) ) {
			return Result::fail(
				'venue_not_found',
				sprintf( 'Venue "%s" is not registered on this site.', $key )
			);
		}

		$data         = $venues[ $key ];
		$data['id']   = $key;

		return Venue::from_array( $data );
	}

	/**
	 * All registered venues, hydrated.
	 *
	 * Invalid records are skipped rather than fataling the list.
	 *
	 * @return array<int, Venue>
	 */
	public function all_venues(): array {
		$out = array();

		foreach ( $this->all() as $id => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$data['id'] = (string) $id;
			$venue      = Venue::from_array( $data );

			if ( $venue->is_ok() ) {
				$out[] = $venue->value();
			}
		}

		return $out;
	}

	/**
	 * Raw venue records.
	 *
	 * @return array<string, mixed>
	 */
	private function all(): array {
		$venues = get_option( self::OPTION_NAME, array() );

		return is_array( $venues ) ? $venues : array();
	}
}
