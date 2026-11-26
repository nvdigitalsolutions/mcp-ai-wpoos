<?php

/**
 * The appointment triage readers, and the confirmation stamp they depend on.
 *
 * Pinned behaviours:
 *
 * - Both readers use the keys the writers store: `_status` with `no-show` and
 *   `pending`, `_start_time`, `_appointment_type`, and the record content for
 *   notes. A record carrying the old WordPress keys is not matched.
 * - The date window includes the whole of both boundary days, and an invalid or
 *   reversed window is refused.
 * - No-show results are newest first; unconfirmed results are oldest first.
 * - "Unconfirmed" means pending with no `_confirmation_sent_at`; a pending
 *   record with a stamp is excluded, and a confirmed record is never included.
 * - `create_appointment` stamps the confirmation time only when the transport
 *   accepted the message, so a booking confirmed at creation is not listed.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use Nvoos\Core\Tests\Support\FakeEmailService;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\AppointmentTriage;
use Nvoos\Core\Tool\Calendar\CreateAppointmentTool;
use Nvoos\Core\Tool\Calendar\GetNoShowAppointmentsTool;
use Nvoos\Core\Tool\Calendar\GetUnconfirmedBookingsTool;
use PHPUnit\Framework\TestCase;

final class CalendarTriageToolsTest extends TestCase
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

    // ─── get_no_show_appointments ────────────────────────────────────────

    public function test_no_show_reads_the_stored_status_newest_first(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(1, AppointmentDefinition::TYPE, 'A', array('_status' => 'no-show', '_start_time' => '2026-02-01 09:00:00'));
        $store->seed(2, AppointmentDefinition::TYPE, 'B', array('_status' => 'no-show', '_start_time' => '2026-02-03 09:00:00'));
        $store->seed(3, AppointmentDefinition::TYPE, 'C', array('_status' => 'confirmed', '_start_time' => '2026-02-02 09:00:00'));
        $store->seed(4, AppointmentDefinition::TYPE, 'D', array('_status' => 'pending', '_start_time' => '2026-02-04 09:00:00'));

        // The old WordPress keys must not match: the writers never produce them.
        $store->seed(5, AppointmentDefinition::TYPE, 'Old', array('_appointment_status' => 'no_show', '_start_time' => '2026-02-05 09:00:00'));

        $result = (new GetNoShowAppointmentsTool($this->errors, $store))->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(array(2, 1), array_column($result['data']['appointments'], 'id'));
        $this->assertSame(array('no-show', 'no-show'), array_column($result['data']['appointments'], 'status'));
    }

    public function test_no_show_row_uses_the_writer_keys(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(
            7,
            AppointmentDefinition::TYPE,
            'Appointment: Ana - 2026-02-05 09:00:00',
            array(
                '_client_name'      => 'Ana',
                '_client_email'     => 'ana@example.com',
                '_client_phone'     => '555-0100',
                '_start_time'       => '2026-02-05 09:00:00',
                '_end_time'         => '2026-02-05 10:00:00',
                '_appointment_type' => 'consult',
                '_status'           => 'no-show',
            ),
            'Bring notes'
        );

        $result = (new GetNoShowAppointmentsTool($this->errors, $store))->execute(array(), self::CONTEXT);
        $row    = $result['data']['appointments'][0];

        $this->assertSame(7, $row['id']);
        $this->assertSame('Ana', $row['client_name']);
        $this->assertSame('555-0100', $row['client_phone']);
        $this->assertSame('2026-02-05', $row['appointment_date']);
        $this->assertSame('2026-02-05 09:00:00', $row['start_time']);
        $this->assertSame('2026-02-05T09:00:00+00:00', $row['start_time_iso']);
        $this->assertSame('2026-02-05T10:00:00+00:00', $row['end_time_iso']);
        $this->assertSame('consult', $row['service_type']);
        $this->assertSame('no-show', $row['status']);
        $this->assertSame('Bring notes', $row['notes']);
    }

    public function test_no_show_filters_by_window_and_type(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(10, AppointmentDefinition::TYPE, 'A', array('_status' => 'no-show', '_start_time' => '2026-02-01 09:00:00', '_appointment_type' => 'consult'));
        $store->seed(11, AppointmentDefinition::TYPE, 'B', array('_status' => 'no-show', '_start_time' => '2026-02-05 08:00:00', '_appointment_type' => 'general'));
        $store->seed(12, AppointmentDefinition::TYPE, 'C', array('_status' => 'no-show', '_start_time' => '2026-02-05 09:00:00', '_appointment_type' => 'consult'));
        $store->seed(13, AppointmentDefinition::TYPE, 'D', array('_status' => 'no-show', '_start_time' => '2026-02-10 09:00:00', '_appointment_type' => 'consult'));
        $store->seed(14, AppointmentDefinition::TYPE, 'E', array('_status' => 'no-show', '_start_time' => '2026-02-05 23:30:00', '_appointment_type' => 'consult'));
        $store->seed(15, AppointmentDefinition::TYPE, 'F', array('_status' => 'no-show', '_start_time' => '2026-02-05 00:00:00', '_appointment_type' => 'consult'));

        $tool   = new GetNoShowAppointmentsTool($this->errors, $store);
        $window = $tool->execute(
            array('date_from' => '2026-02-05', 'date_to' => '2026-02-05'),
            self::CONTEXT
        );

        $this->assertSame(array(14, 12, 11, 15), array_column($window['data']['appointments'], 'id'));

        $type = $tool->execute(array('service_type' => 'Consult'), self::CONTEXT);
        $this->assertSame(array(13, 14, 12, 15, 10), array_column($type['data']['appointments'], 'id'));

        $from = $tool->execute(array('date_from' => '2026-02-06'), self::CONTEXT);
        $this->assertSame(array(13), array_column($from['data']['appointments'], 'id'));

        $to = $tool->execute(array('date_to' => '2026-02-04'), self::CONTEXT);
        $this->assertSame(array(10), array_column($to['data']['appointments'], 'id'));
    }

    public function test_no_show_refuses_invalid_and_reversed_dates(): void
    {
        $tool = new GetNoShowAppointmentsTool($this->errors, new FakeCalendarStore());

        $invalid = $tool->execute(array('date_from' => '2026-02-31'), self::CONTEXT);
        $this->assertSame('validation_failed', $invalid['error']['code']);
        $this->assertSame('date_from', array_key_first($invalid['error']['data']));

        $garbage = $tool->execute(array('date_to' => 'soon'), self::CONTEXT);
        $this->assertSame('date_to', array_key_first($garbage['error']['data']));

        $reversed = $tool->execute(
            array('date_from' => '2026-03-01', 'date_to' => '2026-02-01'),
            self::CONTEXT
        );
        $this->assertSame('date_from', array_key_first($reversed['error']['data']));
    }

    // ─── get_unconfirmed_bookings ────────────────────────────────────────

    public function test_unconfirmed_lists_pending_without_a_confirmation_stamp(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(1, AppointmentDefinition::TYPE, 'A', array('_status' => 'pending', '_start_time' => '2026-02-01 09:00:00'));
        $store->seed(2, AppointmentDefinition::TYPE, 'B', array('_status' => 'pending', '_start_time' => '2026-02-02 09:00:00', '_confirmation_sent_at' => '2026-01-01 10:00:00'));
        $store->seed(3, AppointmentDefinition::TYPE, 'C', array('_status' => 'confirmed', '_start_time' => '2026-02-03 09:00:00'));
        $store->seed(4, AppointmentDefinition::TYPE, 'D', array('_status' => 'pending', '_start_time' => '2026-02-04 09:00:00'));
        $store->seed(5, AppointmentDefinition::TYPE, 'E', array('_status' => 'no-show', '_start_time' => '2026-02-05 09:00:00'));

        $result = (new GetUnconfirmedBookingsTool($this->errors, $store))->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(array(1, 4), array_column($result['data']['bookings'], 'id'));
        $this->assertSame(array('pending', 'pending'), array_column($result['data']['bookings'], 'status'));
    }

    public function test_unconfirmed_filters_and_limits(): void
    {
        $store = new FakeCalendarStore();
        $store->seed(1, AppointmentDefinition::TYPE, 'A', array('_status' => 'pending', '_start_time' => '2026-02-01 09:00:00', '_appointment_type' => 'consult'));
        $store->seed(2, AppointmentDefinition::TYPE, 'B', array('_status' => 'pending', '_start_time' => '2026-02-02 09:00:00', '_appointment_type' => 'general'));
        $store->seed(3, AppointmentDefinition::TYPE, 'C', array('_status' => 'pending', '_start_time' => '2026-02-03 09:00:00', '_appointment_type' => 'consult'));

        $tool = new GetUnconfirmedBookingsTool($this->errors, $store);

        $typed = $tool->execute(array('service_type' => 'consult'), self::CONTEXT);
        $this->assertSame(array(1, 3), array_column($typed['data']['bookings'], 'id'));

        $limited = $tool->execute(array('limit' => 2), self::CONTEXT);
        $this->assertSame(2, $limited['data']['count']);
        $this->assertSame(3, $limited['data']['total']);
        $this->assertFalse($limited['data']['truncated']);
    }

    // ─── the confirmation stamp ──────────────────────────────────────────

    public function test_create_appointment_stamps_the_confirmation_when_sent(): void
    {
        $store = new FakeCalendarStore();
        $email = new FakeEmailService();
        $tool  = new CreateAppointmentTool($this->errors, $store, $email);

        $result = $tool->execute(
            array(
                'client_name'       => 'Ana',
                'client_email'      => 'ana@example.com',
                'start_time'        => '2026-03-01 09:00:00',
                'send_notification' => true,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['notification_sent']);
        $this->assertCount(1, $email->sent);

        $id = $result['data']['appointment_id'];
        $this->assertArrayHasKey('_confirmation_sent_at', $store->items[ $id ]->meta);
        $this->assertSame(
            1,
            preg_match(
                '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                $store->items[ $id ]->meta['_confirmation_sent_at']
            )
        );
    }

    public function test_create_appointment_does_not_stamp_without_a_delivery(): void
    {
        $store = new FakeCalendarStore();
        $tool  = new CreateAppointmentTool($this->errors, $store);

        $result = $tool->execute(
            array(
                'client_name'       => 'Ana',
                'client_email'      => 'ana@example.com',
                'start_time'        => '2026-03-01 09:00:00',
                'send_notification' => true,
            ),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['notification_sent']);
        $this->assertArrayHasKey('notification_error', $result['data']);

        $id = $result['data']['appointment_id'];
        $this->assertArrayNotHasKey('_confirmation_sent_at', $store->items[ $id ]->meta);
    }

    public function test_create_appointment_does_not_stamp_when_the_transport_refuses(): void
    {
        $store          = new FakeCalendarStore();
        $email          = new FakeEmailService();
        $email->succeed = false;
        $tool           = new CreateAppointmentTool($this->errors, $store, $email);

        $result = $tool->execute(
            array(
                'client_name'       => 'Ana',
                'client_email'      => 'ana@example.com',
                'start_time'        => '2026-03-01 09:00:00',
                'send_notification' => true,
            ),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['notification_sent']);
        $this->assertArrayNotHasKey(
            '_confirmation_sent_at',
            $store->items[ $result['data']['appointment_id'] ]->meta
        );
    }

    // ─── shared rules ────────────────────────────────────────────────────

    public function test_window_includes_whole_boundary_days(): void
    {
        $window = AppointmentTriage::window('2026-02-05', '2026-02-05');

        $this->assertNull($window['error']);
        $this->assertSame('2026-02-05 00:00:00', $window['clauses'][0]['value']);
        $this->assertSame('2026-02-05 23:59:59', $window['clauses'][1]['value']);
    }

    public function test_window_treats_absent_bounds_as_open(): void
    {
        $window = AppointmentTriage::window(null, null);

        $this->assertSame(array(), $window['clauses']);
        $this->assertNull($window['error']);
    }
}
