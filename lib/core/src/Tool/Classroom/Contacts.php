<?php

/**
 * Where an ECA message goes — one precedence for every sender.
 *
 * Two tools address students: `send_eca_notification` writes to the student and
 * optionally the parent, `send_eca_parent_report` writes to the parent and falls
 * back to the student. Both need the same two keys read the same way, and both
 * must be able to say **which** address was used, because "the report went to
 * the student because no parent address is on file" is a fact the operator
 * needs and a silent fallback is not.
 *
 * The keys are the WordPress ones (`_student_email`, `_student_parent_email`)
 * so a migrated roster keeps working. There is no guardian record in this
 * runtime: a parent address is a field on the student, and a second guardian
 * has nowhere to live yet. That is a data-model gap, not a lookup this class
 * can close, so it is stated rather than papered over.
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

final class Contacts
{
    public const STUDENT_KEY = '_student_email';

    public const PARENT_KEY = '_student_parent_email';

    public const ROLE_STUDENT = 'student';

    public const ROLE_PARENT = 'parent';

    /**
     * The student's own address, if one is set.
     *
     * @return array{email: string, role: string}
     */
    public static function student(ContentItem $student): array
    {
        $email = self::read($student, self::STUDENT_KEY);

        return array(
            'email' => $email,
            'role'  => '' !== $email ? self::ROLE_STUDENT : '',
        );
    }

    /**
     * The parent address, if one is set.
     *
     * @return array{email: string, role: string}
     */
    public static function parent(ContentItem $student): array
    {
        $email = self::read($student, self::PARENT_KEY);

        return array(
            'email' => $email,
            'role'  => '' !== $email ? self::ROLE_PARENT : '',
        );
    }

    /**
     * The address a report goes to: the parent first, then the student.
     *
     * @return array{email: string, role: string}
     */
    public static function forReport(ContentItem $student): array
    {
        $parent = self::parent($student);

        return '' !== $parent['email'] ? $parent : self::student($student);
    }

    private static function read(ContentItem $student, string $key): string
    {
        $raw = $student->meta[ $key ] ?? '';

        if (is_array($raw)) {
            $raw = reset($raw);
        }

        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
