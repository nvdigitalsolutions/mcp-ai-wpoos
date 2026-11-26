<?php

/**
 * Matching an activity against a student, teacher, venue or year group.
 *
 * The timetable and the conflict check ask the same questions, and the answers
 * are less obvious than they look: `_eca_teachers` and `_eca_year_groups` may be
 * an array, a comma-separated string or a single name, depending on whether the
 * row was written by the family, by WordPress' importer or by hand. One reader
 * for both tools means a teacher view and a teacher conflict check cannot
 * disagree about who teaches what.
 *
 * Enrolment is read through `EcaRow::enrollments()` — the live
 * `_eca_student_enrollments` key, with the legacy `_eca_enrollments` as a
 * fallback — because a roster read from the wrong key silently reports nobody,
 * which is the defect \u00a7164.57 records.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Entity\ContentItem;

final class EcaFilter
{
    /**
     * A name against a field that may be a list, a CSV string or one value.
     */
    public static function matchesList(mixed $value, string $needle): bool
    {
        $needle = \trim($needle);

        if ('' === $needle) {
            return false;
        }

        foreach (self::flatten($value) as $candidate) {
            if (0 === \strcasecmp($candidate, $needle) || false !== \stripos($candidate, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A venue against the activity's `_eca_venue`, exactly (case-insensitively).
     */
    public static function venue(ContentItem $eca, string $venue): bool
    {
        return 0 === \strcasecmp(\trim((string) ($eca->meta['_eca_venue'] ?? '')), \trim($venue));
    }

    /**
     * Whether a student is on the activity's roster.
     */
    public static function enrolled(ContentItem $eca, int $student_id): bool
    {
        if ($student_id <= 0) {
            return false;
        }

        if (\array_key_exists($student_id, EcaRow::enrollments($eca))) {
            return true;
        }

        return \array_key_exists($student_id, Enrollments::map($eca->meta['_eca_enrollments'] ?? array()));
    }

    /**
     * @return list<string>
     */
    private static function flatten(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \str_contains($value, ',') ? \explode(',', $value) : array($value);
        } elseif (\is_scalar($value)) {
            $value = array((string) $value);
        }

        if (!\is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $out[] = \trim((string) $item);
            }
        }

        return $out;
    }
}
