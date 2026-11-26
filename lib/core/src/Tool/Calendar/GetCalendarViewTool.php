<?php

/**
 * A unified calendar view over projects, tasks and events.
 *
 * Port of `WP_MCP_AI_Tool_Get_Calendar_View` (Proposal 164 section 164.84). It
 * reads the three published content types the project toolkit writes —
 * `mcp_ai_project`, `mcp_ai_task` and `mcp_ai_event` — and merges them into one
 * date-sorted list or a date-keyed map.
 *
 * ## Corrections
 *
 * 1. **A record that spans the window is not invisible.** WordPress asked
 *    whether the project start **or** the project end fell inside the range, so
 *    a project running all year disappeared from a January view. Projects and
 *    events now use the standard overlap test
 *    (`start <= window_end AND end >= window_start`), which is the same
 *    correction the appointment conflict query needed.
 * 2. **The dates are validated and ordered.** A reversed range in WordPress
 *    returned an empty calendar without saying why.
 * 3. **`include_types` is validated.** An unknown value used to be ignored, so a
 *    typo looked like an empty calendar; it is refused now.
 * 4. **The user filter is portable.** Assignee and attendee membership use the
 *    quoted JSON/PHP LIKE that `list_projects` relies on.
 *
 * The external booking systems are not merged: this runtime binds no booking
 * adapter, and the WordPress argument that enabled them was absent from its own
 * schema.
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

class GetCalendarViewTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The types a caller may include. */
    public const TYPES = array('projects', 'tasks', 'events');

    /** WordPress's per-type ceilings. */
    private const CAPS = array(
        'projects' => 100,
        'tasks'    => 200,
        'events'   => 500,
    );

    /** Sort rank so same-day items keep the projects/tasks/events order. */
    private const RANK = array(
        'project' => 0,
        'task'    => 1,
        'event'   => 2,
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_calendar_view';
    }

    public function getName(): string
    {
        return 'Get Calendar View';
    }

    public function getDescription(): string
    {
        return 'Builds a calendar view for a date range, merging projects, tasks and events into one date-sorted list or a date-keyed map.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'start_date'    => array(
                    'type'        => 'string',
                    'description' => 'Start of the view range (Y-m-d) (required).',
                ),
                'end_date'      => array(
                    'type'        => 'string',
                    'description' => 'End of the view range (Y-m-d) (required).',
                ),
                'project_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Only items belonging to this project (optional).',
                ),
                'user_id'       => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Only items assigned to or attended by this user (optional).',
                ),
                'include_types' => array(
                    'type'        => 'array',
                    'description' => 'Which item types to include.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => self::TYPES,
                    ),
                    'default'     => self::TYPES,
                ),
                'group_by_date' => array(
                    'type'        => 'boolean',
                    'description' => 'Group the result by date instead of returning a flat list.',
                    'default'     => true,
                ),
            ),
            'required'             => array('start_date', 'end_date'),
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

        $startDate = CalendarEventDefinition::date($arguments['start_date'] ?? null);
        $endDate   = CalendarEventDefinition::date($arguments['end_date'] ?? null);

        if (null === $startDate || null === $endDate) {
            return $this->errors->validationFailed(
                'Both start_date and end_date are required as dates.',
                array('start_date' => array('Use Y-m-d, for example 2026-01-01.'))
            );
        }

        if ($startDate > $endDate) {
            return $this->errors->validationFailed(
                'start_date cannot be later than end_date.',
                array('start_date' => array('Use a date on or before end_date.'))
            );
        }

        $types = $this->types($arguments);

        if (null === $types) {
            return $this->errors->validationFailed(
                'include_types names an unknown item type.',
                array('include_types' => array('Use any of: ' . implode(', ', self::TYPES) . '.'))
            );
        }

        $projectId = CalendarEventDefinition::projectId($arguments['project_id'] ?? 0);
        $userId    = max(0, $this->intParam($arguments, 'user_id', 0));
        $groupBy   = $this->boolParam($arguments, 'group_by_date', true);

        $items     = array();
        $truncated = false;

        if (in_array('projects', $types, true)) {
            $rows = $this->projects($startDate, $endDate, $projectId, $userId, $truncated);

            foreach ($rows as $row) {
                $items[] = $row;
            }
        }

        if (in_array('tasks', $types, true)) {
            $rows = $this->tasks($startDate, $endDate, $projectId, $userId, $truncated);

            foreach ($rows as $row) {
                $items[] = $row;
            }
        }

        if (in_array('events', $types, true)) {
            $rows = $this->events($startDate, $endDate, $projectId, $userId, $truncated);

            foreach ($rows as $row) {
                $items[] = $row;
            }
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                $byDate = strcmp((string) $a['date'], (string) $b['date']);

                if (0 !== $byDate) {
                    return $byDate;
                }

                $byType = ( self::RANK[ $a['type'] ] ?? 9 ) <=> ( self::RANK[ $b['type'] ] ?? 9 );

                return 0 !== $byType ? $byType : ( $a['id'] <=> $b['id'] );
            }
        );

        $payload = array(
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'count'      => count($items),
            'truncated'  => $truncated,
        );

        if ($groupBy) {
            $grouped = array();

            foreach ($items as $item) {
                $date = (string) $item['date'];

                if (! isset($grouped[ $date ])) {
                    $grouped[ $date ] = array();
                }

                $grouped[ $date ][] = $item;
            }

            $payload['calendar'] = $grouped;
        } else {
            $payload['items'] = $items;
        }

        return $this->success(
            sprintf('%d calendar item(s) found.', count($items)),
            $payload
        );
    }

    /**
     * The requested item types, or null when one is unknown.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<string>|null
     */
    private function types(array $arguments): ?array
    {
        if (! array_key_exists('include_types', $arguments)) {
            return self::TYPES;
        }

        $raw = $arguments['include_types'];

        if (! is_array($raw)) {
            return null;
        }

        $types = array();

        foreach ($raw as $type) {
            if (! is_scalar($type)) {
                return null;
            }

            $type = (string) $type;

            if (! in_array($type, self::TYPES, true)) {
                return null;
            }

            if (! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Published projects overlapping the window.
     *
     * @return list<array<string, mixed>>
     */
    private function projects(
        string $from,
        string $to,
        int $projectId,
        int $userId,
        bool &$truncated,
    ): array {
        $metaQuery = array(
            'relation' => 'AND',
            CalendarEventDefinition::overlap('_project_start_date', '_project_end_date', $from, $to),
        );

        if ($userId > 0) {
            $metaQuery[] = CalendarEventDefinition::attendeeClause('_project_assigned_to', $userId);
        }

        $scan = CalendarEventDefinition::scan(
            $this->content,
            new ContentQuery(
                types: array(CalendarEventDefinition::PROJECT_TYPE),
                statuses: array('publish'),
                include: $projectId > 0 ? array($projectId) : array(),
                metaQuery: $metaQuery,
                orderBy: 'id',
                order: 'ASC',
            ),
            self::CAPS['projects']
        );

        $truncated = $truncated || $scan['total'] > $scan['scanned'];

        $rows = array();

        foreach ($scan['items'] as $item) {
            $start = CalendarEventDefinition::string($item->meta['_project_start_date'] ?? '');

            $rows[] = array(
                'type'       => 'project',
                'id'         => $item->id,
                'title'      => $item->title,
                'date'       => '' !== $start ? $start : $item->createdAt->format(CalendarEventDefinition::DATE_FORMAT),
                'status'     => CalendarEventDefinition::string($item->meta['_project_status'] ?? ''),
                'project_id' => $item->id,
            );
        }

        return $rows;
    }

    /**
     * Published tasks due inside the window.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(
        string $from,
        string $to,
        int $projectId,
        int $userId,
        bool &$truncated,
    ): array {
        $metaQuery = array(
            'relation' => 'AND',
            array(
                'key'     => '_task_due_date',
                'value'   => array($from, $to),
                'compare' => 'BETWEEN',
            ),
        );

        if ($projectId > 0) {
            $metaQuery[] = array(
                'key'     => '_task_project_id',
                'value'   => $projectId,
                'compare' => '=',
            );
        }

        if ($userId > 0) {
            $metaQuery[] = array(
                'key'     => '_task_assigned_to',
                'value'   => $userId,
                'compare' => '=',
            );
        }

        $scan = CalendarEventDefinition::scan(
            $this->content,
            new ContentQuery(
                types: array(CalendarEventDefinition::TASK_TYPE),
                statuses: array('publish'),
                metaQuery: $metaQuery,
                orderBy: 'id',
                order: 'ASC',
            ),
            self::CAPS['tasks']
        );

        $truncated = $truncated || $scan['total'] > $scan['scanned'];

        $rows = array();

        foreach ($scan['items'] as $item) {
            $project = CalendarEventDefinition::projectId($item->meta['_task_project_id'] ?? 0);

            $rows[] = array(
                'type'       => 'task',
                'id'         => $item->id,
                'title'      => $item->title,
                'date'       => CalendarEventDefinition::string($item->meta['_task_due_date'] ?? ''),
                'status'     => CalendarEventDefinition::string($item->meta['_task_status'] ?? ''),
                'priority'   => CalendarEventDefinition::string($item->meta['_task_priority'] ?? ''),
                'project_id' => $project > 0 ? $project : null,
            );
        }

        return $rows;
    }

    /**
     * Published events overlapping the window.
     *
     * @return list<array<string, mixed>>
     */
    private function events(
        string $from,
        string $to,
        int $projectId,
        int $userId,
        bool &$truncated,
    ): array {
        $metaQuery = array(
            'relation' => 'AND',
            CalendarEventDefinition::overlap(
                CalendarEventDefinition::META_START_DATE,
                CalendarEventDefinition::META_END_DATE,
                $from,
                $to
            ),
        );

        if ($projectId > 0) {
            $metaQuery[] = array(
                'key'     => CalendarEventDefinition::META_PROJECT_ID,
                'value'   => $projectId,
                'compare' => '=',
            );
        }

        if ($userId > 0) {
            $metaQuery[] = CalendarEventDefinition::attendeeClause(
                CalendarEventDefinition::META_ATTENDEES,
                $userId
            );
        }

        $scan = CalendarEventDefinition::scan(
            $this->content,
            new ContentQuery(
                types: array(CalendarEventDefinition::TYPE),
                statuses: array('publish'),
                metaQuery: $metaQuery,
                orderBy: 'id',
                order: 'ASC',
            ),
            self::CAPS['events']
        );

        $truncated = $truncated || $scan['total'] > $scan['scanned'];

        $rows = array();

        foreach ($scan['items'] as $item) {
            $project = CalendarEventDefinition::projectId(
                $item->meta[ CalendarEventDefinition::META_PROJECT_ID ] ?? 0
            );
            $startTime = CalendarEventDefinition::string(
                $item->meta[ CalendarEventDefinition::META_START_TIME ] ?? ''
            );
            $endTime = CalendarEventDefinition::string(
                $item->meta[ CalendarEventDefinition::META_END_TIME ] ?? ''
            );
            $endDate = CalendarEventDefinition::string(
                $item->meta[ CalendarEventDefinition::META_END_DATE ] ?? ''
            );
            $location = CalendarEventDefinition::string(
                $item->meta[ CalendarEventDefinition::META_LOCATION ] ?? ''
            );

            $rows[] = array(
                'type'       => 'event',
                'id'         => $item->id,
                'title'      => $item->title,
                'date'       => CalendarEventDefinition::string(
                    $item->meta[ CalendarEventDefinition::META_START_DATE ] ?? ''
                ),
                'time'       => '' !== $startTime ? $startTime : null,
                'end_date'   => '' !== $endDate
                    ? $endDate
                    : CalendarEventDefinition::string($item->meta[ CalendarEventDefinition::META_START_DATE ] ?? ''),
                'end_time'   => '' !== $endTime ? $endTime : null,
                'all_day'    => CalendarEventDefinition::allDay($item->meta),
                'event_type' => CalendarEventDefinition::string(
                    $item->meta[ CalendarEventDefinition::META_TYPE ] ?? ''
                ),
                'location'   => '' !== $location ? $location : null,
                'project_id' => $project > 0 ? $project : null,
            );
        }

        return $rows;
    }
}
