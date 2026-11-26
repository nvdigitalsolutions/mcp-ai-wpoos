<?php

/**
 * Would this slot collide with what a student, teacher or venue already has?
 * Proposal 164 §164.58.
 *
 * Ported from `WP_MCP_AI_Tool_Check_ECA_Conflicts`: same eight arguments, same
 * three check types and the same conflict row. The question is asked *before* a
 * write — `create_eca`, `set_eca_schedule` and `update_eca` do not check
 * anything themselves — so this tool is the caller's guard rather than a
 * constraint the store enforces.
 *
 * ### The three checks are not the same match
 *
 * | check | matches |
 * |---|---|
 * | `student` | the activity's roster (live key, legacy fallback) |
 * | `teacher` | `_eca_teachers`, as a list, a CSV string or one name |
 * | `venue` | `_eca_venue`, exactly (case-insensitively) |
 *
 * `exclude_eca_id` exists because the common caller is an edit: "would moving
 * *this* activity to 15:30 clash?" must not count the activity against itself.
 *
 * ### What it does not claim
 *
 * A clean answer means nothing on the roster, the teacher's list or the venue
 * collides **in what this runtime stores**. It cannot see a room booking that
 * lives in another system, and it says so in the description rather than in a
 * footnote.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\AbstractTool;

class CheckEcaConflictsTool extends AbstractTool
{
    private const CHECK_TYPES = array('student', 'teacher', 'venue');

    /** The same page ceiling `list_ecas` uses. */
    private const SCAN_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'check_eca_conflicts';
    }

    public function getName(): string
    {
        return 'Check ECA Conflicts';
    }

    public function getDescription(): string
    {
        return 'Checks whether a proposed day and time collide with the activities a student is enrolled in, a teacher teaches, or a venue hosts. Reads only what this runtime stores: a room booking held in another system is invisible to it.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'check_type' => array(
                    'type' => 'string',
                    'enum' => self::CHECK_TYPES,
                    'description' => 'What to check the proposed slot against.',
                ),
                'student_id' => array(
                    'type' => 'integer',
                    'description' => 'Student record ID. Required for the student check.',
                    'minimum' => 1,
                ),
                'teacher_name' => array(
                    'type' => 'string',
                    'description' => 'Teacher name. Required for the teacher check.',
                ),
                'venue' => array(
                    'type' => 'string',
                    'description' => 'Venue name. Required for the venue check.',
                ),
                'day' => array(
                    'type' => 'string',
                    'enum' => Schedule::DAYS,
                    'description' => 'Day of the proposed slot.',
                ),
                'start_time' => array(
                    'type' => 'string',
                    'description' => 'Proposed start, HH:MM.',
                ),
                'end_time' => array(
                    'type' => 'string',
                    'description' => 'Proposed end, HH:MM.',
                ),
                'exclude_eca_id' => array(
                    'type' => 'integer',
                    'description' => 'Activity to ignore, as when checking a move of the activity itself.',
                    'minimum' => 1,
                ),
            ),
            'required' => array('check_type', 'day', 'start_time', 'end_time'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $check_type = $this->stringParam($arguments, 'check_type');
        $day        = \trim($this->stringParam($arguments, 'day'));

        if (!\in_array($check_type, self::CHECK_TYPES, true)) {
            return $this->errors->validationFailed(
                'Unknown check_type "' . $check_type . '".',
                array('check_type' => array('Use one of ' . \implode(', ', self::CHECK_TYPES) . '.')),
            );
        }

        if (!\in_array($day, Schedule::DAYS, true)) {
            return $this->errors->validationFailed(
                'day must be one of ' . \implode(', ', Schedule::DAYS) . '.',
                array('day' => array('Give the day of the proposed slot.')),
            );
        }

        $start_time = \trim($this->stringParam($arguments, 'start_time'));
        $end_time   = \trim($this->stringParam($arguments, 'end_time'));

        $start = Schedule::minutes($start_time);
        $end   = Schedule::minutes($end_time);

        if (null === $start || null === $end) {
            return $this->errors->validationFailed(
                'start_time and end_time must be times in HH:MM format.',
                array(
                    'start_time' => array('Use HH:MM.'),
                    'end_time'   => array('Use HH:MM.'),
                ),
            );
        }

        if ($end <= $start) {
            return $this->errors->validationFailed(
                'end_time must be after start_time.',
                array('end_time' => array('A slot cannot end before it starts.')),
            );
        }

        $student_id   = $this->intParam($arguments, 'student_id');
        $teacher_name = \trim($this->stringParam($arguments, 'teacher_name'));
        $venue_name   = \trim($this->stringParam($arguments, 'venue'));
        $exclude      = $this->intParam($arguments, 'exclude_eca_id');

        if ('student' === $check_type && $student_id <= 0) {
            return $this->errors->validationFailed(
                'student_id is required for the student check.',
                array('student_id' => array('Give the student to check.')),
            );
        }

        if ('teacher' === $check_type && '' === $teacher_name) {
            return $this->errors->validationFailed(
                'teacher_name is required for the teacher check.',
                array('teacher_name' => array('Give the teacher to check.')),
            );
        }

        if ('venue' === $check_type && '' === $venue_name) {
            return $this->errors->validationFailed(
                'venue is required for the venue check.',
                array('venue' => array('Give the venue to check.')),
            );
        }

        try {
            $page = $this->content->query(
                new ContentQuery(types: array('mcp_ai_eca'), perPage: self::SCAN_LIMIT)
            );
        } catch (\Throwable $e) {
            return $this->errors->create('conflict_check_failed', $e->getMessage());
        }

        $conflicts = array();

        foreach ($page->items as $eca) {
            if (!$eca instanceof ContentItem || $eca->id === $exclude) {
                continue;
            }

            if (!$this->matches($eca, $check_type, $student_id, $teacher_name, $venue_name)) {
                continue;
            }

            if (!Schedule::collides($eca->meta, $day, $start, $end)) {
                continue;
            }

            $conflicts[] = array(
                'eca_id'     => $eca->id,
                'eca_name'   => $eca->title,
                'day'        => (string) ($eca->meta['_eca_day'] ?? ''),
                'start_time' => (string) ($eca->meta['_eca_start_time'] ?? ''),
                'end_time'   => (string) ($eca->meta['_eca_end_time'] ?? ''),
                'venue'      => (string) ($eca->meta['_eca_venue'] ?? ''),
                'teachers'   => $eca->meta['_eca_teachers'] ?? array(),
                'type'       => (string) ($eca->meta['_eca_type'] ?? ''),
            );
        }

        return $this->success(
            array() === $conflicts
                ? 'No conflict: nothing this runtime stores occupies that slot.'
                : \count($conflicts) . ' conflicting activity(ies) on ' . $day . '.',
            array(
                'check_type'     => $check_type,
                'day'            => $day,
                'start_time'     => $start_time,
                'end_time'       => $end_time,
                'has_conflicts'  => array() !== $conflicts,
                'conflicts'      => $conflicts,
                'truncated'      => $page->total > self::SCAN_LIMIT,
            )
        );
    }

    private function matches(ContentItem $eca, string $check_type, int $student_id, string $teacher_name, string $venue_name): bool
    {
        switch ($check_type) {
            case 'student':
                return EcaFilter::enrolled($eca, $student_id);

            case 'teacher':
                return EcaFilter::matchesList($eca->meta['_eca_teachers'] ?? array(), $teacher_name);

            case 'venue':
                return EcaFilter::venue($eca, $venue_name);
        }

        return false;
    }
}
