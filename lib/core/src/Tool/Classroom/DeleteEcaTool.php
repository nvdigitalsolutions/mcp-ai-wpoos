<?php

/**
 * Delete or cancel an Extra-Curricular Activity — and unenrol its students.
 *
 * Ported from `WP_MCP_AI_Tool_Delete_ECA`, same slug, parameters, confirmation
 * rule and result shape. Two paths, exactly as WordPress has them: `soft_delete`
 * writes `_eca_status = cancelled` and keeps the record, anything else removes it.
 *
 * Three things are deliberate:
 *
 * - **The confirmation is a refusal, not a warning.** WordPress refuses when the
 *   activity has active enrolments unless `confirm_delete` is true, and names the
 *   count. So does this, with `confirmation_required` and the number in the
 *   message, because a cascade that unenrols twelve students is not something a
 *   caller should discover afterwards.
 *
 * - **Student sides are written before the activity.** If the cascade fails
 *   halfway, the activity still says it has students who no longer say they are
 *   in it — which is recoverable and visible. The reverse order would leave
 *   students enrolled in something that no longer exists. `withdraw_student_eca`
 *   uses the same ordering for the same reason.
 *
 * - **Cancelling clears the roster and the count.** WordPress removes each
 *   student's side but leaves `_eca_enrollments` and `_eca_current_enrollment` on
 *   the cancelled activity, so `list_ecas` reports twelve enrolled on a cancelled
 *   club whose students are gone, and a later enrol refuses on capacity. Two
 *   values that contradict reality are worse than one clear state, so this port
 *   clears both. That is the one behavioural divergence, and it is on the record.
 *
 * `_eca_cancelled_at` is written as `Y-m-d H:i:s` in UTC rather than WordPress's
 * local `current_time( 'mysql' )`; the runtime stores timestamps in UTC
 * everywhere else and a local-time string with no offset is not a timestamp.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class DeleteEcaTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'delete_eca';
    }

    public function getName(): string
    {
        return 'Delete ECA';
    }

    public function getDescription(): string
    {
        return 'Deletes or cancels an Extra-Curricular Activity. soft_delete sets the status to cancelled and keeps the record; otherwise the record is removed. Either way its enrolled students are unenrolled, and a delete with active enrolments requires confirm_delete.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'eca_id' => array(
                    'type' => 'integer',
                    'description' => 'ECA record ID to delete (required).',
                    'minimum' => 1,
                ),
                'soft_delete' => array(
                    'type' => 'boolean',
                    'description' => 'Cancel the activity instead of deleting it: the record stays with status cancelled, and its roster and count are cleared.',
                    'default' => false,
                ),
                'confirm_delete' => array(
                    'type' => 'boolean',
                    'description' => 'Must be true when the activity has active enrolments. Confirms that the enrolled students will be unenrolled.',
                    'default' => false,
                ),
            ),
            'required' => array('eca_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        // WordPress checks `delete_posts` at execute time; the classroom family's
        // less destructive tools use `edit_posts`. A delete keeps the stricter one.
        return 'delete_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'delete_posts');

        $user_id = (int) ($context['user_id'] ?? 0);
        $eca_id  = $this->intParam($arguments, 'eca_id');

        if ($eca_id <= 0) {
            return $this->errors->validationFailed(
                'An ECA id is required.',
                array('eca_id' => array('Give the id of the activity to delete.')),
            );
        }

        $soft    = $this->boolParam($arguments, 'soft_delete');
        $confirm = $this->boolParam($arguments, 'confirm_delete');

        $eca = $this->content->find($eca_id);

        if (!$eca instanceof ContentItem || 'mcp_ai_eca' !== $eca->type) {
            return $this->errors->notFound('There is no ECA with id ' . $eca_id . '.');
        }

        $roster  = Enrollments::map($eca->meta['_eca_enrollments'] ?? array());
        $current = (int) ($eca->meta['_eca_current_enrollment'] ?? 0);

        // The roster is the authority when it exists; the counter is only a
        // fallback for a row written before the roster was maintained.
        $enrolled = array() !== $roster ? count($roster) : max(0, $current);

        if ($enrolled > 0 && !$confirm) {
            return $this->errors->create(
                'confirmation_required',
                sprintf(
                    'This ECA has %d active enrollment(s). Set confirm_delete to true to proceed; the students will be unenrolled.',
                    $enrolled
                ),
                array('eca_id' => $eca_id, 'enrollments' => $enrolled),
            );
        }

        // Student sides first: a half-finished cascade then leaves an activity
        // that still lists students who do not list it, which is visible and
        // recoverable. The reverse would enrol students in a deleted activity.
        $removed = array();

        foreach (array_keys($roster) as $student_id) {
            $student = $this->content->find($student_id);

            if (!$student instanceof ContentItem || 'mcp_ai_student' !== $student->type) {
                continue;
            }

            $map = Enrollments::map($student->meta['_student_eca_enrollments'] ?? array());

            if (!array_key_exists($eca_id, $map)) {
                continue;
            }

            unset($map[$eca_id]);

            try {
                $this->content->update(
                    $student_id,
                    new UpdateContentCommand(
                        userId: $user_id,
                        meta: array('_student_eca_enrollments' => $map),
                    )
                );
            } catch (NotFoundException $e) {
                // The student record vanished between the roster read and this
                // write. There is nothing left to unenrol, so the cascade is
                // still consistent; only the count changes.
                continue;
            } catch (AccessDeniedException $e) {
                return $this->errors->forbidden($e->getMessage());
            } catch (\Throwable $e) {
                return $this->errors->create(
                    'eca_delete_failed',
                    'The cascade stopped after ' . count($removed) . ' student record(s): ' . $e->getMessage(),
                    array('eca_id' => $eca_id, 'students_affected' => count($removed)),
                );
            }

            $removed[] = $student_id;
        }

        if ($soft) {
            try {
                $this->content->update(
                    $eca_id,
                    new UpdateContentCommand(
                        userId: $user_id,
                        meta: array(
                            '_eca_status'             => 'cancelled',
                            '_eca_cancelled_at'       => \gmdate('Y-m-d H:i:s'),
                            '_eca_cancelled_by'       => $user_id,
                            '_eca_enrollments'        => array(),
                            '_eca_current_enrollment' => 0,
                        ),
                    )
                );
            } catch (NotFoundException $e) {
                return $this->errors->notFound($e->getMessage());
            } catch (\Throwable $e) {
                return $this->errors->create('eca_delete_failed', $e->getMessage());
            }

            return $this->success(
                'ECA cancelled; its students were unenrolled.',
                array(
                    'eca_id'            => $eca_id,
                    'name'              => $eca->title,
                    'action'            => 'cancelled',
                    'students_affected' => count($removed),
                )
            );
        }

        try {
            $this->content->delete($eca_id, $user_id);
        } catch (NotFoundException $e) {
            return $this->errors->notFound($e->getMessage());
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errors->create('eca_delete_failed', $e->getMessage());
        }

        return $this->success(
            'ECA deleted; its students were unenrolled.',
            array(
                'eca_id'            => $eca_id,
                'name'              => $eca->title,
                'action'            => 'deleted',
                'students_affected' => count($removed),
            )
        );
    }
}
