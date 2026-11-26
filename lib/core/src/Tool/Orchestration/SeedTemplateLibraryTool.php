<?php

/**
 * Seed the prebuilt task-template library.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Seed_Template_Library` (Proposal 164 section
 * 164.79). The fourteen templates live in {@see TaskTemplateLibrary}; this tool
 * creates them through {@see CreateTemplateTool} and publishes them, so the
 * seeder and a hand-created template share one validation path.
 *
 * ## Corrected
 *
 * - **`overwrite` updates instead of duplicating.** WordPress skipped the
 *   existence check when `overwrite` was true and then called
 *   `create_template` without an id, so "overwrite" added a second copy of
 *   every template. The port looks up the existing id by name and passes it,
 *   which is what overwriting means.
 * - **The whole library is valid.** Eight of the fourteen categories were not
 *   in `create_template`'s list, so WordPress created six templates and
 *   reported eight errors on an empty library. {@see TaskTemplateCategory}
 *   accepts all fourteen.
 * - **Publishing is the item's status.** WordPress set `post_status` and also
 *   wrote a `status` meta row that nothing read; the duplicate is gone.
 *
 * ## Pinned
 *
 * - **Existence is an exact, case-insensitive title match.** The check reads up
 *   to 200 existing templates once (the store's page ceiling) rather than one
 *   query per library entry; a library beyond that ceiling could re-create an
 *   entry whose match lies outside the page.
 * - **A partial failure is reported, not raised.** One template that fails to
 *   create is listed in `errors` and the rest are still attempted, matching the
 *   WordPress loop.
 * - **The library is content, not behaviour.** It ships even when a host has
 *   its own; seeding is idempotent by name.
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
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\AbstractTool;

class SeedTemplateLibraryTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const TEMPLATE_TYPE = 'mcp_task_template';

    private const FETCH_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'seed_template_library';
    }

    public function getName(): string
    {
        return 'Seed Template Library';
    }

    public function getDescription(): string
    {
        return 'Seed the task plan template library with fourteen prebuilt templates covering research, content, data analysis, development, marketing and the Pro toolkits. Existing templates are skipped unless overwrite is set.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'overwrite' => array(
                    'type'        => 'boolean',
                    'description' => 'Update templates that already exist (matched by exact name) instead of skipping them.',
                    'default'     => false,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
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
        $this->authorize($context, 'manage_options');

        $userId    = (int) ( $context['user_id'] ?? 0 );
        $overwrite = $this->boolParam($arguments, 'overwrite', false);

        $existing = $this->existingByName();
        $creator  = new CreateTemplateTool($this->errors, $this->content);

        $created = array();
        $skipped = array();
        $errors  = array();

        foreach (TaskTemplateLibrary::all() as $template) {
            $name = (string) ( $template['template_name'] ?? '' );
            $key  = strtolower($name);

            if (isset($existing[ $key ]) && ! $overwrite) {
                $skipped[] = $name;
                continue;
            }

            $creation = $template;

            if (isset($existing[ $key ])) {
                $creation['template_id'] = $existing[ $key ];
            }

            $result = $creator->execute($creation, $context);

            if (! is_array($result) || true !== ( $result['success'] ?? false )) {
                $errors[] = array(
                    'name'  => $name,
                    'error' => $this->errorMessage($result),
                );
                continue;
            }

            $id = (int) ( $result['data']['template_id'] ?? 0 );

            try {
                $this->content->update(
                    $id,
                    new UpdateContentCommand(userId: $userId, status: 'publish')
                );
            } catch (\Throwable $error) {
                $errors[] = array(
                    'name'  => $name,
                    'error' => 'Created but could not publish: ' . $error->getMessage(),
                );
                continue;
            }

            $existing[ $key ] = $id;

            $created[] = array(
                'name'     => $name,
                'category' => (string) ( $template['category'] ?? '' ),
                'id'       => $id,
            );
        }

        return $this->success(
            sprintf(
                'Template library seeded: %d created, %d skipped, %d errors',
                count($created),
                count($skipped),
                count($errors)
            ),
            array(
                'templates_created' => count($created),
                'templates_skipped' => count($skipped),
                'templates_errors'  => count($errors),
                'created'           => $created,
                'skipped'           => $skipped,
                'errors'            => $errors,
                'message'           => sprintf(
                    'Template library seeded: %d created, %d skipped, %d errors',
                    count($created),
                    count($skipped),
                    count($errors)
                ),
            )
        );
    }

    /**
     * Existing template ids by lower-cased title.
     *
     * @return array<string, int>
     */
    private function existingByName(): array
    {
        $collection = $this->content->query(
            new ContentQuery(
                types: array(self::TEMPLATE_TYPE),
                statuses: array('draft', 'publish', 'private'),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: self::FETCH_LIMIT,
            )
        );

        $existing = array();

        foreach ($collection->items as $item) {
            if ($item instanceof ContentItem && self::TEMPLATE_TYPE === $item->type) {
                $existing[ strtolower($item->title) ] = $item->id;
            }
        }

        return $existing;
    }

    /**
     * The message from an envelope or a thrown error factory.
     */
    private function errorMessage(mixed $result): string
    {
        if ($result instanceof \Throwable) {
            return $result->getMessage();
        }

        if (is_array($result)) {
            $message = $result['error']['message'] ?? $result['message'] ?? 'Unknown error';

            return is_scalar($message) ? (string) $message : 'Unknown error';
        }

        return 'Unknown error';
    }
}
