<?php

/**
 * List calendar events with filters.
 *
 * Port of `WP_MCP_AI_Tool_List_Events` (Proposal 164 section 164.84). Reads
 * `mcp_ai_event` records through `ContentStoreInterface`; the filters map to
 * the same `_event_*` meta WordPress queried.
 *
 * ## Corrections
 *
 * - **Dates are validated.** WordPress passed any string into a DATE meta
 *   comparison; a reversed range returned an empty list with no explanation.
 *   The port refuses both.
 * - **The event type is validated** instead of silently matching nothing.
 * - **Events are sorted by start date, then start time.** The store cannot order
 *   by meta, so the tool reads a bounded window and sorts it, and reports
 *   `truncated` when the collection holds more rows than the window.
 * - **The attendee filter is portable.** WordPress matched a PHP-serialised
 *   substring; the port stores IDs as strings and uses the quoted JSON/PHP
 *   LIKE that `list_projects` already relies on.
 *
 * `total` counts every row the store matched; `count` is the page returned.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class ListEventsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'list_events';
    }

    public function getName(): string
    {
        return 'List Events';
    }

    public function getDescription(): string
    {
        return 'Lists calendar events, oldest first, optionally filtered by project, type, attendee or start-date range. Returns up to 500 events.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'project_id'   => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Only events belonging to this project (optional).',
                ),
                'type'         => array(
                    'type'        => 'string',
                    'description' => 'Only events of this type (optional).',
                    'enum'        => CalendarEventDefinition::TYPES,
                ),
                'attendee'     => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Only events listing this user ID as an attendee (optional).',
                ),
                'start_after'  => array(
                    'type'        => 'string',
                    'description' => 'Only events starting on or after this date (Y-m-d) (optional).',
                ),
                'start_before' => array(
                    'type'        => 'string',
                    'description' => 'Only events starting on or before this date (Y-m-d) (optional).',
                ),
                'limit'        => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of events to return.',
                    'default'     => CalendarEventDefinition::LIST_LIMIT_DEFAULT,
                    'minimum'     => 1,
                    'maximum'     => CalendarEventDefinition::LIST_LIMIT_MAX,
                ),
            ),
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
        return CapabilityFlagVocabulary::LOCAL_READ;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $startAfter  = $this->optionalDate($arguments, 'start_after');
        $startBefore = $this->optionalDate($arguments, 'start_before');

        if (null === $startAfter) {
            return $this->errors->validationFailed(
                'start_after is not a valid date.',
                array('start_after' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        if (null === $startBefore) {
            return $this->errors->validationFailed(
                'start_before is not a valid date.',
                array('start_before' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        if ('' !== $startAfter && '' !== $startBefore && $startAfter > $startBefore) {
            return $this->errors->validationFailed(
                'start_after cannot be later than start_before.',
                array('start_after' => array('Use a date on or before start_before.'))
            );
        }

        $clauses = array();
        $project = CalendarEventDefinition::projectId($arguments['project_id'] ?? 0);

        if ($project > 0) {
            $clauses[] = array(
                'key'     => CalendarEventDefinition::META_PROJECT_ID,
                'value'   => $project,
                'compare' => '=',
            );
        }

        $typeParam = $this->stringParam($arguments, 'type');

        if ('' !== $typeParam) {
            $type = CalendarEventDefinition::type($typeParam);

            if (null === $type) {
                return $this->errors->validationFailed(
                    'The event type is not one of the accepted types.',
                    array('type' => array('Use one of: ' . implode(', ', CalendarEventDefinition::TYPES) . '.'))
                );
            }

            $clauses[] = array(
                'key'     => CalendarEventDefinition::META_TYPE,
                'value'   => $type,
                'compare' => '=',
            );
        }

        $attendee = max(0, $this->intParam($arguments, 'attendee', 0));

        if ($attendee > 0) {
            $clauses[] = CalendarEventDefinition::attendeeClause(
                CalendarEventDefinition::META_ATTENDEES,
                $attendee
            );
        }

        if ('' !== $startAfter) {
            $clauses[] = array(
                'key'     => CalendarEventDefinition::META_START_DATE,
                'value'   => $startAfter,
                'compare' => '>=',
            );
        }

        if ('' !== $startBefore) {
            $clauses[] = array(
                'key'     => CalendarEventDefinition::META_START_DATE,
                'value'   => $startBefore,
                'compare' => '<=',
            );
        }

        $metaQuery = array();

        if (array() !== $clauses) {
            $metaQuery = array_merge(array('relation' => 'AND'), $clauses);
        }

        $scan = CalendarEventDefinition::scan(
            $this->content,
            new ContentQuery(
                types: array(CalendarEventDefinition::TYPE),
                statuses: array('publish'),
                metaQuery: $metaQuery,
                orderBy: 'id',
                order: 'DESC',
            )
        );

        $items = $scan['items'];
        usort(
            $items,
            static fn (ContentItem $a, ContentItem $b): int => CalendarEventDefinition::compare($a, $b)
        );

        $limit = max(1, min(
            CalendarEventDefinition::LIST_LIMIT_MAX,
            $this->intParam($arguments, 'limit', CalendarEventDefinition::LIST_LIMIT_DEFAULT)
        ));

        $events = array();

        foreach (array_slice($items, 0, $limit) as $item) {
            $events[] = CalendarEventDefinition::project($item);
        }

        return $this->success(
            sprintf('%d event(s) found.', count($events)),
            array(
                'count'     => count($events),
                'total'     => $scan['total'],
                'events'    => $events,
                'truncated' => $scan['total'] > $scan['scanned'],
            )
        );
    }

    /**
     * An optional date filter: '' when absent, null when invalid.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function optionalDate(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments)) {
            return '';
        }

        $raw = $arguments[ $key ];

        if (null === $raw || '' === $raw) {
            return '';
        }

        return CalendarEventDefinition::date($raw);
    }
}
