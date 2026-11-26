<?php

/**
 * Create or replace a markdown task plan.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Task_Plan` (Proposal 164 section 164.79).
 * A plan is a `mcp_task_plan` content item whose body is a checkbox list; this
 * tool is the only writer of the heading and goal, and the plan trio reads the
 * format through {@see TaskPlanMarkdown}.
 *
 * ## Pinned and corrected
 *
 * - **Passing `task_plan_id` updates instead of creating.** The WordPress tool
 *   does the same, and both refuse a plan the caller may not edit — here the
 *   content store enforces it, so the check cannot be forgotten.
 * - **An update regenerates the task list unchecked**, because the caller
 *   supplies the whole list. WordPress then left `completed_count` and
 *   `progress` at their previous values while the markdown said 0%; the port
 *   resets both so the stored counters agree with the stored body. `status` is
 *   left alone, as WordPress leaves it.
 * - **Task text is collapsed to one line.** WordPress interpolated it raw, so a
 *   task containing `\n- [ ] …` injected extra checkboxes into the plan. A task
 *   is one line; the sanitiser makes that true.
 * - **An invalid priority is refused by task index** rather than rendered as
 *   `(Priority: Urgent)` and then not parsed back as a priority. The schema
 *   already declares the three values.
 * - **The `Created:` stamp is UTC**, not site-local, matching the rest of the
 *   core's timestamps.
 * - **One store, not two.** WordPress branches between a JetEngine CCT and the
 *   CPT; the port writes the content store and reports where it wrote.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateTaskPlanTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const PLAN_TYPE = 'mcp_task_plan';

    /** The priorities the format parses back. */
    private const PRIORITIES = array('high', 'medium', 'low');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_task_plan';
    }

    public function getName(): string
    {
        return 'Create Task Plan';
    }

    public function getDescription(): string
    {
        return 'Create a new task plan or update an existing task plan. If task_plan_id is provided, updates the existing task plan instead of creating a new one. Creates a markdown-based task plan with checkboxes for autonomous orchestration.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'task_plan_id' => array(
                    'type'        => 'integer',
                    'description' => 'Optional task plan ID. If provided, updates the existing task plan instead of creating a new one.',
                ),
                'plan_name'    => array(
                    'type'        => 'string',
                    'description' => 'Name of the task plan (e.g., "Market Research Q1 2026").',
                ),
                'goal'         => array(
                    'type'        => 'string',
                    'description' => 'Overall objective of the task plan.',
                ),
                'tasks'        => array(
                    'type'        => 'array',
                    'description' => 'List of tasks with priorities.',
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
                                'description' => 'Task priority level.',
                            ),
                        ),
                        'required'   => array('text'),
                    ),
                ),
                'project_id'   => array(
                    'type'        => 'integer',
                    'description' => 'Optional project ID to link this task plan to.',
                ),
                'template_id'  => array(
                    'type'        => 'integer',
                    'description' => 'Optional template ID if the plan came from a template.',
                ),
            ),
            'required'             => array('plan_name', 'goal', 'tasks'),
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

        $planName = $this->stringParam($arguments, 'plan_name');
        $goal     = isset($arguments['goal']) && is_scalar($arguments['goal'])
            ? Text::multiline((string) $arguments['goal'])
            : '';

        $errors = array();

        if ('' === $planName) {
            $errors['plan_name'] = array('A plan name is required.');
        }

        if ('' === $goal) {
            $errors['goal'] = array('A goal is required.');
        }

        $tasks = $this->normaliseTasks($arguments['tasks'] ?? array(), $errors);

        if (array() === $tasks && ! isset($errors['tasks.0'])) {
            $errors['tasks'] = array('At least one task is required.');
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The task plan cannot be saved.', $errors);
        }

        $planId     = abs($this->intParam($arguments, 'task_plan_id', 0));
        $projectId  = abs($this->intParam($arguments, 'project_id', 0));
        $templateId = abs($this->intParam($arguments, 'template_id', 0));

        $markdown  = TaskPlanMarkdown::render($planName, $goal, $tasks);
        $taskCount = count($tasks);
        $isUpdate  = $planId > 0;

        if ($isUpdate) {
            $existing = $this->content->find($planId);

            if (null === $existing || self::PLAN_TYPE !== $existing->type) {
                return $this->errors->create(
                    'task_plan_not_found',
                    'Task plan not found.'
                );
            }

            try {
                $plan = $this->content->update(
                    $planId,
                    new UpdateContentCommand(
                        userId: $userId,
                        title: $planName,
                        content: $markdown,
                        meta: array(
                            'goal'            => $goal,
                            'task_count'      => $taskCount,
                            'completed_count' => 0,
                            'progress'        => 0.0,
                            'project_id'      => $projectId,
                            'template_id'     => $templateId,
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
        } else {
            try {
                $plan = $this->content->create(
                    new CreateContentCommand(
                        title: $planName,
                        type: self::PLAN_TYPE,
                        authorId: $userId,
                        status: 'publish',
                        content: $markdown,
                        meta: array(
                            'goal'            => $goal,
                            'task_count'      => $taskCount,
                            'completed_count' => 0,
                            'progress'        => 0.0,
                            'status'          => 'draft',
                            'project_id'      => $projectId,
                            'template_id'     => $templateId,
                            'completed_at'    => null,
                        ),
                    )
                );
            } catch (AccessDeniedException $error) {
                return $this->errors->forbidden($error->getMessage());
            } catch (\Throwable $error) {
                return $this->errors->create('task_plan_create_failed', $error->getMessage());
            }
        }

        return $this->success(
            sprintf(
                '%s task plan "%s" with %d tasks (ID: %d)',
                $isUpdate ? 'Updated' : 'Created',
                $planName,
                $taskCount,
                $plan->id
            ),
            array(
                'plan_id'         => $plan->id,
                'plan_name'       => $plan->title,
                'task_count'      => $taskCount,
                'completed_count' => 0,
                'progress'        => 0.0,
                'markdown'        => $markdown,
                'updated'         => $isUpdate,
                'message'         => sprintf(
                    '%s task plan "%s" with %d tasks (ID: %d)',
                    $isUpdate ? 'Updated' : 'Created',
                    $planName,
                    $taskCount,
                    $plan->id
                ),
            )
        );
    }

    /**
     * Validate and normalise the task list.
     *
     * @param mixed                       $raw    Raw arguments.
     * @param array<string, string[]>     $errors Error map, extended in place.
     *
     * @return list<array{text: string, priority: string}>
     */
    private function normaliseTasks(mixed $raw, array &$errors): array
    {
        if (! is_array($raw)) {
            $errors['tasks'] = array('Tasks must be a list.');

            return array();
        }

        $tasks = array();
        $index = 0;

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                $errors[ 'tasks.' . $index ] = array('Each task must be an object.');
                ++$index;
                continue;
            }

            $text = isset($entry['text']) && is_scalar($entry['text'])
                ? Text::singleLine((string) $entry['text'])
                : '';

            if ('' === $text) {
                $errors[ 'tasks.' . $index ] = array('Task text is required.');
                ++$index;
                continue;
            }

            $priority = isset($entry['priority']) && is_scalar($entry['priority'])
                ? strtolower(trim((string) $entry['priority']))
                : '';

            if ('' !== $priority && ! in_array($priority, self::PRIORITIES, true)) {
                $errors[ 'tasks.' . $index ] = array('Priority must be high, medium or low.');
                ++$index;
                continue;
            }

            $tasks[] = array('text' => $text, 'priority' => $priority);
            ++$index;
        }

        return $tasks;
    }
}
