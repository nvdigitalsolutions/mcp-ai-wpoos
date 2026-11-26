<?php

/**
 * The six event tools, the ICS writer and the shared event rules.
 *
 * Pinned behaviours:
 *
 * - `create_event` writes the nine `_event_*` keys on every call, so a replace
 *   clears omitted times, location and attendees instead of leaving them
 *   behind.
 * - Dates and clock times are validated; an unknown type, an end time without a
 *   start, a reversed span and a same-day end at or before the start are refused.
 * - `update_event` changes only supplied fields, recomputes `_event_all_day`
 *   when a time is touched, and refuses an incoherent result.
 * - `delete_event` honours the store's delete rule.
 * - `list_events` filters in the store, sorts by start date and time, and
 *   reports truncation when the scan ceiling is reached.
 * - `get_calendar_view` sees a project or event that spans the whole window —
 *   the case WordPress missed by testing each bound separately — and merges the
 *   three item shapes.
 * - The ICS writer escapes text, folds at 75 octets on a UTF-8 boundary, emits
 *   all-day dates and UTC times, and the export reads the meta keys the writers
 *   actually store.
 *
 * The fake store evaluates the same clause shapes the tools emit, including
 * nested `relation` groups and the quoted-ID LIKE, so a wrong clause produces a
 * wrong result rather than a passing test.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use DateTimeZone;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Tests\Support\FakeEventFiles;
use Nvoos\Core\Tests\Support\FakeEventStore;
use Nvoos\Core\Tool\Calendar\CalendarEventDefinition;
use Nvoos\Core\Tool\Calendar\CreateEventTool;
use Nvoos\Core\Tool\Calendar\DeleteEventTool;
use Nvoos\Core\Tool\Calendar\ExportCalendarIcsTool;
use Nvoos\Core\Tool\Calendar\GetCalendarViewTool;
use Nvoos\Core\Tool\Calendar\IcsWriter;
use Nvoos\Core\Tool\Calendar\ListEventsTool;
use Nvoos\Core\Tool\Calendar\UpdateEventTool;
use PHPUnit\Framework\TestCase;

final class CalendarEventToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── Definition ──────────────────────────────────────────────────────

    public function test_dates_and_clock_times_are_validated(): void
    {
        $this->assertSame('2026-02-01', CalendarEventDefinition::date('2026-02-01'));
        $this->assertNull(CalendarEventDefinition::date('2026-02-31'));
        $this->assertNull(CalendarEventDefinition::date('01/02/2026'));
        $this->assertNull(CalendarEventDefinition::date('next monday'));

        $this->assertSame('09:05', CalendarEventDefinition::clock('9:05'));
        $this->assertNull(CalendarEventDefinition::clock('24:00'));
    }

    public function test_attendees_are_stored_as_strings_and_read_as_integers(): void
    {
        $stored = CalendarEventDefinition::attendees(array(7, '7', 0, -2, '8', array(9)));

        $this->assertSame(array('7', '8'), $stored);
        $this->assertSame(array(7, 8), CalendarEventDefinition::attendeeIds($stored));
    }

    public function test_span_problems_name_the_field_to_change(): void
    {
        $this->assertNull(
            CalendarEventDefinition::spanProblem('2026-02-01', '09:00', '2026-02-01', '10:00')
        );

        $reversedDate = CalendarEventDefinition::spanProblem('2026-02-02', '', '2026-02-01', '');
        $this->assertSame('end_date', $reversedDate['field']);

        $endWithoutStart = CalendarEventDefinition::spanProblem('2026-02-01', '', '2026-02-01', '10:00');
        $this->assertSame('end_time', $endWithoutStart['field']);

        $touching = CalendarEventDefinition::spanProblem('2026-02-01', '10:00', '2026-02-01', '10:00');
        $this->assertSame('end_time', $touching['field']);
    }

    // ─── create_event ────────────────────────────────────────────────────

    public function test_create_writes_every_meta_key_and_the_all_day_flag(): void
    {
        $store = new FakeEventStore();
        $tool  = new CreateEventTool($this->errors, $store);

        $result = $tool->execute(
            array(
                'title'       => 'Team sync',
                'description' => 'Weekly sync',
                'start_date'  => '2026-02-01',
                'type'        => 'meeting',
                'attendees'   => array(7, 8),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['event']['all_day']);
        $this->assertSame('2026-02-01', $result['data']['event']['start_date']);
        $this->assertSame(array(7, 8), $result['data']['event']['attendees']);

        $command = $store->created;
        $this->assertInstanceOf(CreateContentCommand::class, $command);
        $this->assertSame(CalendarEventDefinition::TYPE, $command->type);
        $this->assertSame('publish', $command->status);

        $meta = $command->meta;
        $this->assertSame('2026-02-01', $meta['_event_start_date']);
        $this->assertSame('2026-02-01', $meta['_event_end_date']);
        $this->assertSame('', $meta['_event_start_time']);
        $this->assertSame('', $meta['_event_end_time']);
        $this->assertSame('1', $meta['_event_all_day']);
        $this->assertSame('meeting', $meta['_event_type']);
        $this->assertSame('', $meta['_event_location']);
        $this->assertSame(array('7', '8'), $meta['_event_attendees']);
        $this->assertSame(0, $meta['_event_project_id']);
    }

    public function test_create_stores_timed_events_in_utc_wall_clock(): void
    {
        $store = new FakeEventStore();
        $tool  = new CreateEventTool($this->errors, $store);

        $result = $tool->execute(
            array(
                'title'      => 'Workshop',
                'start_date' => '2026-02-01',
                'start_time' => '9:00',
                'end_time'   => '10:30',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['event']['all_day']);
        $this->assertSame('09:00', $result['data']['event']['start_time']);
        $this->assertSame('0', $store->created->meta['_event_all_day']);
    }

    public function test_create_refuses_incoherent_spans(): void
    {
        $store = new FakeEventStore();
        $tool  = new CreateEventTool($this->errors, $store);

        $cases = array(
            array('start_date' => '2026-02-02', 'end_date' => '2026-02-01'),
            array('start_date' => '2026-02-01', 'end_time' => '10:00'),
            array('start_date' => '2026-02-01', 'start_time' => '11:00', 'end_time' => '10:00'),
            array('start_date' => '2026-02-31'),
        );

        foreach ($cases as $case) {
            $result = $tool->execute(array_merge(array('title' => 'Bad span'), $case), self::CONTEXT);

            $this->assertFalse($result['success'], 'Expected a refusal for ' . json_encode($case));
        }

        $this->assertNull($store->created);
    }

    public function test_create_refuses_an_unknown_type_and_a_missing_project(): void
    {
        $store = new FakeEventStore();
        $store->seed(50, CalendarEventDefinition::TASK_TYPE, 'A task', array());
        $tool = new CreateEventTool($this->errors, $store);

        $type = $tool->execute(
            array('title' => 'Party', 'start_date' => '2026-02-01', 'type' => 'party'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $type['error']['code']);

        $project = $tool->execute(
            array('title' => 'Sync', 'start_date' => '2026-02-01', 'project_id' => 50),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $project['error']['code']);
    }

    public function test_create_replace_clears_omitted_fields(): void
    {
        $store = new FakeEventStore();
        $store->seed(
            5,
            CalendarEventDefinition::TYPE,
            'Old title',
            array(
                '_event_start_date' => '2026-02-01',
                '_event_end_date'   => '2026-02-01',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
                '_event_type'       => 'meeting',
                '_event_location'   => 'Room 1',
                '_event_attendees'  => array('7'),
                '_event_project_id' => 3,
            ),
            'Old notes'
        );

        $tool = new CreateEventTool($this->errors, $store);

        $result = $tool->execute(
            array(
                'event_id'   => 5,
                'title'      => 'New title',
                'start_date' => '2026-03-01',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);

        $meta = $store->items[5]->meta;
        $this->assertSame('2026-03-01', $meta['_event_start_date']);
        $this->assertSame('2026-03-01', $meta['_event_end_date']);
        $this->assertSame('', $meta['_event_start_time']);
        $this->assertSame('', $meta['_event_end_time']);
        $this->assertSame('1', $meta['_event_all_day']);
        $this->assertSame('', $meta['_event_location']);
        $this->assertSame(array(), $meta['_event_attendees']);
        $this->assertSame(0, $meta['_event_project_id']);
    }

    public function test_create_replace_honours_the_store_edit_rule(): void
    {
        $store = new FakeEventStore();
        $store->seed(5, CalendarEventDefinition::TYPE, 'Theirs', array('_event_start_date' => '2026-02-01'));
        $store->access[5] = array('edit' => false);

        $tool   = new CreateEventTool($this->errors, $store);
        $result = $tool->execute(
            array('event_id' => 5, 'title' => 'Mine', 'start_date' => '2026-02-01'),
            self::CONTEXT
        );

        $this->assertSame('forbidden', $result['error']['code']);
    }

    // ─── update_event ────────────────────────────────────────────────────

    public function test_update_changes_only_the_supplied_fields(): void
    {
        $store = new FakeEventStore();
        $store->seed(
            5,
            CalendarEventDefinition::TYPE,
            'Old title',
            array(
                '_event_start_date' => '2026-02-01',
                '_event_end_date'   => '2026-02-03',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
                '_event_type'       => 'deadline',
                '_event_location'   => 'Room 1',
                '_event_attendees'  => array('7'),
            ),
            'Notes'
        );

        $tool   = new UpdateEventTool($this->errors, $store);
        $result = $tool->execute(array('event_id' => 5, 'title' => 'New title'), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('New title', $store->items[5]->title);
        $this->assertSame('Notes', $store->items[5]->content);
        $this->assertSame('09:00', $store->items[5]->meta['_event_start_time']);
        $this->assertSame('10:00', $store->items[5]->meta['_event_end_time']);
        $this->assertSame('deadline', $store->items[5]->meta['_event_type']);
        $this->assertSame('Room 1', $store->items[5]->meta['_event_location']);
        $this->assertSame('0', $store->items[5]->meta['_event_all_day']);
    }

    public function test_update_clears_times_and_recomputes_all_day(): void
    {
        $store = new FakeEventStore();
        $store->seed(
            5,
            CalendarEventDefinition::TYPE,
            'Timed',
            array(
                '_event_start_date' => '2026-02-01',
                '_event_end_date'   => '2026-02-01',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
            )
        );

        $tool   = new UpdateEventTool($this->errors, $store);
        $result = $tool->execute(
            array('event_id' => 5, 'start_time' => '', 'end_time' => ''),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('', $store->items[5]->meta['_event_start_time']);
        $this->assertSame('1', $store->items[5]->meta['_event_all_day']);
        $this->assertTrue($result['data']['event']['all_day']);
    }

    public function test_update_refuses_an_incoherent_result(): void
    {
        $store = new FakeEventStore();
        $store->seed(
            5,
            CalendarEventDefinition::TYPE,
            'Timed',
            array(
                '_event_start_date' => '2026-02-01',
                '_event_end_date'   => '2026-02-01',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
            )
        );

        $tool   = new UpdateEventTool($this->errors, $store);
        $result = $tool->execute(array('event_id' => 5, 'start_date' => '2026-03-01'), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame('end_date', array_key_first($result['error']['data']));
    }

    public function test_update_refuses_an_unknown_type_and_a_non_list_attendees(): void
    {
        $store = new FakeEventStore();
        $store->seed(5, CalendarEventDefinition::TYPE, 'Event', array('_event_start_date' => '2026-02-01'));

        $tool = new UpdateEventTool($this->errors, $store);

        $type = $tool->execute(array('event_id' => 5, 'type' => 'party'), self::CONTEXT);
        $this->assertSame('validation_failed', $type['error']['code']);

        $attendees = $tool->execute(array('event_id' => 5, 'attendees' => 'me'), self::CONTEXT);
        $this->assertSame('validation_failed', $attendees['error']['code']);
    }

    // ─── delete_event ────────────────────────────────────────────────────

    public function test_delete_removes_the_event(): void
    {
        $store = new FakeEventStore();
        $store->seed(5, CalendarEventDefinition::TYPE, 'Event', array());

        $tool   = new DeleteEventTool($this->errors, $store);
        $result = $tool->execute(array('event_id' => 5), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['data']['event_id']);
        $this->assertSame(5, $store->deleted);
    }

    public function test_delete_refuses_a_wrong_type_and_lost_access(): void
    {
        $store = new FakeEventStore();
        $store->seed(5, CalendarEventDefinition::TASK_TYPE, 'Task', array());
        $store->seed(6, CalendarEventDefinition::TYPE, 'Event', array());
        $store->access[6] = array('delete' => false);

        $tool = new DeleteEventTool($this->errors, $store);

        $wrongType = $tool->execute(array('event_id' => 5), self::CONTEXT);
        $this->assertSame('invalid_event', $wrongType['error']['code']);

        $forbidden = $tool->execute(array('event_id' => 6), self::CONTEXT);
        $this->assertSame('forbidden', $forbidden['error']['code']);
    }

    // ─── list_events ─────────────────────────────────────────────────────

    public function test_list_sorts_by_start_date_and_time(): void
    {
        $store = $this->eventFixture();

        $tool   = new ListEventsTool($this->errors, $store);
        $result = $tool->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['data']['count']);
        $this->assertSame(
            array(11, 12, 10, 13),
            array_column($result['data']['events'], 'id')
        );
        $this->assertFalse($result['data']['truncated']);
    }

    public function test_list_filters_by_project_type_attendee_and_dates(): void
    {
        $store = $this->eventFixture();
        $tool  = new ListEventsTool($this->errors, $store);

        $byProject = $tool->execute(array('project_id' => 3), self::CONTEXT);
        $this->assertSame(array(12), array_column($byProject['data']['events'], 'id'));

        $byType = $tool->execute(array('type' => 'deadline'), self::CONTEXT);
        $this->assertSame(array(11), array_column($byType['data']['events'], 'id'));

        $byAttendee = $tool->execute(array('attendee' => 8), self::CONTEXT);
        $this->assertSame(array(12), array_column($byAttendee['data']['events'], 'id'));

        $byRange = $tool->execute(
            array('start_after' => '2026-02-02', 'start_before' => '2026-02-10'),
            self::CONTEXT
        );
        $this->assertSame(array(12, 10, 13), array_column($byRange['data']['events'], 'id'));

        $limited = $tool->execute(array('limit' => 2), self::CONTEXT);
        $this->assertSame(2, $limited['data']['count']);
        $this->assertSame(4, $limited['data']['total']);
    }

    public function test_list_refuses_a_reversed_range_and_an_unknown_type(): void
    {
        $tool = new ListEventsTool($this->errors, $this->eventFixture());

        $range = $tool->execute(
            array('start_after' => '2026-03-01', 'start_before' => '2026-02-01'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $range['error']['code']);

        $type = $tool->execute(array('type' => 'party'), self::CONTEXT);
        $this->assertSame('validation_failed', $type['error']['code']);
    }

    public function test_list_reports_truncation_beyond_the_scan_ceiling(): void
    {
        $store = new FakeEventStore();

        for ($id = 1; $id <= 1050; ++$id) {
            $store->seed(
                $id,
                CalendarEventDefinition::TYPE,
                'Event ' . $id,
                array('_event_start_date' => '2026-02-01')
            );
        }

        $tool   = new ListEventsTool($this->errors, $store);
        $result = $tool->execute(array('limit' => 10), self::CONTEXT);

        $this->assertSame(10, $result['data']['count']);
        $this->assertSame(1050, $result['data']['total']);
        $this->assertTrue($result['data']['truncated']);
    }

    // ─── get_calendar_view ───────────────────────────────────────────────

    public function test_calendar_view_merges_the_three_types_and_groups_by_date(): void
    {
        $store = $this->calendarFixture();
        $tool  = new GetCalendarViewTool($this->errors, $store);

        $result = $tool->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-02-28'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['count']);
        $this->assertArrayHasKey('calendar', $result['data']);
        $this->assertArrayHasKey('2026-01-01', $result['data']['calendar']);
        $this->assertArrayHasKey('2026-02-15', $result['data']['calendar']);
        $this->assertArrayHasKey('2026-02-20', $result['data']['calendar']);

        $types = array_column($result['data']['calendar']['2026-01-01'], 'type');
        $this->assertSame(array('project'), $types);
    }

    public function test_calendar_view_sees_items_that_span_the_window(): void
    {
        $store = $this->calendarFixture();
        $tool  = new GetCalendarViewTool($this->errors, $store);

        $result = $tool->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-02-07', 'group_by_date' => false),
            self::CONTEXT
        );

        $ids = array_column($result['data']['items'], 'id');

        $this->assertContains(100, $ids, 'A project spanning the window must be visible.');
    }

    public function test_calendar_view_filters_by_user_type_and_project(): void
    {
        $store = $this->calendarFixture();
        $tool  = new GetCalendarViewTool($this->errors, $store);

        $user = $tool->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'user_id' => 7),
            self::CONTEXT
        );
        $this->assertSame(3, $user['data']['count']);

        $nobody = $tool->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'user_id' => 99),
            self::CONTEXT
        );
        $this->assertSame(0, $nobody['data']['count']);

        $eventsOnly = $tool->execute(
            array(
                'start_date'    => '2026-02-01',
                'end_date'      => '2026-02-28',
                'include_types' => array('events'),
            ),
            self::CONTEXT
        );
        $this->assertSame(1, $eventsOnly['data']['count']);

        $project = $tool->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'project_id' => 100),
            self::CONTEXT
        );
        $this->assertSame(3, $project['data']['count'], 'The project itself plus its task and event.');
    }

    public function test_calendar_view_refuses_a_reversed_range_and_an_unknown_type(): void
    {
        $tool = new GetCalendarViewTool($this->errors, $this->calendarFixture());

        $range = $tool->execute(
            array('start_date' => '2026-03-01', 'end_date' => '2026-02-01'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $range['error']['code']);

        $types = $tool->execute(
            array(
                'start_date'    => '2026-02-01',
                'end_date'      => '2026-02-28',
                'include_types' => array('invoices'),
            ),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $types['error']['code']);
    }

    // ─── ICS writer ──────────────────────────────────────────────────────

    public function test_ics_writer_emits_a_valid_calendar(): void
    {
        $ics = IcsWriter::calendar(
            array(
                array(
                    'uid'         => 'event-1@nvoos',
                    'title'       => 'Review, plan; ship\\now',
                    'description' => "Line one\nLine two",
                    'location'    => 'Room 1',
                    'start'       => '2026-01-31 09:00:00',
                    'end'         => '2026-01-31 10:30:00',
                    'default_minutes' => 120,
                ),
                array(
                    'uid'     => 'task-2@nvoos',
                    'title'   => 'Due',
                    'start'   => '2026-02-10',
                    'end'     => '2026-02-10',
                    'all_day' => true,
                ),
            ),
            array(
                'calName'   => 'Project: Alpha',
                'timezone'  => 'Europe/London',
                'reminders' => true,
                'organizer' => array('name' => 'Ana', 'email' => 'ana@example.com'),
            ),
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'))
        );

        $this->assertStringStartsWith('BEGIN:VCALENDAR', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('PRODID:' . IcsWriter::PRODID, $ics);
        $this->assertStringContainsString('DTSTAMP:20260101T120000Z', $ics);
        $this->assertStringContainsString('DTSTART:20260131T090000Z', $ics);
        $this->assertStringContainsString('DTEND:20260131T103000Z', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260210', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260211', $ics);
        $this->assertStringContainsString('SUMMARY:Review\\, plan\\; ship\\\\now', $ics);
        $this->assertStringContainsString('DESCRIPTION:Line one\\nLine two', $ics);
        $this->assertStringContainsString('ORGANIZER;CN="Ana":mailto:ana@example.com', $ics);
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT15M', $ics);
        $this->assertStringContainsString('X-WR-TIMEZONE:Europe/London', $ics);
        $this->assertStringContainsString('X-WR-CALNAME:Project: Alpha', $ics);
    }

    public function test_ics_writer_folds_long_lines_on_utf8_boundaries(): void
    {
        $description = str_repeat('Événement — résumé, détail; ', 12);
        $ics         = IcsWriter::calendar(
            array(
                array(
                    'uid'         => 'event-9@nvoos',
                    'title'       => 'Long',
                    'description' => $description,
                    'start'       => '2026-01-31 09:00:00',
                ),
            )
        );

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'A content line exceeded 75 octets.');
        }

        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString('DESCRIPTION:', $unfolded);
        $this->assertSame(1, preg_match('//u', $unfolded), 'Folding split a UTF-8 character.');
    }

    // ─── export_calendar_ics ─────────────────────────────────────────────

    public function test_export_collects_the_project_tasks_and_events(): void
    {
        $store = $this->exportFixture();
        $tool  = new ExportCalendarIcsTool($this->errors, $store);

        $result = $tool->execute(
            array('project_id' => 10, 'download_file' => false),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['event_count']);
        $this->assertStringContainsString('UID:task-20@nvoos', $result['data']['ics_content']);
        $this->assertStringContainsString('UID:event-30@nvoos', $result['data']['ics_content']);
        $this->assertStringNotContainsString('UID:event-31@nvoos', $result['data']['ics_content']);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260210', $result['data']['ics_content']);
        $this->assertStringContainsString('DTSTART:20260220T090000Z', $result['data']['ics_content']);
    }

    public function test_export_stores_the_file_privately_by_default(): void
    {
        $store = $this->exportFixture();
        $files = new FakeEventFiles();
        $tool  = new ExportCalendarIcsTool($this->errors, $store, $files);

        $result = $tool->execute(array('project_id' => 10), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('text/calendar', $files->mime);
        $this->assertSame(FileVisibilityStoreInterface::VISIBILITY_PRIVATE, $files->visibility);
        $this->assertMatchesRegularExpression('/^project-10-\d{8}-\d{6}\.ics$/', (string) $files->filename);
        $this->assertStringContainsString('BEGIN:VCALENDAR', (string) $files->bytes);
        $this->assertSame('text/calendar', $result['data']['mime_type']);
        $this->assertGreaterThan(0, $result['data']['file_size']);
    }

    public function test_export_refuses_without_a_file_store_and_without_events(): void
    {
        $store = $this->exportFixture();

        $noStore = (new ExportCalendarIcsTool($this->errors, $store))->execute(
            array('project_id' => 10),
            self::CONTEXT
        );
        $this->assertSame('no_file_store', $noStore['error']['code']);

        $empty = $this->emptyProjectStore();
        $noEvents = (new ExportCalendarIcsTool($this->errors, $empty))->execute(
            array('project_id' => 10, 'download_file' => false),
            self::CONTEXT
        );
        $this->assertSame('no_events', $noEvents['error']['code']);
    }

    public function test_export_refuses_a_bad_timezone_and_organizer_email(): void
    {
        $tool = new ExportCalendarIcsTool($this->errors, $this->exportFixture());

        $timezone = $tool->execute(
            array('project_id' => 10, 'download_file' => false, 'timezone' => 'Mars/Olympus'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $timezone['error']['code']);

        $email = $tool->execute(
            array('project_id' => 10, 'download_file' => false, 'organizer_email' => 'not-an-email'),
            self::CONTEXT
        );
        $this->assertSame('validation_failed', $email['error']['code']);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * Four events in a known order, one with a project and one with an attendee.
     */
    private function eventFixture(): FakeEventStore
    {
        $store = new FakeEventStore();

        $store->seed(10, CalendarEventDefinition::TYPE, 'D', array('_event_start_date' => '2026-02-03', '_event_start_time' => '09:00'));
        $store->seed(11, CalendarEventDefinition::TYPE, 'A', array('_event_start_date' => '2026-02-01', '_event_start_time' => '', '_event_type' => 'deadline'));
        $store->seed(12, CalendarEventDefinition::TYPE, 'B', array('_event_start_date' => '2026-02-02', '_event_start_time' => '08:00', '_event_project_id' => 3, '_event_attendees' => array('8')));
        $store->seed(13, CalendarEventDefinition::TYPE, 'E', array('_event_start_date' => '2026-02-04', '_event_start_time' => '10:00', '_event_type' => 'milestone'));
        $store->seed(14, CalendarEventDefinition::TASK_TYPE, 'Not an event', array('_event_start_date' => '2026-02-01'));

        return $store;
    }

    /**
     * A project spanning the year, a task and an event inside February.
     */
    private function calendarFixture(): FakeEventStore
    {
        $store = new FakeEventStore();

        $store->seed(
            100,
            CalendarEventDefinition::PROJECT_TYPE,
            'Alpha',
            array(
                '_project_start_date'  => '2026-01-01',
                '_project_end_date'    => '2026-12-31',
                '_project_status'      => 'active',
                '_project_assigned_to' => array('7'),
            )
        );

        $store->seed(
            200,
            CalendarEventDefinition::TASK_TYPE,
            'Design',
            array(
                '_task_due_date'    => '2026-02-15',
                '_task_status'      => 'todo',
                '_task_priority'    => 'high',
                '_task_project_id'  => 100,
                '_task_assigned_to' => 7,
            )
        );

        $store->seed(
            300,
            CalendarEventDefinition::TYPE,
            'Review',
            array(
                '_event_start_date' => '2026-02-20',
                '_event_end_date'   => '2026-02-20',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
                '_event_type'       => 'meeting',
                '_event_project_id' => 100,
                '_event_attendees'  => array('7'),
            )
        );

        return $store;
    }

    /**
     * Project 10 with a task and an event, plus an event in another project.
     */
    private function exportFixture(): FakeEventStore
    {
        $store = new FakeEventStore();

        $store->seed(
            10,
            CalendarEventDefinition::PROJECT_TYPE,
            'Alpha',
            array('_project_start_date' => '2026-01-01', '_project_end_date' => '2026-12-31')
        );

        $store->seed(
            20,
            CalendarEventDefinition::TASK_TYPE,
            'Draft the brief',
            array('_task_due_date' => '2026-02-10', '_task_project_id' => 10)
        );

        $store->seed(
            30,
            CalendarEventDefinition::TYPE,
            'Kickoff',
            array(
                '_event_start_date' => '2026-02-20',
                '_event_end_date'   => '2026-02-20',
                '_event_start_time' => '09:00',
                '_event_end_time'   => '10:00',
                '_event_all_day'    => '0',
                '_event_project_id' => 10,
                '_event_location'   => 'Room 1',
            ),
            'Agenda'
        );

        $store->seed(
            31,
            CalendarEventDefinition::TYPE,
            'Other project',
            array(
                '_event_start_date' => '2026-02-21',
                '_event_end_date'   => '2026-02-21',
                '_event_project_id' => 11,
            )
        );

        return $store;
    }

    private function emptyProjectStore(): FakeEventStore
    {
        $store = new FakeEventStore();
        $store->seed(10, CalendarEventDefinition::PROJECT_TYPE, 'Empty', array());

        return $store;
    }
}
