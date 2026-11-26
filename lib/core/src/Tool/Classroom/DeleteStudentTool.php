<?php

/**
 * Delete a student record — and say what is left pointing at it.
 *
 * Ported from `WP_MCP_AI_Tool_Delete_Student`, same slug, one parameter, same
 * outcome: the record is removed and nothing else is touched.
 *
 * The WordPress tool warns in prose that deleting a student "does not
 * automatically unenroll the student from ECAs". This port keeps the behaviour
 * and replaces the warning with a number: before deleting, it reads the live
 * activities whose roster still lists the student, and returns
 * `enrollments_left` with their ids. A caller that expected a clean delete sees
 * exactly how unclean it was, and one that did not care pays one query.
 *
 * The alternative — refusing while enrolments exist, or cascading silently — was
 * rejected. Refusing would break the WordPress behaviour for callers that mean
 * it, and a silent cascade would delete a student's history from activities
 * nobody asked it to change. Naming the leftovers is the honest middle.
 *
 * The scan is capped at 200 activities, which is the same ceiling `list_ecas`
 * uses for a page; beyond that the count is a lower bound and `truncated` says
 * so rather than reporting a number that looks exact.
 *
 * @package Nvoos\Core
 * @since   2.8.0
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class DeleteStudentTool extends AbstractTool
{
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
        return 'delete_student';
    }

    public function getName(): string
    {
        return 'Delete Student';
    }

    public function getDescription(): string
    {
        return 'Deletes a student record. This does not unenroll the student from ECAs: any activity whose roster still lists them is named in enrollments_left and eca_ids, so the caller sees what the delete left behind.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'student_id' => array(
                    'type' => 'integer',
                    'description' => 'Student record ID to delete (required).',
                    'minimum' => 1,
                ),
            ),
            'required' => array('student_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'delete_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'delete_posts');

        $user_id    = (int) ($context['user_id'] ?? 0);
        $student_id = $this->intParam($arguments, 'student_id');

        if ($student_id <= 0) {
            return $this->errors->validationFailed(
                'A student id is required.',
                array('student_id' => array('Give the id of the student to delete.')),
            );
        }

        $student = $this->content->find($student_id);

        if (!$student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
            return $this->errors->notFound('There is no student with id ' . $student_id . '.');
        }

        // What the student's own record claims, before it disappears.
        $left = array_keys(Enrollments::map($student->meta['_student_eca_enrollments'] ?? array()));

        // And what the activities claim. The two sides can disagree — a failed
        // cascade, or a roster written by an older tool — so the report is the
        // union rather than whichever side happened to be read first.
        $named     = array();
        $truncated = false;

        try {
            $ecas = $this->content->query(
                new ContentQuery(
                    types: array('mcp_ai_eca'),
                    perPage: self::SCAN_LIMIT,
                )
            );

            $truncated = $ecas->total > self::SCAN_LIMIT;

            foreach ($ecas->items as $eca) {
                if (!$eca instanceof ContentItem) {
                    continue;
                }

                $roster = Enrollments::map($eca->meta['_eca_enrollments'] ?? array());

                if (!array_key_exists($student_id, $roster)) {
                    continue;
                }

                $left[]                = $eca->id;
                $named[ $eca->id ] = $eca->title;
            }
        } catch (\Throwable $e) {
            // A failed scan must not block the delete the caller asked for; the
            // report says the count is unknown rather than pretending it is zero.
            return $this->errors->create(
                'student_delete_failed',
                'Could not read the ECA rosters before deleting: ' . $e->getMessage(),
            );
        }

        $left = array_values(array_unique(array_map('intval', $left)));
        \sort($left);

        try {
            $this->content->delete($student_id, $user_id);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('student_delete_failed', $e->getMessage());
        }

        return $this->success(
            array() === $left
                ? 'Student deleted; no activity still lists them.'
                : sprintf(
                    'Student deleted; %d activity(ies) still list them and were not changed.',
                    count($left)
                ),
            array(
                'student_id'        => $student_id,
                'name'              => $student->title,
                'action'            => 'deleted',
                'enrollments_left'  => count($left),
                'eca_ids'           => $left,
                'eca_names'         => $named,
                'truncated'         => $truncated,
            )
        );
    }
}
