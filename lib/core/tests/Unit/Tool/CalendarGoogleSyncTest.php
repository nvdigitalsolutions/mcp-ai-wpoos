<?php

/**
 * Tests for `sync_google_calendar`, the outbound Google Calendar push.
 *
 * Proposal 164 section 164.136. The cases pin the corrections that separate
 * this port from WordPress: the appointment keys that are actually written, the
 * all-day/timed shape rule, the deleted-event recreate path, and the two
 * directions the tool refuses instead of ignoring.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\GoogleCalendarApiException;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use Nvoos\Core\Tests\Support\FakeGoogleCalendarClient;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\SyncGoogleCalendarTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarGoogleSyncTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    private FakeCalendarStore $store;

    private FakeGoogleCalendarClient $google;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );

        $this->store  = new FakeCalendarStore();
        $this->google = new FakeGoogleCalendarClient();
    }

    // ─── Refusals before a request ───────────────────────────────────────

    public function test_an_unconfigured_client_is_refused_without_a_request(): void
    {
        $this->google->configured = false;
        $this->appointment();

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('google_calendar_not_configured', $result['error']['code']);
        $this->assertSame(array(), $this->google->calls);
    }

    public function test_a_missing_id_is_refused(): void
    {
        $result = $this->tool()->execute(array(), self::CONTEXT);

        $this->assertSame('missing_appointment_id', $result['error']['code']);
    }

    public function test_a_non_appointment_id_is_refused(): void
    {
        $this->store->items[5] = new ContentItem(
            id: 5,
            title: 'A service',
            content: '',
            status: 'publish',
            type: 'mcp_service',
            authorId: 1,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            meta: array(),
        );

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('invalid_appointment', $result['error']['code']);
        $this->assertSame(array(), $this->google->calls);
    }

    public function test_an_edit_denied_appointment_is_forbidden(): void
    {
        $this->appointment();
        $this->store->access[5] = array('edit' => false);

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('forbidden', $result['error']['code']);
        $this->assertSame(array(), $this->google->calls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedDirections(): array
    {
        return array(
            'import'      => array('from_google'),
            'bidirection' => array('bidirectional'),
        );
    }

    #[DataProvider('unsupportedDirections')]
    public function test_the_import_directions_are_refused(string $direction): void
    {
        $this->appointment();

        $result = $this->tool()->execute(
            array('appointment_id' => 5, 'sync_direction' => $direction),
            self::CONTEXT
        );

        $this->assertSame('google_calendar_direction_unsupported', $result['error']['code']);
        $this->assertStringContainsString($direction, $result['error']['message']);
        $this->assertSame(array(), $this->google->calls);
    }

    public function test_a_named_connection_is_refused(): void
    {
        $this->appointment();

        $result = $this->tool()->execute(
            array('appointment_id' => 5, 'connection_id' => 'remote-sites-3'),
            self::CONTEXT
        );

        $this->assertSame('google_calendar_connection_unsupported', $result['error']['code']);
        $this->assertSame(array(), $this->google->calls);
    }

    // ─── Create, update, recreate ────────────────────────────────────────

    public function test_the_first_sync_inserts_and_stores_the_event_id(): void
    {
        $this->appointment();
        $this->google->inserted = array('id' => 'evt-1', 'htmlLink' => 'https://calendar.test/e/1');

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['data']['operation']);
        $this->assertSame('evt-1', $result['data']['google_event_id']);
        $this->assertSame('https://calendar.test/e/1', $result['data']['html_link']);
        $this->assertSame('synced', $result['data']['sync_status']);

        $this->assertCount(1, $this->google->calls);
        $this->assertSame('insert', $this->google->calls[0]['verb']);
        $this->assertSame('primary', $this->google->calls[0]['calendar_id']);

        $stored = $this->store->items[5]->meta;
        $this->assertSame('evt-1', $stored['_google_calendar_event_id']);
        $this->assertSame('synced', $stored['_google_calendar_synced']);
        $this->assertSame('primary', $stored['_google_calendar_calendar_id']);
    }

    public function test_the_second_sync_updates_the_same_event(): void
    {
        $this->appointment(array('_google_calendar_event_id' => 'evt-1'));
        $this->google->updated = array('id' => 'evt-1');

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('updated', $result['data']['operation']);
        $this->assertCount(1, $this->google->calls);
        $this->assertSame('update', $this->google->calls[0]['verb']);
        $this->assertSame('evt-1', $this->google->calls[0]['event_id']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function goneCodes(): array
    {
        return array(
            'deleted upstream' => array('google_calendar_event_deleted'),
            'nulled upstream'  => array('google_calendar_event_not_found'),
        );
    }

    #[DataProvider('goneCodes')]
    public function test_a_vanished_event_is_recreated(string $code): void
    {
        $this->appointment(array('_google_calendar_event_id' => 'evt-old'));
        $this->google->failUpdate = new GoogleCalendarApiException('gone', $code, 404);
        $this->google->inserted   = array('id' => 'evt-new');

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('recreated', $result['data']['operation']);
        $this->assertSame('evt-new', $result['data']['google_event_id']);
        $this->assertSame(array('update', 'insert'), array_column($this->google->calls, 'verb'));
        $this->assertSame('evt-new', $this->store->items[5]->meta['_google_calendar_event_id']);
    }

    public function test_a_refused_update_is_not_recreated_and_marks_the_record(): void
    {
        $this->appointment(array('_google_calendar_event_id' => 'evt-1'));
        $this->google->failUpdate = new GoogleCalendarApiException('nope', 'google_calendar_forbidden', 403);

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('google_calendar_forbidden', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
        $this->assertSame(array('update'), array_column($this->google->calls, 'verb'));
        $this->assertSame('failed', $this->store->items[5]->meta['_google_calendar_synced']);
    }

    // ─── Payload ─────────────────────────────────────────────────────────

    public function test_the_payload_reads_the_keys_the_writers_store(): void
    {
        $this->appointment(
            array(
                '_start_time'   => '2026-03-02 10:00:00',
                '_end_time'     => '2026-03-02 11:00:00',
                '_location'     => '12 Harbour Street',
                '_client_email' => 'client@example.test',
            )
        );
        $this->google->inserted = array('id' => 'evt-1');

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $body = $this->google->calls[0]['body'];

        $this->assertSame('12 Harbour Street', $body['location']);
        $this->assertSame(
            array(array('email' => 'client@example.test', 'responseStatus' => 'needsAction')),
            $body['attendees']
        );
        $this->assertSame('2026-03-02T10:00:00+00:00', $body['start']['dateTime']);
        $this->assertSame('UTC', $body['start']['timeZone']);
        $this->assertSame('2026-03-02T11:00:00+00:00', $body['end']['dateTime']);
    }

    public function test_markup_is_stripped_from_the_description(): void
    {
        $this->appointment(
            array('_start_time' => '2026-03-02 10:00:00', '_end_time' => '2026-03-02 11:00:00'),
            'Appointment',
            '<p>Bring <strong>ID</strong></p><script>alert(1)</script>'
        );
        $this->google->inserted = array('id' => 'evt-1');

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('Bring ID', $this->google->calls[0]['body']['description']);
    }

    public function test_stored_utc_is_rendered_in_the_calendar_zone(): void
    {
        $this->google->zone = 'America/New_York';
        $this->appointment(
            array('_start_time' => '2026-03-02 10:00:00', '_end_time' => '2026-03-02 11:00:00')
        );
        $this->google->inserted = array('id' => 'evt-1');

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $start = $this->google->calls[0]['body']['start'];

        $this->assertSame('2026-03-02T05:00:00-05:00', $start['dateTime']);
        $this->assertSame('America/New_York', $start['timeZone']);
    }

    public function test_an_all_day_appointment_stays_all_day_with_an_exclusive_end(): void
    {
        $this->appointment(array('_start_time' => '2026-03-02', '_end_time' => ''));

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $body = $this->google->calls[0]['body'];

        $this->assertSame(array('date' => '2026-03-02'), $body['start']);
        $this->assertSame(array('date' => '2026-03-03'), $body['end']);
    }

    public function test_a_mixed_pair_becomes_a_timed_event(): void
    {
        $this->appointment(
            array('_start_time' => '2026-03-02', '_end_time' => '2026-03-02 15:00:00')
        );

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $body = $this->google->calls[0]['body'];

        $this->assertSame('2026-03-02T00:00:00+00:00', $body['start']['dateTime']);
        $this->assertSame('2026-03-02T15:00:00+00:00', $body['end']['dateTime']);
        $this->assertArrayNotHasKey('date', $body['start']);
    }

    public function test_an_unreadable_start_is_refused(): void
    {
        $this->appointment(array('_start_time' => 'next friday'));
        $this->google->inserted = array('id' => 'evt-1');

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('google_calendar_invalid_time', $result['error']['code']);
        $this->assertSame(array(), $this->google->calls);
    }

    public function test_a_missing_start_is_refused(): void
    {
        $this->appointment(array('_start_time' => ''));

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('google_calendar_invalid_time', $result['error']['code']);
        $this->assertStringContainsString('no start time', $result['error']['message']);
    }

    public function test_an_invalid_attendee_email_is_omitted(): void
    {
        $this->appointment(
            array('_start_time' => '2026-03-02 10:00:00', '_client_email' => 'not-an-email')
        );
        $this->google->inserted = array('id' => 'evt-1');

        $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertArrayNotHasKey('attendees', $this->google->calls[0]['body']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function sendUpdates(): array
    {
        return array(
            'all'           => array('all', 'all'),
            'external only' => array('externalOnly', 'externalOnly'),
            'none'          => array('none', 'none'),
            'invalid'       => array('everyone', 'none'),
        );
    }

    #[DataProvider('sendUpdates')]
    public function test_send_updates_is_validated(mixed $given, string $expected): void
    {
        $this->appointment(array('_start_time' => '2026-03-02 10:00:00'));
        $this->google->inserted = array('id' => 'evt-1');

        $this->tool()->execute(
            array('appointment_id' => 5, 'send_updates' => $given),
            self::CONTEXT
        );

        $this->assertSame($expected, $this->google->calls[0]['params']['sendUpdates']);
    }

    public function test_the_calendar_override_wins_and_is_echoed(): void
    {
        $this->appointment(array('_start_time' => '2026-03-02 10:00:00'));
        $this->google->inserted = array('id' => 'evt-1');

        $result = $this->tool()->execute(
            array('appointment_id' => 5, 'calendar_id' => 'team@group.calendar.google.com'),
            self::CONTEXT
        );

        $this->assertSame('team@group.calendar.google.com', $this->google->calls[0]['calendar_id']);
        $this->assertSame('team@group.calendar.google.com', $result['data']['calendar_id']);
        $this->assertSame(
            'team@group.calendar.google.com',
            $this->store->items[5]->meta['_google_calendar_calendar_id']
        );
    }

    public function test_the_connection_is_reported_in_the_envelope(): void
    {
        $this->appointment(array('_start_time' => '2026-03-02 10:00:00'));
        $this->google->inserted = array('id' => 'evt-1');
        $this->google->name     = 'Reception';

        $result = $this->tool()->execute(array('appointment_id' => 5), self::CONTEXT);

        $this->assertSame('', $result['data']['connection_id']);
        $this->assertSame('Reception', $result['data']['connection_name']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Seed an appointment, using the keys the toolkit's writers store.
     *
     * @param array<string, mixed> $meta Meta overrides.
     */
    private function appointment(
        array $meta = array(),
        string $title = 'Appointment',
        string $content = ''
    ): void {
        $this->store->items[5] = new ContentItem(
            id: 5,
            title: $title,
            content: $content,
            status: 'publish',
            type: AppointmentDefinition::TYPE,
            authorId: 1,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            meta: array_merge(
                array(
                    '_start_time'   => '2026-03-02 10:00:00',
                    '_end_time'     => '2026-03-02 11:00:00',
                    '_status'       => 'confirmed',
                    '_client_email' => '',
                    '_location'     => '',
                ),
                $meta
            )
        );
    }

    private function tool(): SyncGoogleCalendarTool
    {
        return new SyncGoogleCalendarTool($this->errors, $this->store, $this->google);
    }
}
