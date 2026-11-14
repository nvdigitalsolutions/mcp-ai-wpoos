<?php
/**
 * ECA row assembler — the `mcp_ai_eca` wire shape.
 *
 * Shared by `list_ecas` and the follow-on read tools so one projection means a
 * read and a write cannot disagree about what an activity looks like, the same
 * reason `ProjectRow` and `TaskRow` exist.
 *
 * Field order and derived values mirror
 * `WP_MCP_AI_Tool_List_ECAs::get_eca_data()` exactly. Two values cannot be
 * reproduced without WordPress and are documented divergences (090-d §5):
 *
 * - `url` is always `''` — there are no permalinks outside WordPress.
 * - `is_paid` / `requires_audition` are read as the `'yes'` checkbox encoding
 *   WordPress stores, which is why the schema descriptors declare them as
 *   strings with a `yes`/`no` enum rather than booleans.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Entity\ContentItem;

/**
 * Projects a `ContentItem` of type `mcp_ai_eca` into the tool-facing row.
 *
 * `from()` is the `list_ecas` shape, `detail()` the `get_eca` shape. They
 * differ on purpose: WordPress returns a shorter row from the list tool and a
 * longer one from the detail tool, and the ports keep that distinction.
 *
 * @since 1.4.0
 */
final class EcaRow {

	/**
	 * @return array<string, mixed>
	 */
	public static function from( ContentItem $item ): array {
		$meta = $item->meta;

		$max_students       = self::toInt( $meta['_eca_max_students'] ?? 0 );
		$current_enrollment = self::toInt( $meta['_eca_current_enrollment'] ?? 0 );

		$is_full         = $max_students > 0 && $current_enrollment >= $max_students;
		$available_spots = $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : null;
		$utilization     = $max_students > 0
			? round( ( $current_enrollment / $max_students ) * 100, 1 )
			: null;

		$is_paid = self::toBool( $meta['_eca_is_paid'] ?? '' );

		return array(
			'eca_id'               => $item->id,
			'name'                 => $item->title,
			'eca_code'             => self::toText( $meta['_eca_code'] ?? '' ),
			'description'          => $item->content,
			'type'                 => self::toText( $meta['_eca_type'] ?? '' ),
			'day'                  => self::toText( $meta['_eca_day'] ?? '' ),
			'start_time'           => self::toText( $meta['_eca_start_time'] ?? '' ),
			'end_time'             => self::toText( $meta['_eca_end_time'] ?? '' ),
			'venue'                => self::toText( $meta['_eca_venue'] ?? '' ),
			'year_groups'          => self::toList( $meta['_eca_year_groups'] ?? array() ),
			'teachers'             => self::toList( $meta['_eca_teachers'] ?? array() ),
			'max_students'         => $max_students,
			'current_enrollment'   => $current_enrollment,
			'available_spots'      => $available_spots,
			'is_full'              => $is_full,
			'capacity_utilization' => $utilization,
			'is_paid'              => $is_paid,
			'cost'                 => $is_paid ? self::toFloat( $meta['_eca_cost'] ?? 0 ) : 0,
			'cost_period'          => self::toText( $meta['_eca_cost_period'] ?? '' ),
			'requires_audition'    => self::toBool( $meta['_eca_requires_audition'] ?? '' ),
			'booking_type'         => self::toText( $meta['_eca_booking_type'] ?? '' ),
			'status'               => self::toText( $meta['_eca_status'] ?? '' ),
			'url'                  => '',
		);
	}

	/**
	 * The `get_eca` detail shape.
	 *
	 * Field order mirrors `WP_MCP_AI_Tool_Get_ECA::execute()`. Enrolments are
	 * appended by the tool when the caller asks for them, because resolving
	 * student names needs the store.
	 *
	 * @return array<string, mixed>
	 */
	public static function detail( ContentItem $item ): array {
		$meta = $item->meta;

		$max_students       = self::toInt( $meta['_eca_max_students'] ?? 0 );
		$current_enrollment = self::toInt( $meta['_eca_current_enrollment'] ?? 0 );

		$is_paid = self::toBool( $meta['_eca_is_paid'] ?? '' );

		return array(
			'eca_id'               => $item->id,
			'name'                 => $item->title,
			'description'          => $item->content,
			'eca_code'             => self::toText( $meta['_eca_code'] ?? '' ),
			'type'                 => self::toText( $meta['_eca_type'] ?? '' ),
			'day'                  => self::toText( $meta['_eca_day'] ?? '' ),
			'start_time'           => self::toText( $meta['_eca_start_time'] ?? '' ),
			'end_time'             => self::toText( $meta['_eca_end_time'] ?? '' ),
			'venue'                => self::toText( $meta['_eca_venue'] ?? '' ),
			'year_groups'          => self::toList( $meta['_eca_year_groups'] ?? array() ),
			'max_students'         => $max_students,
			'current_enrollment'   => $current_enrollment,
			'available_spots'      => $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : null,
			'is_full'              => $max_students > 0 && $current_enrollment >= $max_students,
			'enrollment_breakdown' => self::breakdown( $item ),
			'teachers'             => self::toList( $meta['_eca_teachers'] ?? array() ),
			'is_paid'              => $is_paid,
			'cost'                 => $is_paid ? self::toFloat( $meta['_eca_cost'] ?? 0 ) : 0,
			'cost_period'          => self::toText( $meta['_eca_cost_period'] ?? '' ),
			'currency'             => self::toText( $meta['_eca_currency'] ?? '' ),
			'term'                 => self::toText( $meta['_eca_term'] ?? '' ),
			'requires_audition'    => self::toBool( $meta['_eca_requires_audition'] ?? '' ),
			'booking_type'         => self::toText( $meta['_eca_booking_type'] ?? '' ),
			'status'               => self::toText( $meta['_eca_status'] ?? '' ),
			'isams_sync_id'        => self::toText( $meta['_eca_isams_sync_id'] ?? '' ),
			'sessions'             => self::toList( $meta['_eca_sessions'] ?? array() ),
			'url'                  => '',
			'created_at'           => $item->createdAt->format( 'Y-m-d H:i:s' ),
			'modified_at'          => $item->updatedAt->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Enrolment counts by type.
	 *
	 * Reads the id-keyed map through {@see Enrollments}. WordPress builds this
	 * from `_eca_enrollments`, a key nothing in the family writes, so its
	 * breakdown is always zero on real data. The port prefers `_eca_enrollments`
	 * when it exists and otherwise counts the authoritative
	 * `_eca_student_enrollments`, so the numbers it reports are real. That is a
	 * deliberate divergence from a WordPress defect, recorded in 090-d §5.
	 *
	 * @return array<string, int>
	 */
	public static function breakdown( ContentItem $item ): array {
		$counts = array(
			'confirmed'  => 0,
			'waitlist'   => 0,
			'trial'      => 0,
			'preference' => 0,
		);

		$records = Enrollments::map( $item->meta['_eca_enrollments'] ?? array() );

		if ( array() === $records ) {
			$records = Enrollments::map( $item->meta['_eca_student_enrollments'] ?? array() );
		}

		foreach ( $records as $record ) {
			$type = isset( $record['enrollment_type'] ) && is_string( $record['enrollment_type'] )
				? $record['enrollment_type']
				: 'confirmed';

			if ( isset( $counts[ $type ] ) ) {
				++$counts[ $type ];
			}
		}

		return $counts;
	}

	/**
	 * Enrolled student ids, in the order they were added.
	 *
	 * `_eca_student_enrollments` is an id-keyed map on WordPress, but a
	 * hand-written mirror may hold a plain list; {@see Enrollments} accepts both.
	 *
	 * @return array<int, int>
	 */
	public static function enrollmentIds( ContentItem $item ): array {
		return Enrollments::ids( $item->meta['_eca_student_enrollments'] ?? array() );
	}

	/**
	 * Enrolment records keyed by student id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrollments( ContentItem $item ): array {
		return Enrollments::map( $item->meta['_eca_student_enrollments'] ?? array() );
	}

	/**
	 * WordPress checkbox meta is `'yes'` / `'no'`; booleans are tolerated so a
	 * caller that wrote a real boolean through the content port still reads.
	 *
	 * @param mixed $value Raw meta value.
	 */
	private static function toBool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return 'yes' === strtolower( (string) $value ) || '1' === (string) $value;
	}

	/**
	 * @param mixed $value Raw meta value.
	 */
	private static function toInt( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * @param mixed $value Raw meta value.
	 */
	private static function toFloat( mixed $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * @param mixed $value Raw meta value.
	 */
	private static function toText( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * List-valued meta comes back as an array from the store, but a caller may
	 * have written a comma-separated string. Normalise both to a list so the
	 * wire shape does not depend on how the value was written.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array<int, mixed>
	 */
	private static function toList( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}

		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $value ) ),
				static fn ( string $part ): bool => '' !== $part
			)
		);
	}
}
