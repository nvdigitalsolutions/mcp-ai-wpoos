<?php
/**
 * Enrolment meta normaliser.
 *
 * The ECA family stores enrolment on both sides as an **id-keyed map**:
 *
 *     `_eca_student_enrollments`  → [ <student_id> => <enrolment record> ]
 *     `_student_eca_enrollments`  → [ <eca_id>     => <enrolment record> ]
 *
 * where the record carries `enrollment_type`, `enrollment_date`,
 * `payment_status`, `amount_due`, `notes` and `enrolled_by`. Every WordPress
 * consumer relies on that shape — `isset( $enrollments[ $id ] )` in
 * `check_eca_conflicts` and `get_eca_timetable`, `foreach ( … as $id => $record )`
 * in `manage_eca_waitlist` and `get_student`.
 *
 * Hand-written data (seeders, REST payloads, older mirrors) sometimes uses a
 * plain list of ids instead. Reading that as a map yields ids of 0 and 1 and
 * enrolment records of `12` and `15`, which silently produces an empty or wrong
 * answer rather than an error — so every reader goes through here.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

/**
 * Normalises enrolment meta to `[ id => record ]` regardless of how it was written.
 *
 * @since 1.4.0
 */
final class Enrollments {

	/**
	 * @param mixed $raw Raw meta value.
	 * @return array<int, array<string, mixed>> Id-keyed enrolment records.
	 */
	public static function map( mixed $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		if ( array_is_list( $raw ) ) {
			return self::fromList( $raw );
		}

		$map = array();

		foreach ( $raw as $key => $record ) {
			if ( ! is_numeric( $key ) || (int) $key <= 0 ) {
				continue;
			}

			$map[ (int) $key ] = is_array( $record ) ? $record : array();
		}

		return $map;
	}

	/**
	 * Enrolled ids, in insertion order.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return array<int, int>
	 */
	public static function ids( mixed $raw ): array {
		return array_keys( self::map( $raw ) );
	}

	/**
	 * Count of enrolment entries.
	 *
	 * @param mixed $raw Raw meta value.
	 */
	public static function count( mixed $raw ): int {
		return count( self::map( $raw ) );
	}

	/**
	 * @param array<mixed, mixed> $list Sequential list of ids or records.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fromList( array $list ): array {
		$map = array();

		foreach ( $list as $entry ) {
			if ( is_array( $entry ) ) {
				$id = self::idFrom( $entry );

				if ( $id > 0 ) {
					$map[ $id ] = $entry;
				}

				continue;
			}

			if ( is_numeric( $entry ) && (int) $entry > 0 ) {
				$map[ (int) $entry ] = array();
			}
		}

		return $map;
	}

	/**
	 * Pull the counterpart id out of a record-shaped list entry.
	 *
	 * @param array<string, mixed> $entry Enrolment record.
	 */
	private static function idFrom( array $entry ): int {
		foreach ( array( 'student_id', 'eca_id', 'id' ) as $key ) {
			if ( isset( $entry[ $key ] ) && is_numeric( $entry[ $key ] ) && (int) $entry[ $key ] > 0 ) {
				return (int) $entry[ $key ];
			}
		}

		return 0;
	}
}
