<?php

/**
 * Read a task plan with its parsed tasks and progress.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Task_Plan` (Proposal 164 section 164.79).
 * Everything is derived from the stored markdown; nothing here writes.
 *
 * ## Pinned and corrected
 *
 * - **`include_history` is accepted and inert.** The WordPress schema declares
 *   it and the tool never reads it; no edit history is recorded on this
 *   runtime, and inventing an empty `history` key would read as "there were no
 *   edits" rather than "there is no history".
 * - **Progress comes from the markdown, not the stored counters.** A hand-edited
 *   plan is reported as it is, where the counters would be stale. WordPress's
 *   reader does the same, which is why `instantiate_template`'s broken meta was
 *   invisible here and visible in the prompt builder.
 * - **An empty `completed_at` is `null`**, not the empty string WordPress
 *   returns from a missing meta row.
 * - **Timestamps are ISO 8601 UTC**, the core's convention, rather than the
 *   site-local `Y-m-d H:i:s` of a WordPress post object.
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
use Nvoos\Core\Tool\AbstractTool;

class GetTaskPlanTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const PLAN_TYPE = 'mcp_task_plan';

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_task_plan';
    }

    public function getName(): string
    {
        return 'Get Task Plan';
    }

    public function getDescription(): string
    {
        return 'Retrieve a task plan with current completion status. Use this to check progress during autonomous workflows.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'plan_id'         => array(
                    'type'        => 'integer',
                    'description' => 'Task plan ID.',
                ),
                'include_history' => array(
                    'type'        => 'boolean',
                    'description' => 'Accepted for compatibility; no edit history is recorded on this runtime.',
                    'default'     => false,
                ),
            ),
            'required'             => array('plan_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

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

        $parsed = TaskPlanMarkdown::parse($plan->content);
        $meta   = $plan->meta;

        $data = array(
            'plan_id'         => $plan->id,
            'plan_name'       => $plan->title,
            'goal'            => (string) ( $meta['goal'] ?? '' ),
            'task_count'      => $parsed['total'],
            'completed_count' => $parsed['completed'],
            'progress'        => $parsed['progress'],
            'status'          => (string) ( $meta['status'] ?? '' ),
            'tasks'           => $parsed['tasks'],
            'markdown'        => $plan->content,
            'created_at'      => $plan->createdAt->format('c'),
            'updated_at'      => $plan->updatedAt->format('c'),
            'completed_at'    => $this->stamp($meta['completed_at'] ?? null),
        );

        $projectId = (int) ( $meta['project_id'] ?? 0 );

        if ($projectId > 0) {
            $data['project_id'] = $projectId;
        }

        return $this->success(
            sprintf(
                'Task plan #%d "%s" is %.1f%% complete.',
                $plan->id,
                $plan->title,
                $parsed['progress']
            ),
            $data
        );
    }

    /**
     * A non-empty timestamp, or null.
     */
    private function stamp(mixed $value): ?string
    {
        if (! is_scalar($value) || '' === (string) $value) {
            return null;
        }

        return (string) $value;
    }
}
