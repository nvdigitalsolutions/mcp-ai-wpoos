<?php
/**
 * Student row assembler — the `mcp_ai_student` wire shape.
 *
 * Shared by `list_students` and `get_student` so the two cannot disagree about
 * what a student looks like, the same reason `EcaRow` and `ProjectRow` exist.
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
 * Projects a `ContentItem` of type `mcp_ai_student` into the tool-facing row.
 *
 * @since 1.4.0
 */
final class StudentRow {

	/**
	 * The `list_students` shape.
	 *
	 * `enrollment_count` matches the WordPress tool: it counts the reverse link
	 * the enrolment tools maintain, not the activity side.
	 *
	 * @return array<string, mixed>
	 */
	public static function from( ContentItem $item ): array {
		$meta = $item->meta;

		return array(
			'id'               => $item->id,
			'name'             => $item->title,
			'first_name'       => self::toText( $meta['_student_first_name'] ?? '' ),
			'last_name'        => self::toText( $meta['_student_last_name'] ?? '' ),
			'year_group'       => self::toText( $meta['_student_year_group'] ?? '' ),
			'house'            => self::toText( $meta['_student_house'] ?? '' ),
			'email'            => self::toText( $meta['_student_email'] ?? '' ),
			'enrollment_count' => Enrollments::count( $meta['_student_eca_enrollments'] ?? array() ),
		);
	}

	/**
	 * Enrolled activity ids, in the order they were added.
	 *
	 * `_student_eca_enrollments` is an id-keyed map on WordPress; {@see Enrollments}
	 * also accepts a plain list from hand-written data.
	 *
	 * @return array<int, int>
	 */
	public static function enrollmentIds( ContentItem $item ): array {
		return Enrollments::ids( $item->meta['_student_eca_enrollments'] ?? array() );
	}

	/**
	 * Enrolment records keyed by activity id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrollments( ContentItem $item ): array {
		return Enrollments::map( $item->meta['_student_eca_enrollments'] ?? array() );
	}

	/**
	 * @param mixed $value Raw meta value.
	 */
	private static function toText( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
