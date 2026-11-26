<?php

/**
 * Create or update a reusable task-plan template.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Create_Template` (Proposal 164 section 164.79).
 * A template is a `mcp_task_template` content item: title, markdown body with
 * `{{placeholders}}`, and the metadata `instantiate_template` reads back.
 *
 * ## Corrected
 *
 * - **The category vocabulary is one list.** WordPress's validator knew six
 *   categories; its own seeded library declares fourteen, so eight templates
 *   failed the call that created them. {@see TaskTemplateCategory} carries the
 *   union, and create, list and seed all read it.
 * - **The caller must be allowed to edit a template it updates**, which the
 *   content store enforces rather than this tool remembering to check.
 * - **`storage_type` reports `content_store`.** WordPress answered `cct` or
 *   `cpt` because it had two backends; there is one here, and claiming `cpt`
 *   would describe a WordPress post type that does not exist on this runtime.
 *
 * ## Pinned
 *
 * - **A new template is a draft.** The seeder publishes its library; a
 *   hand-created template stays a draft until someone publishes it, exactly as
 *   WordPress creates `post_status: draft`.
 * - **`status` is the item's status, not a second meta row.** WordPress's
 *   seeder wrote both `post_status` and a `status` meta field, and only the
 *   post status was ever read back. The duplicate is dropped.
 * - **`success_rate` and `avg_completion_time` start at zero and no tool
 *   updates them.** They are kept because the listing reports them.
 * - **`version` defaults only when absent.** An explicit empty string is kept,
 *   as WordPress keeps it.
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
use Nvoos\Core\Domain\ValueObject\TaskTemplateCategory;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateTemplateTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const TEMPLATE_TYPE = 'mcp_task_template';

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_template';
    }

    public function getName(): string
    {
        return 'Create Template';
    }

    public function getDescription(): string
    {
        return 'Create a new template or update an existing template. If template_id is provided, updates the existing template instead of creating a new one. Creates a reusable task plan template with {{placeholders}}, default configuration and usage tracking.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'template_id'       => array(
                    'type'        => 'integer',
                    'description' => 'Optional template ID. If provided, updates the existing template instead of creating a new one.',
                ),
                'template_name'     => array(
                    'type'        => 'string',
                    'description' => 'Name of the template.',
                ),
                'description'       => array(
                    'type'        => 'string',
                    'description' => 'Description of what this template is for and when to use it.',
                ),
                'category'          => array(
                    'type'        => 'string',
                    'enum'        => TaskTemplateCategory::ALL,
                    'description' => 'Template category (default: custom).',
                ),
                'markdown_template' => array(
                    'type'        => 'string',
                    'description' => 'Markdown template content with {{placeholders}}. Use GFM checkbox format for tasks.',
                ),
                'default_config'    => array(
                    'type'        => 'object',
                    'description' => 'Default configuration values merged into a plan at instantiation.',
                ),
                'tags'              => array(
                    'type'        => 'array',
                    'description' => 'Tags for template organisation.',
                    'items'       => array('type' => 'string'),
                ),
                'version'           => array(
                    'type'        => 'string',
                    'description' => 'Template version (e.g., 1.0.0).',
                ),
            ),
            'required'             => array('template_name', 'description', 'category', 'markdown_template'),
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

        $name     = $this->stringParam($arguments, 'template_name');
        $body     = isset($arguments['description']) && is_scalar($arguments['description'])
            ? Text::multiline((string) $arguments['description'])
            : '';
        $markdown = isset($arguments['markdown_template']) && is_scalar($arguments['markdown_template'])
            ? (string) $arguments['markdown_template']
            : '';
        $category = isset($arguments['category']) && is_scalar($arguments['category'])
            ? Text::singleLine((string) $arguments['category'])
            : TaskTemplateCategory::DEFAULT;
        $version  = array_key_exists('version', $arguments) && is_scalar($arguments['version'])
            ? Text::singleLine((string) $arguments['version'])
            : '1.0.0';

        $errors = array();

        if ('' === $name) {
            $errors['template_name'] = array('A template name is required.');
        }

        if ('' === $body) {
            $errors['description'] = array('A description is required.');
        }

        if ('' === trim($markdown)) {
            $errors['markdown_template'] = array('A markdown template is required.');
        }

        if (! TaskTemplateCategory::isValid($category)) {
            $errors['category'] = array(
                sprintf('Category must be one of: %s.', implode(', ', TaskTemplateCategory::ALL)),
            );
        }

        if (array() !== $errors) {
            return $this->errors->validationFailed('The template cannot be saved.', $errors);
        }

        $config = $this->normaliseConfig($arguments['default_config'] ?? array());
        $tags   = $this->normaliseTags($arguments['tags'] ?? array());
        $id     = abs($this->intParam($arguments, 'template_id', 0));
        $isUpdate = $id > 0;

        if ($isUpdate) {
            $existing = $this->content->find($id);

            if (null === $existing || self::TEMPLATE_TYPE !== $existing->type) {
                return $this->errors->create('template_not_found', 'Template not found.');
            }

            try {
                $template = $this->content->update(
                    $id,
                    new UpdateContentCommand(
                        userId: $userId,
                        title: $name,
                        content: $markdown,
                        excerpt: $body,
                        meta: array(
                            'category'       => $category,
                            'default_config' => $config,
                            'tags'           => $tags,
                            'version'        => $version,
                        ),
                    )
                );
            } catch (AccessDeniedException $error) {
                return $this->errors->forbidden('You do not have permission to update this template.');
            } catch (NotFoundException $error) {
                return $this->errors->create('template_not_found', 'Template not found.');
            } catch (\Throwable $error) {
                return $this->errors->create('template_update_failed', $error->getMessage());
            }
        } else {
            try {
                $template = $this->content->create(
                    new CreateContentCommand(
                        title: $name,
                        type: self::TEMPLATE_TYPE,
                        authorId: $userId,
                        status: 'draft',
                        content: $markdown,
                        excerpt: $body,
                        meta: array(
                            'category'            => $category,
                            'default_config'      => $config,
                            'tags'                => $tags,
                            'version'             => $version,
                            'usage_count'         => 0,
                            'success_rate'        => 0.0,
                            'avg_completion_time' => 0,
                        ),
                    )
                );
            } catch (AccessDeniedException $error) {
                return $this->errors->forbidden($error->getMessage());
            } catch (\Throwable $error) {
                return $this->errors->create('template_create_failed', $error->getMessage());
            }
        }

        return $this->success(
            sprintf(
                'Template %s successfully. Use this template_id to instantiate task plans.',
                $isUpdate ? 'updated' : 'created'
            ),
            array(
                'template_id'   => $template->id,
                'template_name' => $template->title,
                'category'      => $category,
                'version'       => $version,
                'placeholders'  => TaskPlanMarkdown::placeholders($markdown),
                'storage_type'  => 'content_store',
                'updated'       => $isUpdate,
            )
        );
    }

    /**
     * Scalar default configuration, with keys reduced to safe identifiers.
     *
     * @return array<string, string|int|float|bool>
     */
    private function normaliseConfig(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $config = array();

        foreach ($raw as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $clean = Text::sanitizeKey((string) $key);

            if ('' === $clean) {
                continue;
            }

            $config[ $clean ] = $value;
        }

        return $config;
    }

    /**
     * Non-empty, unique tags, in first-appearance order.
     *
     * @return list<string>
     */
    private function normaliseTags(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $tags = array();

        foreach ($raw as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }

            $clean = Text::singleLine((string) $tag);

            if ('' !== $clean) {
                $tags[] = $clean;
            }
        }

        return array_values(array_unique($tags));
    }
}
