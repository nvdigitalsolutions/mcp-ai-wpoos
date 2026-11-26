<?php

/**
 * Create a calendar event, or replace one when `event_id` is given.
 *
 * Port of `WP_MCP_AI_Tool_Create_Event` (Proposal 164 section 164.84). The
 * record is an `mcp_ai_event` content item; the `_event_*` meta family is kept
 * so an export round-trips.
 *
 * ## Replace means replace
 *
 * WordPress offered an update path on this tool and wrote only the fields it
 * was given. Clearing a start time therefore left `_event_start_time` in meta
 * while `_event_all_day` flipped to 1, and the next listing reported a timed
 * event with a stale clock. The port writes all nine keys on every call, so
 * omitted optional fields clear instead of lingering.
 *
 * ## Corrections
 *
 * - Dates and clock times are validated and normalised; WordPress's schema
 *   patterns were advisory only and its `DateTime` check ran afterwards.
 * - An end time without a start time, an end date before the start date and a
 *   same-day end at or before the start are refused.
 * - An unknown event type is refused; WordPress silently rewrote it to
 *   `meeting`, which hid the caller's mistake.
 * - The `edit` rule is enforced on the replace path, as it is for appointments.
 * - Attendees are stored as strings so the membership filter is portable; see
 *   {@see CalendarEventDefinition}.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateEventTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'create_event';
    }

    public function getName(): string
    {
        return 'Create Event';
    }

    public function getDescription(): string
    {
        return 'Creates a calendar event in UTC, or replaces one when event_id is given. Events are meetings, deadlines, milestones or reminders, and may be all-day by omitting both clock times.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'event_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Optional event ID. When given, the existing event is replaced instead of created.',
                ),
                'title'       => array(
                    'type'        => 'string',
                    'description' => 'Event title (required).',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'Event description (optional, stored as plain text).',
                    'maxLength'   => 5000,
                ),
                'project_id'  => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'ID of the project this event belongs to (optional).',
                ),
                'start_date'  => array(
                    'type'        => 'string',
                    'description' => 'Event start date (Y-m-d) (required).',
                ),
                'start_time'  => array(
                    'type'        => 'string',
                    'description' => 'Event start time (HH:MM, UTC). Omit for an all-day event.',
                ),
                'end_date'    => array(
                    'type'        => 'string',
                    'description' => 'Event end date (Y-m-d). Defaults to start_date.',
                ),
                'end_time'    => array(
                    'type'        => 'string',
                    'description' => 'Event end time (HH:MM, UTC). Omit for an open-ended or all-day event.',
                ),
                'location'    => array(
                    'type'        => 'string',
                    'description' => 'Event location (optional).',
                    'maxLength'   => 500,
                ),
                'type'        => array(
                    'type'        => 'string',
                    'description' => 'Event type.',
                    'enum'        => CalendarEventDefinition::TYPES,
                    'default'     => CalendarEventDefinition::DEFAULT_TYPE,
                ),
                'attendees'   => array(
                    'type'        => 'array',
                    'description' => 'User IDs who will attend (optional).',
                    'items'       => array('type' => 'integer'),
                ),
            ),
            'required'             => array('title', 'start_date'),
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

        $userId = (int) ( $context['user_id'] ?? 0 );
        $title  = Text::singleLine($this->stringParam($arguments, 'title'));

        if ('' === $title) {
            return $this->errors->create('missing_title', 'Event title is required.');
        }

        $startDate = CalendarEventDefinition::date($arguments['start_date'] ?? null);

        if (null === $startDate) {
            return $this->errors->validationFailed(
                'The start date is not a valid date.',
                array('start_date' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        $endDate = $this->dateArg($arguments, 'end_date', $startDate);

        if (null === $endDate) {
            return $this->errors->validationFailed(
                'The end date is not a valid date.',
                array('end_date' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        $startTime = $this->clockArg($arguments, 'start_time');

        if (null === $startTime) {
            return $this->errors->validationFailed(
                'The start time is not a clock time.',
                array('start_time' => array('Use HH:MM in 24-hour time, for example 09:30.'))
            );
        }

        $endTime = $this->clockArg($arguments, 'end_time');

        if (null === $endTime) {
            return $this->errors->validationFailed(
                'The end time is not a clock time.',
                array('end_time' => array('Use HH:MM in 24-hour time, for example 17:00.'))
            );
        }

        $problem = CalendarEventDefinition::spanProblem($startDate, $startTime, $endDate, $endTime);

        if (null !== $problem) {
            return $this->errors->validationFailed(
                $problem['message'],
                array($problem['field'] => array($problem['message']))
            );
        }

        $type = CalendarEventDefinition::type($arguments['type'] ?? '', CalendarEventDefinition::DEFAULT_TYPE);

        if (null === $type) {
            return $this->errors->validationFailed(
                'The event type is not one of the accepted types.',
                array('type' => array('Use one of: ' . implode(', ', CalendarEventDefinition::TYPES) . '.'))
            );
        }

        $projectId = CalendarEventDefinition::projectId($arguments['project_id'] ?? 0);

        if ($projectId > 0) {
            $project = $this->content->find($projectId);

            if (! $project instanceof ContentItem || CalendarEventDefinition::PROJECT_TYPE !== $project->type) {
                return $this->errors->validationFailed(
                    'The project does not exist.',
                    array('project_id' => array('Use the ID of an existing project, or omit it.'))
                );
            }
        }

        if (array_key_exists('attendees', $arguments) && ! is_array($arguments['attendees'])) {
            return $this->errors->validationFailed(
                'Attendees must be a list of user IDs.',
                array('attendees' => array('Send an array of integers.'))
            );
        }

        $attendees = CalendarEventDefinition::attendees($arguments['attendees'] ?? array());
        $location  = Text::singleLine($this->stringParam($arguments, 'location'));
        $content   = isset($arguments['description']) && is_scalar($arguments['description'])
            ? Text::multiline((string) $arguments['description'])
            : '';

        $meta = array(
            CalendarEventDefinition::META_START_DATE => $startDate,
            CalendarEventDefinition::META_END_DATE   => $endDate,
            CalendarEventDefinition::META_START_TIME => $startTime,
            CalendarEventDefinition::META_END_TIME   => $endTime,
            CalendarEventDefinition::META_ALL_DAY    => CalendarEventDefinition::isAllDay($startTime, $endTime) ? '1' : '0',
            CalendarEventDefinition::META_TYPE       => $type,
            CalendarEventDefinition::META_LOCATION   => $location,
            CalendarEventDefinition::META_ATTENDEES  => $attendees,
            CalendarEventDefinition::META_PROJECT_ID => $projectId,
        );

        $eventId  = max(0, $this->intParam($arguments, 'event_id', 0));
        $existing = null;

        if ($eventId > 0) {
            $existing = $this->content->find($eventId);

            if (! $existing instanceof ContentItem || CalendarEventDefinition::TYPE !== $existing->type) {
                return $this->errors->create('event_not_found', 'Event not found.');
            }

            if (! $this->content->userCanAccess($eventId, $userId, 'edit')) {
                return $this->errors->forbidden('You do not have permission to update this event.');
            }
        }

        try {
            if ($existing instanceof ContentItem) {
                $item = $this->content->update(
                    $eventId,
                    new UpdateContentCommand(
                        userId: $userId,
                        title: $title,
                        content: $content,
                        meta: $meta,
                    )
                );
            } else {
                $item = $this->content->create(
                    new CreateContentCommand(
                        title: $title,
                        type: CalendarEventDefinition::TYPE,
                        authorId: $userId,
                        status: 'publish',
                        content: $content,
                        meta: $meta,
                    )
                );
            }
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->create('event_not_found', $error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('event_write_failed', $error->getMessage());
        }

        $updated = null !== $existing;

        return $this->success(
            $updated
                ? sprintf('Event updated: %s', $title)
                : 'Event created successfully.',
            array(
                'event_id' => $item->id,
                'event'    => CalendarEventDefinition::project($item),
                'updated'  => $updated,
            )
        );
    }

    /**
     * An optional date argument, falling back when absent or empty.
     */
    private function dateArg(array $arguments, string $key, string $default): ?string
    {
        if (! array_key_exists($key, $arguments)) {
            return $default;
        }

        $raw = $arguments[ $key ];

        if (null === $raw || '' === $raw) {
            return $default;
        }

        return CalendarEventDefinition::date($raw);
    }

    /**
     * An optional clock argument: '' when absent or cleared, null when invalid.
     */
    private function clockArg(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments)) {
            return '';
        }

        $raw = $arguments[ $key ];

        if (null === $raw || ! is_scalar($raw) || '' === trim((string) $raw)) {
            return '';
        }

        return CalendarEventDefinition::clock($raw);
    }
}
