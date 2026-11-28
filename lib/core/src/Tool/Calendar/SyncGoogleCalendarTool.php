<?php

/**
 * Push one appointment to Google Calendar.
 *
 * Port of `WP_MCP_AI_Tool_Sync_Google_Calendar` (Proposal 164 section 164.136).
 * The appointment is read from the content store, turned into an event
 * resource, and written through `GoogleCalendarClientInterface`. The first call
 * inserts and stores the event id; later calls replace the same event.
 *
 * ## The WordPress tool could never sync
 *
 * It read `_appointment_start`, `_appointment_end`, `_appointment_location` and
 * `_appointment_customer_email`. **Nothing in the toolkit writes any of those
 * four keys**; the writers store `_start_time`, `_end_time`, `_location` and
 * `_client_email`. Because the start read was always empty, every call returned
 * `wp_mcp_ai_appointment_missing_start` before reaching Google, so the location
 * and attendee branches were unreachable too. This port reads the keys the
 * writers actually store, which is what makes the capability real rather than
 * nominal.
 *
 * ## Four further corrections
 *
 * - **The mixed date/dateTime pair is resolved.** WordPress emitted `date` for a
 *   date-only start and `dateTime` for the defaulted one-hour end, and Google
 *   rejects an event that mixes the two shapes. A pair that is date-only on
 *   both sides stays all-day — with the end advanced to the next day, because
 *   Google's all-day end date is exclusive — and a mixed pair is promoted to a
 *   timed event at midnight in the calendar's zone.
 * - **`sync_direction` is refused rather than ignored.** The WordPress tool
 *   accepted `from_google` and `bidirectional` and always pushed anyway. A
 *   caller asking for an import now gets a named refusal instead of a successful
 *   push they did not ask for.
 * - **A named connection is refused rather than silently downgraded.** The
 *   WordPress tool resolved a Remote Sites connection first; this runtime has
 *   no connection store, so only the settings connection is served and any other
 *   id is named in the error.
 * - **`send_updates: externalOnly` is actually sent.** WordPress ran the value
 *   through `sanitize_key()`, which lower-cases it to `externalonly`, so the
 *   documented camel-case value fell through to `none` and Google never
 *   received it. The port matches case-insensitively and sends the canonical
 *   spelling.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\GoogleCalendarClientInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\GoogleCalendarApiException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class SyncGoogleCalendarTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** Writes to a third-party API with a stored credential, and to local meta. */
    public const FLAGS = array(
        'external-api',
        'network-dependent',
        'requires-credentials',
        'state-changing',
        'write',
    );

    public const META_EVENT_ID = '_google_calendar_event_id';

    public const META_SYNCED = '_google_calendar_synced';

    public const META_CALENDAR_ID = '_google_calendar_calendar_id';

    /** The `sendUpdates` values Google accepts. */
    public const SEND_UPDATES = array('all', 'externalOnly', 'none');

    /** The directions this tool serves; the others need an import, which does not exist. */
    public const DIRECTIONS = array('to_google');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly GoogleCalendarClientInterface $google,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'sync_google_calendar';
    }

    public function getName(): string
    {
        return 'Sync Google Calendar';
    }

    public function getDescription(): string
    {
        return 'Pushes one appointment to Google Calendar, creating its event on the first call and updating the same event on later calls.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID to sync (required).',
                ),
                'sync_direction' => array(
                    'type'        => 'string',
                    'enum'        => array('to_google', 'from_google', 'bidirectional'),
                    'default'     => 'to_google',
                    'description' => 'Only to_google is implemented; the import directions are refused by name.',
                ),
                'calendar_id'    => array(
                    'type'        => 'string',
                    'description' => 'Google Calendar id; defaults to the configured calendar, then primary.',
                ),
                'connection_id'  => array(
                    'type'        => 'string',
                    'description' => 'Google Calendar connection. Only the settings connection ("settings" or empty) is served.',
                ),
                'send_updates'   => array(
                    'type'        => 'string',
                    'enum'        => array('all', 'externalOnly', 'none'),
                    'default'     => 'none',
                    'description' => 'Whether Google should email the attendee about this change.',
                ),
            ),
            'required'             => array('appointment_id'),
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
        return self::FLAGS;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId = (int) ( $context['user_id'] ?? 0 );
        $id     = max(0, $this->intParam($arguments, 'appointment_id', 0));

        if ($id <= 0) {
            return $this->errors->create('missing_appointment_id', 'Appointment ID is required.');
        }

        $direction = Text::sanitizeKey($this->stringParam($arguments, 'sync_direction', 'to_google'));

        if (! in_array($direction, self::DIRECTIONS, true)) {
            return $this->errors->create(
                'google_calendar_direction_unsupported',
                sprintf(
                    'sync_direction "%s" is not implemented: this tool only pushes an appointment to Google Calendar. Importing Google events is a separate pull that needs a sync cursor and deletion tombstones.',
                    $direction
                )
            );
        }

        $connection = Text::sanitizeKey($this->stringParam($arguments, 'connection_id'));

        if ('' !== $connection && 'settings' !== $connection) {
            return $this->errors->create(
                'google_calendar_connection_unsupported',
                sprintf(
                    'Google Calendar connection "%s" is not available in this runtime. Configure the settings connection instead.',
                    $connection
                )
            );
        }

        if (! $this->google->isConfigured()) {
            return $this->errors->create(
                'google_calendar_not_configured',
                'No Google Calendar credentials are configured. Set google_calendar.client_id, client_secret and refresh_token.'
            );
        }

        $item = $this->content->find($id);

        if (! $item instanceof ContentItem || AppointmentDefinition::TYPE !== $item->type) {
            return $this->errors->create('invalid_appointment', 'Invalid appointment ID.');
        }

        if (! $this->content->userCanAccess($id, $userId, 'edit')) {
            return $this->errors->forbidden('You do not have permission to change this appointment.');
        }

        $calendarId = $this->google->calendarId($this->stringParam($arguments, 'calendar_id'));
        $timezone   = $this->google->defaultTimeZone();

        $payload = $this->payload($item, $timezone);

        if (is_string($payload)) {
            return $this->errors->create('google_calendar_invalid_time', $payload);
        }

        $sendUpdates = $this->sendUpdates($this->stringParam($arguments, 'send_updates', 'none'));

        $params = array('sendUpdates' => $sendUpdates);
        $meta   = $item->meta;
        $event  = isset($meta[ self::META_EVENT_ID ]) && is_scalar($meta[ self::META_EVENT_ID ])
            ? trim((string) $meta[ self::META_EVENT_ID ])
            : '';

        try {
            if ('' !== $event) {
                try {
                    $result    = $this->google->updateEvent($calendarId, $event, $payload, $params);
                    $operation = 'updated';
                } catch (GoogleCalendarApiException $error) {
                    if (! $error->isGone()) {
                        throw $error;
                    }

                    // The linked event was deleted upstream; a replacement keeps
                    // the schedule current instead of failing forever.
                    $result    = $this->google->insertEvent($calendarId, $payload, $params);
                    $operation = 'recreated';
                }
            } else {
                $result    = $this->google->insertEvent($calendarId, $payload, $params);
                $operation = 'created';
            }
        } catch (GoogleCalendarApiException $error) {
            $this->mark($id, $userId, $item, 'failed');

            return $this->failure($error);
        }

        $eventId = isset($result['id']) && is_scalar($result['id']) ? trim((string) $result['id']) : '';

        $this->mark(
            $id,
            $userId,
            $item,
            'synced',
            array_filter(
                array(
                    self::META_EVENT_ID    => $eventId,
                    self::META_CALENDAR_ID => $calendarId,
                ),
                static fn (string $value): bool => '' !== $value
            )
        );

        return $this->success(
            'Appointment synced to Google Calendar.',
            array(
                'appointment_id'  => $id,
                'sync_status'     => 'synced',
                'operation'       => $operation,
                'google_event_id' => $eventId,
                'calendar_id'     => $calendarId,
                'html_link'       => isset($result['htmlLink']) && is_scalar($result['htmlLink'])
                    ? (string) $result['htmlLink']
                    : '',
                'connection_id'   => $this->google->connectionId(),
                'connection_name' => $this->google->connectionName(),
            )
        );
    }

    /**
     * The canonical `sendUpdates` value for a caller's input.
     *
     * WordPress ran the argument through `sanitize_key()`, which lower-cases, so
     * the documented `externalOnly` became `externalonly` and fell back to
     * `none` — the value its own schema advertises was unreachable. Matching
     * case-insensitively keeps the camel-case value intact while still refusing
     * anything Google does not accept.
     */
    private function sendUpdates(string $given): string
    {
        foreach (self::SEND_UPDATES as $value) {
            if (0 === strcasecmp(trim($given), $value)) {
                return $value;
            }
        }

        return 'none';
    }

    /**
     * The Google event resource for one appointment.
     *
     * @return array<string, mixed>|string The resource, or a refusal message.
     */
    private function payload(ContentItem $item, string $timezone): array|string
    {
        $meta    = $item->meta;
        $start   = AppointmentDefinition::string($meta['_start_time'] ?? '');
        $end     = AppointmentDefinition::string($meta['_end_time'] ?? '');
        $payload = array(
            'summary'     => $item->title,
            'description' => Text::multiline($item->content),
        );

        $span = $this->span($start, $end, $timezone);

        if (is_string($span)) {
            return $span;
        }

        $payload['start'] = $span[0];
        $payload['end']   = $span[1];

        $location = AppointmentDefinition::string($meta['_location'] ?? '');

        if ('' !== $location) {
            $payload['location'] = $location;
        }

        $email = AppointmentDefinition::string($meta['_client_email'] ?? '');

        if ('' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // needsAction is Google's recommended value for a new guest;
            // sending an accepted/declined status resets the guest's response.
            $payload['attendees'] = array(
                array(
                    'email'          => $email,
                    'responseStatus' => 'needsAction',
                ),
            );
        }

        return $payload;
    }

    /**
     * The start and end fields, as the same shape.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}|string
     */
    private function span(string $start, string $end, string $timezone): array|string
    {
        $start = trim($start);

        if ('' === $start) {
            return 'This appointment has no start time, so it cannot be synced.';
        }

        $startDate = $this->isDate($start);
        $end       = trim($end);
        $endDate   = '' !== $end && $this->isDate($end);

        if ($startDate && ('' === $end || $endDate)) {
            $last = '' !== $end ? $end : $start;

            // Google's all-day end date is exclusive, so a one-day event ends
            // the following day.
            if ($last <= $start) {
                $last = ( new DateTimeImmutable($start, new DateTimeZone('UTC')) )
                    ->modify('+1 day')
                    ->format('Y-m-d');
            }

            return array(
                array('date' => $start),
                array('date' => $last),
            );
        }

        $from = $this->timed($start, $timezone);

        if (null === $from) {
            return sprintf('Unable to interpret the appointment start time "%s".', $start);
        }

        if ('' === $end) {
            $end = AppointmentDefinition::store($from->modify('+60 minutes'));
        }

        $to = $this->timed($end, $timezone);

        if (null === $to) {
            return sprintf('Unable to interpret the appointment end time "%s".', $end);
        }

        return array(
            array('dateTime' => $from->format(DATE_RFC3339), 'timeZone' => $timezone),
            array('dateTime' => $to->format(DATE_RFC3339), 'timeZone' => $timezone),
        );
    }

    /**
     * A timed field, promoting a date-only value to midnight in the zone.
     */
    private function timed(string $value, string $timezone): ?DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception $error) {
            return null;
        }

        if ($this->isDate($value)) {
            $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

            return false === $midnight ? null : $midnight;
        }

        $instant = AppointmentDefinition::parseTime($value);

        // Stored times are UTC wall clock; the same instant rendered in the
        // calendar's zone is the wall time the appointment was written for.
        return null === $instant ? null : $instant->setTimezone($zone);
    }

    /**
     * Whether a value is a bare calendar date.
     */
    private function isDate(string $value): bool
    {
        return null !== AppointmentDefinition::date($value);
    }

    /**
     * Record the sync state on the appointment, best effort.
     *
     * @param array<string, string> $extra Meta to merge in.
     */
    private function mark(int $id, int $userId, ContentItem $item, string $status, array $extra = array()): void
    {
        $meta = array_merge(
            $item->meta,
            $extra,
            array(self::META_SYNCED => $status)
        );

        try {
            $this->content->update(
                $id,
                new UpdateContentCommand(
                    userId: $userId,
                    title: $item->title,
                    content: $item->content,
                    meta: $meta,
                )
            );
        } catch (\Throwable $error) {
            // The marker is bookkeeping; the API result is the answer the
            // caller asked for, so a failed marker must not mask it.
        }
    }

    /**
     * A refused API call, as the canonical error envelope.
     */
    private function failure(GoogleCalendarApiException $error): mixed
    {
        $data = array();

        if ($error->statusCode > 0) {
            $data['status'] = $error->statusCode;
        }

        if ('' !== $error->apiCode) {
            $data['api_code'] = $error->apiCode;
        }

        if ($error->retryAfter > 0) {
            $data['retry_after'] = $error->retryAfter;
        }

        return $this->errors->create($error->errorCode, $error->getMessage(), $data);
    }
}
