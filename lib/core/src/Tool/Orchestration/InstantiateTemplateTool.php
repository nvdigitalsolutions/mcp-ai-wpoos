<?php

/**
 * Create a task plan from a template by filling its placeholders.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Instantiate_Template` (Proposal 164 section
 * 164.79). The template's markdown becomes the plan body, every `{{name}}` must
 * be supplied, and the merged configuration is reported back.
 *
 * ## Corrected
 *
 * - **The plan is written in the same schema the plan tools read.** WordPress
 *   wrote `status`, `task_count`, `project_id` and friends as *unprefixed* meta
 *   while every reader used `_`-prefixed keys, and it never wrote `goal` at
 *   all. An instantiated plan therefore reported an empty goal and an empty
 *   status, and only the markdown parse kept its progress honest. Here the plan
 *   carries the canonical metadata, so `get_task_plan` and the prompt builder
 *   see what the creator meant.
 * - **The usage counter is only incremented when the caller may edit the
 *   template.** WordPress incremented it unconditionally, bypassing the edit
 *   rules that `create_template` itself enforces. Instantiating someone else's
 *   published template still creates the plan; the counter is a statistic, not
 *   part of the instantiation contract, and it is reported as it stands.
 *
 * ## Pinned
 *
 * - **The plan title is the extracted goal**, as WordPress sets `post_title`.
 *   The goal is now also stored as `goal`, so the two agree instead of the
 *   title carrying the only copy.
 * - **The merged `config` is returned but not stored on the plan.** WordPress
 *   did the same; `manage_autonomous_session` receives the values from the
 *   caller who has them.
 * - **Every placeholder must be supplied.** A leftover `{{name}}` is a
 *   validation error listing the names, not a plan with a literal placeholder.
 * - **A non-scalar variable is skipped**, so an array cannot become the string
 *   `Array`. The placeholder then remains and the call fails naming it.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class InstantiateTemplateTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const PLAN_TYPE = 'mcp_task_plan';

    private const TEMPLATE_TYPE = 'mcp_task_template';

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'instantiate_template';
    }

    public function getName(): string
    {
        return 'Instantiate Template';
    }

    public function getDescription(): string
    {
        return 'Creates a task plan from a template by replacing placeholders with actual values. This allows reusing proven workflows by just changing the variables.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'template_id'      => array(
                    'type'        => 'integer',
                    'description' => 'ID of the template to instantiate.',
                ),
                'variables'        => array(
                    'type'        => 'object',
                    'description' => 'Key-value pairs to replace template placeholders (e.g., {"topic": "Machine Learning", "count": "10"}).',
                ),
                'config_overrides' => array(
                    'type'        => 'object',
                    'description' => 'Override the template default configuration values.',
                ),
                'project_id'       => array(
                    'type'        => 'integer',
                    'description' => 'Optional project ID to link the task plan to.',
                ),
            ),
            'required'             => array('template_id', 'variables'),
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

        $userId     = (int) ( $context['user_id'] ?? 0 );
        $templateId = abs($this->intParam($arguments, 'template_id', 0));
        $projectId  = abs($this->intParam($arguments, 'project_id', 0));
        $variables  = isset($arguments['variables']) && is_array($arguments['variables'])
            ? $arguments['variables']
            : array();

        if (0 === $templateId) {
            return $this->errors->create('missing_template_id', 'Missing required argument: template_id.');
        }

        $template = $this->content->find($templateId);

        if (null === $template || self::TEMPLATE_TYPE !== $template->type) {
            return $this->errors->create('template_not_found', 'Template not found.');
        }

        $markdown = $template->content;

        foreach ($variables as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $markdown = str_replace('{{' . $key . '}}', (string) $value, $markdown);
        }

        $missing = TaskPlanMarkdown::placeholders($markdown);

        if (array() !== $missing) {
            $fields = array();

            foreach ($missing as $name) {
                $fields[ $name ] = array('This placeholder has no value.');
            }

            return $this->errors->validationFailed(
                'Missing required variables: ' . implode(', ', $missing),
                $fields
            );
        }

        $config = $this->mergeConfig($template->meta['default_config'] ?? array(), $arguments['config_overrides'] ?? array());
        $goal   = $this->extractGoal($markdown, $template->title);
        $count  = TaskPlanMarkdown::countTasks($markdown);

        try {
            $plan = $this->content->create(
                new CreateContentCommand(
                    title: $goal,
                    type: self::PLAN_TYPE,
                    authorId: $userId,
                    status: 'publish',
                    content: $markdown,
                    meta: array(
                        'goal'            => $goal,
                        'task_count'      => $count,
                        'completed_count' => 0,
                        'progress'        => 0.0,
                        'status'          => 'active',
                        'project_id'      => $projectId,
                        'template_id'     => $templateId,
                        'completed_at'    => null,
                    ),
                )
            );
        } catch (\Throwable $error) {
            return $this->errors->create('task_plan_create_failed', $error->getMessage());
        }

        $usage = $this->incrementUsage($template, $userId);

        return $this->success(
            'Task plan created from template successfully. Use this plan_id to start an autonomous session.',
            array(
                'plan_id'       => $plan->id,
                'template_id'   => $templateId,
                'template_name' => $template->title,
                'goal'          => $goal,
                'task_count'    => $count,
                'config'        => $config,
                'storage_type'  => 'content_store',
                'usage_count'   => $usage,
                'message'       => 'Task plan created from template successfully. Use this plan_id to start an autonomous session.',
            )
        );
    }

    /**
     * Merge the template's defaults with the caller's overrides.
     *
     * @return array<string, string|int|float|bool>
     */
    private function mergeConfig(mixed $defaults, mixed $overrides): array
    {
        $config = array();

        foreach (array($defaults, $overrides) as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $key => $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $clean = Text::sanitizeKey((string) $key);

                if ('' !== $clean) {
                    $config[ $clean ] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * The first heading or non-empty line, as WordPress extracts it.
     */
    private function extractGoal(string $markdown, string $templateName): string
    {
        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (str_starts_with($line, '#')) {
                return ltrim($line, '# ');
            }

            return $line;
        }

        return $templateName . ' - Instantiated';
    }

    /**
     * Increment `usage_count` when the caller may edit the template.
     *
     * The plan is already created; a counter failure must not turn a successful
     * instantiation into an error, so the stored value is reported instead.
     */
    private function incrementUsage(ContentItem $template, int $userId): int
    {
        $current = (int) ( $template->meta['usage_count'] ?? 0 );

        if (! $this->content->userCanAccess($template->id, $userId, 'edit')) {
            return $current;
        }

        try {
            $this->content->update(
                $template->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array('usage_count' => $current + 1),
                )
            );
        } catch (\Throwable $error) {
            return $current;
        }

        return $current + 1;
    }
}
