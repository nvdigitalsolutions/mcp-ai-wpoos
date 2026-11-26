<?php

/**
 * Tool: list_mcp_tools — the runtime's own tool catalogue.
 *
 * An agent that cannot enumerate its surface either guesses at slugs or carries
 * every schema in context. WordPress had this tool; the Laravel runtime did not,
 * even though presets and capability flags named it, so a preset could advertise
 * a discovery step that failed on the first call.
 *
 * ## What it reads, and why
 *
 * The answer comes from the **live registry**, not the readiness manifest. The
 * manifest is the policy input — which slugs *may* be served — while the registry
 * is the fact: what this host actually constructed. A catalogue built from the
 * manifest would happily list a tool the container skipped for a missing service.
 *
 * ## What is deliberately absent
 *
 * - **No `toolkit` filter.** WordPress derives a toolkit from the declaring
 *   folder (`tools/<toolkit>/`); core tools have no such folder, so a filter
 *   here would accept a value and match nothing.
 * - **No `_meta` block.** The WordPress block carries WordPress identity
 *   (plugin version, site URL, MCP endpoint). This runtime has its own identity
 *   question to answer first; inventing half of it here would be worse than
 *   omitting it.
 * - **No self-entry in the list.** Listing the listing tool adds a row the model
 *   already knows about, and keeps the recursion hazard visible.
 *
 * ## Ordering
 *
 * Entries are sorted by slug. Registration order is an implementation detail of
 * the host, and a catalogue whose order changes between boots is hard to diff.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;

final class ListMcpToolsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public const SLUG = 'list_mcp_tools';

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    public function __construct(ErrorFactoryInterface $errors, private readonly ToolRegistry $registry)
    {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return self::SLUG;
    }

    public function getName(): string
    {
        return 'List MCP Tools';
    }

    public function getDescription(): string
    {
        return 'Returns the tools this runtime can execute, with their parameter schemas, so an agent can discover its own surface before invoking anything. Filter by search, page with limit and offset, omit schemas with include_schemas=false, or fetch one definition with tool_slug.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'search'          => array(
                    'type'        => 'string',
                    'description' => 'Optional. Case-insensitive filter on a tool slug or description.',
                ),
                'limit'           => array(
                    'type'        => 'integer',
                    'description' => 'Maximum tools to return. Default 50, maximum 200.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                ),
                'offset'          => array(
                    'type'        => 'integer',
                    'description' => 'Tools to skip before paging. Default 0.',
                    'minimum'     => 0,
                ),
                'tool_slug'       => array(
                    'type'        => 'string',
                    'description' => 'Optional. Return this one tool\'s full definition instead of a list. Other filters are ignored.',
                ),
                'include_schemas' => array(
                    'type'        => 'boolean',
                    'description' => 'Include each tool\'s parameter schema. Default true; set false for a lean name and description catalogue.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'local-only', 'cacheable', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $search         = $this->stringParam($arguments, 'search');
        $toolSlug       = $this->stringParam($arguments, 'tool_slug');
        $includeSchemas = $this->boolParam($arguments, 'include_schemas', true);
        $limit          = \min(\max(1, $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $offset         = \max(0, $this->intParam($arguments, 'offset', 0));

        // Lazy-loading mode. Asking for this tool by name falls through to the
        // list, because the list is what the caller wants either way.
        if ('' !== $toolSlug && self::SLUG !== $toolSlug) {
            $tool = $this->registry->has($toolSlug) ? $this->registry->get($toolSlug) : null;

            if (! $tool instanceof ToolInterface) {
                return $this->errors->notFound(
                    \sprintf('Tool "%s" is not available in this runtime.', $toolSlug),
                    array( 'tool' => $toolSlug )
                );
            }

            return $this->success(
                'Tool definition retrieved.',
                array(
                    'name'        => $tool->getSlug(),
                    'description' => $tool->getDescription(),
                    'inputSchema' => $tool->getParametersSchema(),
                    'risk_level'  => $this->riskFor($tool),
                )
            );
        }

        $matches = array();

        foreach ($this->registry->enabled() as $slug => $tool) {
            if (self::SLUG === $slug) {
                continue;
            }

            $description = $tool->getDescription();

            if (
                '' !== $search
                && false === \stripos($slug, $search)
                && false === \stripos($description, $search)
            ) {
                continue;
            }

            $entry = array(
                'name'        => $slug,
                'description' => $description,
                'risk_level'  => $this->riskFor($tool),
            );

            if ($includeSchemas) {
                $entry['inputSchema'] = $tool->getParametersSchema();
            }

            $matches[ $slug ] = $entry;
        }

        \ksort($matches);

        $total = \count($matches);
        $page  = \array_slice(\array_values($matches), $offset, $limit);

        return $this->success(
            \sprintf('Found %d tool(s) (total matching: %d).', \count($page), $total),
            array(
                'total'  => $total,
                'count'  => \count($page),
                'limit'  => $limit,
                'offset' => $offset,
                'tools'  => $page,
            )
        );
    }

    /**
     * Map a tool to the risk level a client renders.
     *
     * Declared flags win; the catalogue covers the tools that predate the
     * interface. Keeping the same four values as the WordPress tool means a
     * client does not need a second mapping per runtime.
     */
    private function riskFor(ToolInterface $tool): string
    {
        $flags = $tool instanceof ToolCapabilityFlagsInterface
            ? $tool->getCapabilityFlags()
            : ToolCapabilityFlagCatalogue::for($tool->getSlug());

        if (\in_array('destructive', $flags, true)) {
            return 'high';
        }

        if (\in_array('state-changing', $flags, true) || \in_array('write', $flags, true)) {
            return 'medium';
        }

        if (\in_array('read-only', $flags, true)) {
            return 'low';
        }

        return 'info';
    }
}
