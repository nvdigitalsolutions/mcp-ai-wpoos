<?php

/**
 * Mark tasks complete or append tasks to a plan.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Update_Task_Plan` (Proposal 164 section 164.79).
 * The markdown is the source of truth; the counters are recomputed from it on
 * every write.
 *
 * ## Corrected
 *
 * - **Appending tasks destroyed the plan in WordPress.** Its `new_tasks` path
 *   passed a pattern whose non-capturing group had been mangled to `( ? ( :`,
 *   so `preg_replace` failed, returned null, and the stored markdown was
 *   replaced with nothing. The port splices lines after the last task (see
 *   {@see TaskPlanMarkdown::appendTasks()}), which also avoids `$1` replacement
 *   semantics in caller text.
 * - **The caller must be allowed to edit the plan.** WordPress's update tool
 *   performed no authorisation check at all, so any user with `edit_posts`
 *   could mark tasks on anyone's plan. The content store enforces the edit
 *   right, which is the same rule `create_task_plan` applies when it updates.
 * - **`completed_at` is maintained.** The WordPress CPT path never wrote it
 *   while its CCT path did, so a completed plan reported an empty timestamp.
 *   The port stamps it at 100% and clears it below, following the CCT.
 *
 * ## Pinned
 *
 * - **An out-of-range `task_index` changes nothing**, silently. The response's
 *   counts are the honest signal that the update did not apply.
 * - **`update_task_plan` does not rewrite the `## Status` section** of the
 *   markdown; the counters live in metadata. The `Progress:` line written at
 *   creation stays at 0% unless a caller edits the body.
 * - **`new_tasks` are appended, not merged by text**, so the same task can
 *   appear twice if the caller sends it twice.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class UpdateTaskPlanTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const PLAN_TYPE = 'mcp_task_plan';

    private const PRIORITIES = array('high', 'medium', 'low');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'update_task_plan';
    }

    public function getName(): string
    {
        return 'Update Task Plan';
    }

    public function getDescription(): string
    {
        return 'Update task completion status in a task plan. Use this after completing tasks to track progress in autonomous workflows.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'plan_id'      => array(
                    'type'        => 'integer',
                    'description' => 'Task plan ID.',
                ),
                'task_updates' => array(
                    'type'        => 'array',
                    'description' => 'Task status updates by zero-based index.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'task_index' => array(
                                'type'        => 'integer',
                                'description' => 'Zero-based index of the task in the list.',
                            ),
                            'completed'  => array(
                                'type'        => 'boolean',
                                'description' => 'Whether the task is completed.',
                            ),
                        ),
                        'required'   => array('task_index', 'completed'),
                    ),
                ),
                'new_tasks'    => array(
                    'type'        => 'array',
                    'description' => 'Tasks to append to the plan.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'text'     => array(
                                'type'        => 'string',
                                'description' => 'Task description.',
                            ),
                            'priority' => array(
                                'type'        => 'string',
                                'enum'        => self::PRIORITIES,
                                'description' => 'Task priority.',
                            ),
                        ),
                        'required'   => array('text'),
                    ),
                ),
            ),
            'required'             => array('plan_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $userId = (int) ( $context['user_id'] ?? 0 );
        $planId = abs($this->intParam($arguments, 'plan_id', 0));

        if (0 === $planId) {
            return $this->errors->create('missing_plan_id', 'Missing required argument: plan_id.');
        }

        $plan = $this->content->find($planId);

        if (null === $plan || self::PLAN_TYPE !== $plan->type) {
            return $this->errors->create(
                'task_plan_not_found',
                sprintf('Task plan #%d not found', $planId)
            );
        }

        $errors = array();
        $newTasks = $this->normaliseNewTasks($arguments['new_tasks'] ?? array(), $errors);

        if (array() !== $errors) {
            return $this->errors->validationFailed('The task plan cannot be updated.', $errors);
        }

        $markdown = $plan->content;

        $updates = isset($arguments['task_updates']) && is_array($arguments['task_updates'])
            ? $arguments['task_updates']
            : array();

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $index     = isset($update['task_index']) ? (int) $update['task_index'] : -1;
            $completed = ! empty($update['completed']);

            $markdown = TaskPlanMarkdown::setTaskStatus($markdown, $index, $completed);
        }

        if (array() !== $newTasks) {
            $markdown = TaskPlanMarkdown::appendTasks($markdown, $newTasks);
        }

        $parsed   = TaskPlanMarkdown::parse($markdown);
        $status   = $parsed['progress'] >= 100 ? 'completed' : 'active';
        $stamp    = $parsed['progress'] >= 100 ? gmdate('c') : null;

        try {
            $this->content->update(
                $planId,
                new UpdateContentCommand(
                    userId: $userId,
                    content: $markdown,
                    meta: array(
                        'task_count'      => $parsed['total'],
                        'completed_count' => $parsed['completed'],
                        'progress'        => $parsed['progress'],
                        'status'          => $status,
                        'completed_at'    => $stamp,
                    ),
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden('You do not have permission to update this task plan.');
        } catch (NotFoundException $error) {
            return $this->errors->create('task_plan_not_found', 'Task plan not found.');
        } catch (\Throwable $error) {
            return $this->errors->create('task_plan_update_failed', $error->getMessage());
        }

        return $this->success(
            sprintf(
                'Updated task plan #%d: %d of %d tasks complete (%.1f%%)',
                $planId,
                $parsed['completed'],
                $parsed['total'],
                $parsed['progress']
            ),
            array(
                'plan_id'          => $planId,
                'task_count'       => $parsed['total'],
                'completed_count'  => $parsed['completed'],
                'progress'         => $parsed['progress'],
                'remaining_tasks'  => $parsed['total'] - $parsed['completed'],
                'updated_markdown' => $markdown,
                'message'          => sprintf(
                    'Updated task plan #%d: %d of %d tasks complete (%.1f%%)',
                    $planId,
                    $parsed['completed'],
                    $parsed['total'],
                    $parsed['progress']
                ),
            )
        );
    }

    /**
     * Validate the tasks to append.
     *
     * @param mixed                   $raw    Raw arguments.
     * @param array<string, string[]> $errors Error map, extended in place.
     *
     * @return list<array{text: string, priority: string}>
     */
    private function normaliseNewTasks(mixed $raw, array &$errors): array
    {
        if (! is_array($raw)) {
            $errors['new_tasks'] = array('New tasks must be a list.');

            return array();
        }

        $tasks = array();
        $index = 0;

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                $errors[ 'new_tasks.' . $index ] = array('Each task must be an object.');
                ++$index;
                continue;
            }

            $text = isset($entry['text']) && is_scalar($entry['text'])
                ? Text::singleLine((string) $entry['text'])
                : '';

            if ('' === $text) {
                $errors[ 'new_tasks.' . $index ] = array('Task text is required.');
                ++$index;
                continue;
            }

            $priority = isset($entry['priority']) && is_scalar($entry['priority'])
                ? strtolower(trim((string) $entry['priority']))
                : '';

            if ('' !== $priority && ! in_array($priority, self::PRIORITIES, true)) {
                $errors[ 'new_tasks.' . $index ] = array('Priority must be high, medium or low.');
                ++$index;
                continue;
            }

            $tasks[] = array('text' => $text, 'priority' => $priority);
            ++$index;
        }

        return $tasks;
    }
}
