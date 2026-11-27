<?php

/**
 * A Google Calendar client that answers from a table.
 *
 * Records every event write so a test can assert the payload the tool built,
 * and returns the queued event resource or throws the queued failure. The
 * credential surface is mutable so a test can cover the unconfigured path
 * without touching settings.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use Nvoos\Core\Domain\Contract\GoogleCalendarClientInterface;
use Nvoos\Core\Domain\Error\GoogleCalendarApiException;

final class FakeGoogleCalendarClient implements GoogleCalendarClientInterface
{
    public bool $configured = true;

    public string $connection = '';

    public string $name = 'Bookings calendar';

    public string $calendar = 'primary';

    public string $zone = 'UTC';

    /**
     * Every write, in order.
     *
     * @var list<array{verb: string, calendar_id: string, event_id: string, body: array<string, mixed>, params: array<string, mixed>}>
     */
    public array $calls = array();

    /** The resource an insert answers with. */
    public array $inserted = array();

    /** The resource an update answers with. */
    public array $updated = array();

    /** Thrown by the next insert, when set. */
    public ?GoogleCalendarApiException $failInsert = null;

    /** Thrown by the next update, when set. */
    public ?GoogleCalendarApiException $failUpdate = null;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function connectionId(): string
    {
        return $this->connection;
    }

    public function connectionName(): string
    {
        return $this->name;
    }

    public function calendarId(string $override = ''): string
    {
        return '' !== trim($override) ? trim($override) : $this->calendar;
    }

    public function defaultTimeZone(): string
    {
        return $this->zone;
    }

    public function insertEvent(string $calendarId, array $body, array $params = array()): array
    {
        $this->calls[] = array(
            'verb'        => 'insert',
            'calendar_id' => $calendarId,
            'event_id'    => '',
            'body'        => $body,
            'params'      => $params,
        );

        if (null !== $this->failInsert) {
            throw $this->failInsert;
        }

        return $this->inserted;
    }

    public function updateEvent(string $calendarId, string $eventId, array $body, array $params = array()): array
    {
        $this->calls[] = array(
            'verb'        => 'update',
            'calendar_id' => $calendarId,
            'event_id'    => $eventId,
            'body'        => $body,
            'params'      => $params,
        );

        if (null !== $this->failUpdate) {
            throw $this->failUpdate;
        }

        return $this->updated;
    }
}
