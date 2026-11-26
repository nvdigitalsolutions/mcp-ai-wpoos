<?php

/**
 * Render a fresh per-iteration execution prompt from a plan.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Execution_Prompt` (Proposal 164 section
 * 164.79). The loop runner resets context between iterations and calls this to
 * get a scoped prompt: objective, next task, progress, budget, success criteria
 * and the exit-signal instruction.
 *
 * ## Corrected
 *
 * - **The prompt is not HTML-escaped.** WordPress ran every interpolated value
 *   through `esc_html()`, so a plan name with an apostrophe arrived as
 *   `Client&#039;s plan` and an ampersand as `&amp;`. A prompt is plain text;
 *   the canonical envelope is the layer that escapes for its transport.
 *
 * ## Pinned
 *
 * - **Progress comes from the stored counters, not the markdown.** WordPress
 *   does the same, which makes this the one reader that can disagree with
 *   `get_task_plan` after a hand edit. Recomputing would change what a loop
 *   runner was told mid-session.
 * - **`tasks_parsed` wins over the markdown when present.** The field was a CCT
 *   column and is dead on the CPT path; a host that writes it (JSON list with
 *   `completed` and `text`/`title`) still overrides the parse here, as it did
 *   in WordPress.
 * - **`success_criteria` is read, never written** by any plan tool. A plan
 *   without it gets the three default criteria.
 * - **`prompt_length` is a byte count**, as `strlen()` was.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateExecutionPromptTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'create_execution_prompt';
    }

    public function getName(): string
    {
        return 'Create Execution Prompt';
    }

    public function getDescription(): string
    {
        return 'Generate a structured execution prompt for the current iteration of an autonomous orchestration loop. Includes the plan objective, current task, available tools, remaining budget/iterations, success criteria and EXIT_SIGNAL instructions.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'plan_id'                => array(
                    'type'        => 'integer',
                    'description' => 'Task plan ID to generate the prompt from.',
                ),
                'iteration_number'       => array(
                    'type'        => 'integer',
                    'description' => 'Current iteration number, used in the prompt header (default: 1).',
                    'default'     => 1,
                ),
                'previous_result'        => array(
                    'type'        => 'string',
                    'description' => 'Summary of the previous iteration result, for continuity.',
                ),
                'current_task'           => array(
                    'type'        => 'string',
                    'description' => 'Override the next incomplete task.',
                ),
                'constraints'            => array(
                    'type'        => 'object',
                    'description' => 'Execution constraints to include in the prompt.',
                    'properties'  => array(
                        'remaining_iterations' => array(
                            'type'        => 'integer',
                            'description' => 'Remaining iterations in the session budget.',
                        ),
                        'remaining_tokens'     => array(
                            'type'        => 'integer',
                            'description' => 'Remaining tokens in the session budget.',
                        ),
                        'time_remaining_hours' => array(
                            'type'        => 'number',
                            'description' => 'Hours remaining before session expiry.',
                        ),
                        'max_tool_calls'       => array(
                            'type'        => 'integer',
                            'description' => 'Maximum tool calls allowed for this iteration.',
                        ),
                    ),
                ),
                'assistant_capabilities' => array(
                    'type'        => 'array',
                    'description' => 'Tools or capabilities available to the assistant.',
                    'items'       => array('type' => 'string'),
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
        return array('read-only', 'idempotent', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $planId = abs($this->intParam($arguments, 'plan_id', 0));

        if (0 === $planId) {
            return $this->errors->create('missing_plan_id', 'Missing required argument: plan_id.');
        }

        $plan = $this->content->find($planId);

        if (null === $plan || self::PLAN_TYPE !== $plan->type) {
            return $this->errors->create(
                'plan_not_found',
                sprintf('Task plan #%d not found.', $planId)
            );
        }

        $iteration = abs($this->intParam($arguments, 'iteration_number', 1));
        $previous  = isset($arguments['previous_result']) && is_scalar($arguments['previous_result'])
            ? Text::multiline((string) $arguments['previous_result'])
            : '';
        $override  = $this->stringParam($arguments, 'current_task');
        $meta      = $plan->meta;

        $next = $this->nextIncomplete($meta, $plan->content);

        $prompt = $this->build(
            $plan->title,
            (string) ( $meta['goal'] ?? '' ),
            $override,
            $next,
            (int) ( $meta['completed_count'] ?? 0 ),
            (int) ( $meta['task_count'] ?? 0 ),
            (float) ( $meta['progress'] ?? 0 ),
            (string) ( $meta['status'] ?? '' ),
            $previous,
            $this->capabilities($arguments['assistant_capabilities'] ?? array()),
            $this->constraints($arguments['constraints'] ?? array()),
            (string) ( $meta['success_criteria'] ?? '' ),
            $iteration
        );

        return $this->success(
            sprintf('Generated execution prompt for "%s", iteration %d', $plan->title, $iteration),
            array(
                'plan_id'         => $plan->id,
                'iteration'       => $iteration,
                'prompt_content'  => $prompt,
                'prompt_length'   => strlen($prompt),
                'next_incomplete' => $next,
                'message'         => sprintf(
                    'Generated execution prompt for "%s", iteration %d',
                    $plan->title,
                    $iteration
                ),
            )
        );
    }

    /**
     * @param array<string, mixed>        $meta        Plan metadata.
     * @param array<string, int|float>    $constraints Sanitised constraints.
     * @param list<string>                $capabilities Sanitised capabilities.
     */
    private function build(
        string $planName,
        string $goal,
        string $override,
        ?string $next,
        int $completed,
        int $total,
        float $progress,
        string $status,
        string $previous,
        array $capabilities,
        array $constraints,
        string $criteria,
        int $iteration,
    ): string {
        $markdown  = '# Execution Prompt: ' . $planName . ' — Iteration ' . $iteration . "\n\n";
        $markdown .= "## Objective\n" . $goal . "\n\n";

        $markdown .= "## Current Task\n";

        if ('' !== $override) {
            $markdown .= $override . "\n\n";
        } elseif (null !== $next) {
            $markdown .= $next . "\n\n";
        } else {
            $markdown .= "All tasks are complete. Verify work and emit EXIT_SIGNAL.\n\n";
        }

        $markdown .= "## Progress\n";
        $markdown .= sprintf("Completed: %d / %d tasks (%.1f%%)\n", $completed, $total, $progress);
        $markdown .= 'Status: ' . $status . "\n\n";

        if ('' !== $previous) {
            $markdown .= "## Previous Iteration Result\n" . $previous . "\n\n";
        }

        if (array() !== $capabilities) {
            $markdown .= "## Available Tools\n";

            foreach ($capabilities as $tool) {
                $markdown .= '- ' . $tool . "\n";
            }

            $markdown .= "\n";
        }

        $markdown .= "## Constraints\n";
        $hasConstraints = false;

        if (isset($constraints['remaining_iterations'])) {
            $markdown      .= sprintf("- Remaining iterations: %d\n", (int) $constraints['remaining_iterations']);
            $hasConstraints = true;
        }

        if (isset($constraints['remaining_tokens'])) {
            $markdown      .= sprintf("- Remaining tokens: %s\n", number_format((int) $constraints['remaining_tokens']));
            $hasConstraints = true;
        }

        if (isset($constraints['time_remaining_hours'])) {
            $markdown      .= sprintf("- Time remaining: %.1f hours\n", (float) $constraints['time_remaining_hours']);
            $hasConstraints = true;
        }

        if (isset($constraints['max_tool_calls'])) {
            $markdown      .= sprintf("- Max tool calls this iteration: %d\n", (int) $constraints['max_tool_calls']);
            $hasConstraints = true;
        }

        if (! $hasConstraints) {
            $markdown .= "- No specific constraints. Proceed efficiently.\n";
        }

        $markdown .= "\n## Success Criteria\n";

        if ('' !== $criteria) {
            $markdown .= $criteria . "\n\n";
        } else {
            $markdown .= "- Current task is completed\n";
            $markdown .= "- All work is verified and tested\n";
            $markdown .= "- No errors or blockers remain\n\n";
        }

        $markdown .= "## Exit Signal\n";
        $markdown .= "Report **EXIT_SIGNAL: true** when:\n";
        $markdown .= "1. All tasks in the plan are complete\n";
        $markdown .= "2. All success criteria are met\n";
        $markdown .= "3. All work has been verified\n";
        $markdown .= "4. No errors, warnings, or blockers remain\n\n";
        $markdown .= "Do NOT emit EXIT_SIGNAL: true until ALL conditions above are satisfied.\n";

        return $markdown;
    }

    /**
     * The first incomplete task, from `tasks_parsed` when present.
     *
     * @param array<string, mixed> $meta Plan metadata.
     */
    private function nextIncomplete(array $meta, string $markdown): ?string
    {
        $parsed = $meta['tasks_parsed'] ?? '';

        if (is_string($parsed) && '' !== $parsed) {
            $tasks = json_decode($parsed, true);

            if (is_array($tasks)) {
                foreach ($tasks as $task) {
                    if (! is_array($task) || ! empty($task['completed'])) {
                        continue;
                    }

                    $text = $task['text'] ?? $task['title'] ?? '';

                    if (is_scalar($text) && '' !== (string) $text) {
                        return (string) $text;
                    }

                    return null;
                }
            }
        }

        return TaskPlanMarkdown::nextIncompleteFromMarkdown($markdown);
    }

    /**
     * @return list<string>
     */
    private function capabilities(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $capabilities = array();

        foreach ($raw as $capability) {
            if (! is_scalar($capability)) {
                continue;
            }

            $clean = Text::singleLine((string) $capability);

            if ('' !== $clean) {
                $capabilities[] = $clean;
            }
        }

        return $capabilities;
    }

    /**
     * @return array<string, int|float>
     */
    private function constraints(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $constraints = array();

        foreach (array('remaining_iterations', 'remaining_tokens', 'max_tool_calls') as $key) {
            $value = abs((int) ( $raw[ $key ] ?? 0 ));

            if ($value > 0) {
                $constraints[ $key ] = $value;
            }
        }

        $hours = isset($raw['time_remaining_hours']) && is_numeric($raw['time_remaining_hours'])
            ? (float) $raw['time_remaining_hours']
            : 0.0;

        if ($hours > 0) {
            $constraints['time_remaining_hours'] = $hours;
        }

        return $constraints;
    }
}
