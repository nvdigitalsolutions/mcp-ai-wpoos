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
	 * @return array<string, mixed>
	 */
	public static function from( ContentItem $item ): array {
		$meta = $item->meta;

		$enrollments = $meta['_student_eca_enrollments'] ?? array();

		return array(
			'id'               => $item->id,
			'name'             => $item->title,
			'first_name'       => self::toText( $meta['_student_first_name'] ?? '' ),
			'last_name'        => self::toText( $meta['_student_last_name'] ?? '' ),
			'year_group'       => self::toText( $meta['_student_year_group'] ?? '' ),
			'house'            => self::toText( $meta['_student_house'] ?? '' ),
			'email'            => self::toText( $meta['_student_email'] ?? '' ),
			'enrollment_count' => is_array( $enrollments ) ? count( $enrollments ) : 0,
		);
	}

	/**
	 * Enrolled activity ids, in the order they were added.
	 *
	 * @return array<int, int>
	 */
	public static function enrollmentIds( ContentItem $item ): array {
		$ids = array();

		$enrollments = $item->meta['_student_eca_enrollments'] ?? array();

		if ( ! is_array( $enrollments ) ) {
			return $ids;
		}

		foreach ( $enrollments as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}

		return $ids;
	}

	/**
	 * @param mixed $value Raw meta value.
	 */
	private static function toText( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
