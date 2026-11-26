<?php

/**
 * Export a project calendar as an ICS file.
 *
 * Port of `WP_MCP_AI_Tool_Export_Calendar_ICS` (Proposal 164 section 164.84).
 * It collects the project's events and tasks, writes an RFC 5545 document, and
 * either returns it inline or stores it in the file registry.
 *
 * ## What was broken in WordPress
 *
 * 1. **The project guard rejected real projects.** It required the post type
 *    `project`; the project toolkit writes `mcp_ai_project`.
 * 2. **The collector looked in the wrong place.** It queried the post types
 *    `task` and `event` by `post_parent`, and read `_event_date`. The writer
 *    stores `mcp_ai_task` and `mcp_ai_event` and links them with
 *    `_task_project_id` / `_event_project_id`, and dates live in
 *    `_task_due_date` / `_event_start_date`. The port reads what the writers
 *    write, so the export is no longer empty by construction.
 * 3. **Generation needed an unbound sidecar.** WordPress required a media worker
 *    route or a filter. {@see IcsWriter} builds the document in PHP, so the tool
 *    works on any host.
 * 4. **The file was public.** WordPress wrote into the uploads directory with a
 *    guessable name and returned its URL. The port stores through the file
 *    registry, private by default, and the visibility is selectable.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use DateTimeZone;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class ExportCalendarIcsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The ceiling on rows collected per type. */
    private const COLLECT_MAX = 1000;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly ?FileVisibilityStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'export_calendar_ics';
    }

    public function getName(): string
    {
        return 'Export Calendar as ICS';
    }

    public function getDescription(): string
    {
        return 'Exports a project calendar as an RFC 5545 ICS file with its tasks and events, built in PHP. Returns the ICS content, or stores it in the file registry (private by default).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'project_id'       => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Project whose calendar is exported (required).',
                ),
                'include_tasks'    => array(
                    'type'        => 'boolean',
                    'description' => 'Include project tasks as all-day calendar entries.',
                    'default'     => true,
                ),
                'include_events'   => array(
                    'type'        => 'boolean',
                    'description' => 'Include project events.',
                    'default'     => true,
                ),
                'date_range_start' => array(
                    'type'        => 'string',
                    'description' => 'Only items on or after this date (Y-m-d) (optional).',
                ),
                'date_range_end'   => array(
                    'type'        => 'string',
                    'description' => 'Only items on or before this date (Y-m-d) (optional).',
                ),
                'timezone'         => array(
                    'type'        => 'string',
                    'description' => 'IANA timezone name written as X-WR-TIMEZONE for display of all-day dates. Times are stored and emitted as UTC.',
                    'default'     => 'UTC',
                ),
                'organizer_name'   => array(
                    'type'        => 'string',
                    'description' => 'Organizer display name (optional).',
                ),
                'organizer_email'  => array(
                    'type'        => 'string',
                    'description' => 'Organizer email address (optional).',
                ),
                'add_reminders'    => array(
                    'type'        => 'boolean',
                    'description' => 'Add a 15-minute display alarm to each entry.',
                    'default'     => false,
                ),
                'download_file'    => array(
                    'type'        => 'boolean',
                    'description' => 'Store the file and return its details. When false, the ICS content is returned inline.',
                    'default'     => true,
                ),
                'visibility'       => array(
                    'type'        => 'string',
                    'enum'        => array(
                        FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                        FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                    ),
                    'default'     => FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                    'description' => 'Visibility of the stored file. Private by default: the file names attendees and locations.',
                ),
            ),
            'required'             => array('project_id'),
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
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $userId    = (int) ( $context['user_id'] ?? 0 );
        $projectId = max(0, $this->intParam($arguments, 'project_id', 0));

        if (0 === $projectId) {
            return $this->errors->validationFailed(
                'A project ID is required.',
                array('project_id' => array('Use the ID of an existing project.'))
            );
        }

        $project = $this->content->find($projectId);

        if (! $project instanceof ContentItem || CalendarEventDefinition::PROJECT_TYPE !== $project->type) {
            return $this->errors->create('project_not_found', 'Project not found.');
        }

        $from = $this->rangeDate($arguments, 'date_range_start');
        $to   = $this->rangeDate($arguments, 'date_range_end');

        if (null === $from) {
            return $this->errors->validationFailed(
                'date_range_start is not a valid date.',
                array('date_range_start' => array('Use Y-m-d, for example 2026-01-01.'))
            );
        }

        if (null === $to) {
            return $this->errors->validationFailed(
                'date_range_end is not a valid date.',
                array('date_range_end' => array('Use Y-m-d, for example 2026-12-31.'))
            );
        }

        if ('' !== $from && '' !== $to && $from > $to) {
            return $this->errors->validationFailed(
                'date_range_start cannot be later than date_range_end.',
                array('date_range_start' => array('Use a date on or before date_range_end.'))
            );
        }

        $timezone = trim($this->stringParam($arguments, 'timezone', 'UTC'));

        if ('' === $timezone) {
            $timezone = 'UTC';
        }

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $error) {
            return $this->errors->validationFailed(
                'The timezone is not a known IANA timezone.',
                array('timezone' => array('Use a name such as Europe/London, or omit it.'))
            );
        }

        $organizerMail = trim($this->stringParam($arguments, 'organizer_email'));

        if ('' !== $organizerMail && false === filter_var($organizerMail, FILTER_VALIDATE_EMAIL)) {
            return $this->errors->validationFailed(
                'The organizer email is not a valid address.',
                array('organizer_email' => array('Use a single email address, or omit it.'))
            );
        }

        $entries = array();

        if ($this->boolParam($arguments, 'include_tasks', true)) {
            foreach ($this->tasks($projectId, $from, $to) as $entry) {
                $entries[] = $entry;
            }
        }

        if ($this->boolParam($arguments, 'include_events', true)) {
            foreach ($this->events($projectId, $from, $to) as $entry) {
                $entries[] = $entry;
            }
        }

        if (array() === $entries) {
            return $this->errors->create(
                'no_events',
                'No events or tasks were found for this project in the requested range.'
            );
        }

        $ics = IcsWriter::calendar(
            $entries,
            array(
                'calName'   => sprintf('Project: %s', $project->title),
                'timezone'  => $timezone,
                'reminders' => $this->boolParam($arguments, 'add_reminders', false),
                'organizer' => array(
                    'name'  => $this->stringParam($arguments, 'organizer_name'),
                    'email' => $organizerMail,
                ),
            )
        );

        $count = count($entries);
        $text  = sprintf('Calendar exported: %1$d events from %2$s', $count, $project->title);

        if (! $this->boolParam($arguments, 'download_file', true)) {
            return $this->success(
                'Calendar exported successfully.',
                array(
                    'project_id'   => $projectId,
                    'project_name' => $project->title,
                    'event_count'  => $count,
                    'text'         => $text,
                    'ics_content'  => $ics,
                )
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so the export has nowhere to live. Ask for download_file false to receive the content.'
            );
        }

        $visibility = $this->stringParam(
            $arguments,
            'visibility',
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        if (! $this->files->supportsVisibility($visibility)) {
            return $this->errors->validationFailed(
                'The requested visibility is not supported by this host.',
                array('visibility' => array('Use private or public.'))
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'nvoos-ics-');

        if (false === $path) {
            return $this->errors->create('export_failed', 'Could not write the calendar to a temporary file.');
        }

        $filename = sprintf('project-%d-%s.ics', $projectId, gmdate('Ymd-His'));

        try {
            file_put_contents($path, $ics);

            $file = $this->files->storeWithVisibility(
                $path,
                $filename,
                'text/calendar',
                $userId,
                $visibility,
                isset($context['tenant_id']) ? (string) $context['tenant_id'] : null,
            );
        } catch (\Throwable $error) {
            return $this->errors->create('export_not_stored', $error->getMessage());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return $this->success(
            'Calendar exported successfully as an ICS file.',
            array(
                'project_id'    => $projectId,
                'project_name'  => $project->title,
                'event_count'   => $count,
                'text'          => $text,
                'attachment_id' => $file->id,
                'file_url'      => $file->publicUrl,
                'file_path'     => $file->localPath,
                'filename'      => $file->filename,
                'file_size'     => $file->sizeBytes,
                'mime_type'     => $file->mimeType,
                'visibility'    => $file->visibility,
            )
        );
    }

    /**
     * The project's tasks as all-day entries.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(int $projectId, string $from, string $to): array
    {
        $metaQuery = array(
            'relation' => 'AND',
            array(
                'key'     => '_task_project_id',
                'value'   => $projectId,
                'compare' => '=',
            ),
        );

        if ('' !== $from) {
            $metaQuery[] = array(
                'key'     => '_task_due_date',
                'value'   => $from,
                'compare' => '>=',
            );
        }

        if ('' !== $to) {
            $metaQuery[] = array(
                'key'     => '_task_due_date',
                'value'   => $to,
                'compare' => '<=',
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
            self::COLLECT_MAX
        );

        $entries = array();

        foreach ($scan['items'] as $item) {
            $due = CalendarEventDefinition::date($item->meta['_task_due_date'] ?? '');

            if (null === $due) {
                continue;
            }

            $entries[] = array(
                'uid'         => sprintf('task-%d@nvoos', $item->id),
                'title'       => $item->title,
                'description' => $item->content,
                'start'       => $due,
                'end'         => $due,
                'all_day'     => true,
            );
        }

        return $entries;
    }

    /**
     * The project's events, timed or all-day as stored.
     *
     * @return list<array<string, mixed>>
     */
    private function events(int $projectId, string $from, string $to): array
    {
        $metaQuery = array(
            'relation' => 'AND',
            array(
                'key'     => CalendarEventDefinition::META_PROJECT_ID,
                'value'   => $projectId,
                'compare' => '=',
            ),
        );

        if ('' !== $from && '' !== $to) {
            $metaQuery[] = CalendarEventDefinition::overlap(
                CalendarEventDefinition::META_START_DATE,
                CalendarEventDefinition::META_END_DATE,
                $from,
                $to
            );
        } elseif ('' !== $from) {
            $metaQuery[] = array(
                'key'     => CalendarEventDefinition::META_END_DATE,
                'value'   => $from,
                'compare' => '>=',
            );
        } elseif ('' !== $to) {
            $metaQuery[] = array(
                'key'     => CalendarEventDefinition::META_START_DATE,
                'value'   => $to,
                'compare' => '<=',
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
            self::COLLECT_MAX
        );

        $entries = array();

        foreach ($scan['items'] as $item) {
            $entry = $this->eventEntry($item);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * One stored event as a writer entry, or null when it has no usable date.
     *
     * @return array<string, mixed>|null
     */
    private function eventEntry(ContentItem $item): ?array
    {
        $meta      = $item->meta;
        $startDate = CalendarEventDefinition::date($meta[ CalendarEventDefinition::META_START_DATE ] ?? '');

        if (null === $startDate) {
            return null;
        }

        $startTime = CalendarEventDefinition::clock($meta[ CalendarEventDefinition::META_START_TIME ] ?? '');
        $endDate   = CalendarEventDefinition::date($meta[ CalendarEventDefinition::META_END_DATE ] ?? '')
            ?? $startDate;
        $endTime = CalendarEventDefinition::clock($meta[ CalendarEventDefinition::META_END_TIME ] ?? '');

        $entry = array(
            'uid'         => sprintf('event-%d@nvoos', $item->id),
            'title'       => $item->title,
            'description' => $item->content,
            'location'    => CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_LOCATION ] ?? ''),
        );

        $allDay = CalendarEventDefinition::allDay($meta);

        if ($allDay || null === $startTime || '' === $startTime) {
            $entry['all_day'] = true;
            $entry['start']   = $startDate;
            $entry['end']     = $endDate;

            return $entry;
        }

        $entry['all_day']         = false;
        $entry['start']           = $startDate . ' ' . $startTime . ':00';
        $entry['default_minutes'] = 120;

        if (null !== $endTime && '' !== $endTime) {
            $entry['end'] = $endDate . ' ' . $endTime . ':00';
        }

        return $entry;
    }

    /**
     * An optional range bound: '' when absent, null when invalid.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function rangeDate(array $arguments, string $key): ?string
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
