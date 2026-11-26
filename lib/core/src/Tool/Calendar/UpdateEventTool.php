<?php

/**
 * Update some fields of an existing calendar event.
 *
 * Port of `WP_MCP_AI_Tool_Update_Event` (Proposal 164 section 164.84). Only the
 * supplied fields change; every other stored value is carried forward.
 *
 * ## Corrections
 *
 * WordPress wrote whatever string it was given into a date or time, never
 * checked that the result was a coherent event, and never recomputed
 * `_event_all_day` — so clearing one clock time left a timed event with a
 * half-empty schedule. The port builds the effective record from the stored
 * values plus the supplied ones, validates it, and refreshes the all-day flag
 * whenever a time is touched. An update that would leave an invalid event is
 * refused with the field that has to change, rather than stored.
 *
 * The `edit` rule is enforced; WordPress's update tool checked only the
 * declared capability and then wrote to any id.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class UpdateEventTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'update_event';
    }

    public function getName(): string
    {
        return 'Update Event';
    }

    public function getDescription(): string
    {
        return 'Updates an existing calendar event, changing only the fields you supply. Clock times are UTC and the all-day flag follows the resulting start and end times.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'event_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Event ID to update (required).',
                ),
                'title'       => array(
                    'type'        => 'string',
                    'description' => 'New event title (optional).',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'New event description (optional, stored as plain text).',
                    'maxLength'   => 5000,
                ),
                'start_date'  => array(
                    'type'        => 'string',
                    'description' => 'New start date (Y-m-d) (optional).',
                ),
                'start_time'  => array(
                    'type'        => 'string',
                    'description' => 'New start time (HH:MM, UTC), or an empty string to clear it (optional).',
                ),
                'end_date'    => array(
                    'type'        => 'string',
                    'description' => 'New end date (Y-m-d) (optional).',
                ),
                'end_time'    => array(
                    'type'        => 'string',
                    'description' => 'New end time (HH:MM, UTC), or an empty string to clear it (optional).',
                ),
                'location'    => array(
                    'type'        => 'string',
                    'description' => 'New event location, or an empty string to clear it (optional).',
                ),
                'type'        => array(
                    'type'        => 'string',
                    'description' => 'New event type (optional).',
                    'enum'        => CalendarEventDefinition::TYPES,
                ),
                'attendees'   => array(
                    'type'        => 'array',
                    'description' => 'New attendee user IDs, replacing the list (optional).',
                    'items'       => array('type' => 'integer'),
                ),
            ),
            'required'             => array('event_id'),
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

        $userId  = (int) ( $context['user_id'] ?? 0 );
        $eventId = max(0, $this->intParam($arguments, 'event_id', 0));

        if (0 === $eventId) {
            return $this->errors->create('missing_id', 'Event ID is required.');
        }

        $item = $this->content->find($eventId);

        if (! $item instanceof ContentItem || CalendarEventDefinition::TYPE !== $item->type) {
            return $this->errors->create('invalid_event', 'Event not found.');
        }

        if (! $this->content->userCanAccess($eventId, $userId, 'edit')) {
            return $this->errors->forbidden('You do not have permission to update this event.');
        }

        $meta    = is_array($item->meta) ? $item->meta : array();
        $carried = CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_START_DATE ] ?? '');

        $startDate = array_key_exists('start_date', $arguments)
            ? CalendarEventDefinition::date($arguments['start_date'])
            : CalendarEventDefinition::date($carried);

        if (null === $startDate) {
            return $this->errors->validationFailed(
                'The start date is not a valid date.',
                array('start_date' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        $endDate = $this->effectiveDate($arguments, $meta, $startDate);

        if (null === $endDate) {
            return $this->errors->validationFailed(
                'The end date is not a valid date.',
                array('end_date' => array('Use Y-m-d, for example 2026-01-31.'))
            );
        }

        $storedStart = CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_START_TIME ] ?? '');
        $storedEnd   = CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_END_TIME ] ?? '');

        $startTime = array_key_exists('start_time', $arguments)
            ? $this->clockValue($arguments['start_time'])
            : $storedStart;

        if (null === $startTime) {
            return $this->errors->validationFailed(
                'The start time is not a clock time.',
                array('start_time' => array('Use HH:MM in 24-hour time, for example 09:30.'))
            );
        }

        $endTime = array_key_exists('end_time', $arguments)
            ? $this->clockValue($arguments['end_time'])
            : $storedEnd;

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

        $title = array_key_exists('title', $arguments)
            ? Text::singleLine($this->stringParam($arguments, 'title'))
            : $item->title;

        if ('' === $title) {
            return $this->errors->create('missing_title', 'Event title is required.');
        }

        if (array_key_exists('type', $arguments)) {
            $type = CalendarEventDefinition::type($arguments['type']);

            if (null === $type) {
                return $this->errors->validationFailed(
                    'The event type is not one of the accepted types.',
                    array('type' => array('Use one of: ' . implode(', ', CalendarEventDefinition::TYPES) . '.'))
                );
            }
        } else {
            $type = CalendarEventDefinition::type(
                $meta[ CalendarEventDefinition::META_TYPE ] ?? '',
                CalendarEventDefinition::DEFAULT_TYPE
            ) ?? CalendarEventDefinition::DEFAULT_TYPE;
        }

        if (array_key_exists('attendees', $arguments) && ! is_array($arguments['attendees'])) {
            return $this->errors->validationFailed(
                'Attendees must be a list of user IDs.',
                array('attendees' => array('Send an array of integers.'))
            );
        }

        $attendees = array_key_exists('attendees', $arguments)
            ? CalendarEventDefinition::attendees($arguments['attendees'])
            : CalendarEventDefinition::attendees($meta[ CalendarEventDefinition::META_ATTENDEES ] ?? array());

        $location = array_key_exists('location', $arguments)
            ? Text::singleLine($this->stringParam($arguments, 'location'))
            : CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_LOCATION ] ?? '');

        $content = array_key_exists('description', $arguments) && is_scalar($arguments['description'])
            ? Text::multiline((string) $arguments['description'])
            : $item->content;

        $timesSupplied = array_key_exists('start_time', $arguments) || array_key_exists('end_time', $arguments);
        $allDay        = $timesSupplied
            ? CalendarEventDefinition::isAllDay($startTime, $endTime)
            : CalendarEventDefinition::allDay($meta);

        $write = array(
            CalendarEventDefinition::META_START_DATE => $startDate,
            CalendarEventDefinition::META_END_DATE   => $endDate,
            CalendarEventDefinition::META_START_TIME => $startTime,
            CalendarEventDefinition::META_END_TIME   => $endTime,
            CalendarEventDefinition::META_ALL_DAY    => $allDay ? '1' : '0',
            CalendarEventDefinition::META_TYPE       => $type,
            CalendarEventDefinition::META_LOCATION   => $location,
            CalendarEventDefinition::META_ATTENDEES  => $attendees,
        );

        $projectId = CalendarEventDefinition::projectId(
            $meta[ CalendarEventDefinition::META_PROJECT_ID ] ?? 0
        );

        if ($projectId > 0) {
            $write[ CalendarEventDefinition::META_PROJECT_ID ] = $projectId;
        }

        try {
            $updated = $this->content->update(
                $eventId,
                new UpdateContentCommand(
                    userId: $userId,
                    title: $title,
                    content: $content,
                    meta: $write,
                )
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (NotFoundException $error) {
            return $this->errors->create('invalid_event', $error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('event_write_failed', $error->getMessage());
        }

        return $this->success(
            'Event updated successfully.',
            array(
                'event_id' => $updated->id,
                'event'    => CalendarEventDefinition::project($updated),
                'updated'  => true,
            )
        );
    }

    /**
     * The effective end date: the supplied one, the stored one, or the start.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $meta      Stored meta.
     */
    private function effectiveDate(array $arguments, array $meta, string $startDate): ?string
    {
        if (array_key_exists('end_date', $arguments)) {
            $raw = $arguments['end_date'];

            if (null === $raw || '' === $raw) {
                return $startDate;
            }

            return CalendarEventDefinition::date($raw);
        }

        $stored = CalendarEventDefinition::string($meta[ CalendarEventDefinition::META_END_DATE ] ?? '');

        if ('' === $stored) {
            return $startDate;
        }

        return CalendarEventDefinition::date($stored) ?? $startDate;
    }

    /**
     * A supplied clock value: '' to clear, null when it is not a clock time.
     */
    private function clockValue(mixed $raw): ?string
    {
        if (null === $raw || ! is_scalar($raw) || '' === trim((string) $raw)) {
            return '';
        }

        return CalendarEventDefinition::clock($raw);
    }
}
