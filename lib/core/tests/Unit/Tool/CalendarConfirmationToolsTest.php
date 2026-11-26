<?php

/**
 * Tests for the booking-confirmation tools and the shared notification rules.
 *
 * Proposal 164 section 164.88. The cases pin the four decisions that separate
 * this port from WordPress: a failed send is an error rather than a success
 * with a false flag, `include_ical` actually attaches an RFC 5545 invitation,
 * the send is idempotent per revision, and the batch reads the meta keys the
 * writers store.
 *
 * Every asserted value is derived from the fixture or from RFC 5545, not from
 * the implementation.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\CalendarNotification;
use Nvoos\Core\Tool\Calendar\SendBookingConfirmationTool;
use Nvoos\Core\Tool\Calendar\SendBookingConfirmationsTool;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use Nvoos\Core\Tests\Support\FakeEmailService;
use PHPUnit\Framework\TestCase;

final class CalendarConfirmationToolsTest extends TestCase
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

    // ─── send_booking_confirmation ───────────────────────────────────────

    public function test_a_confirmation_sends_and_stamps(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('ada@example.test', $mail->sent[0]['to']);
        $this->assertSame('Appointment Confirmation #101', $mail->sent[0]['subject']);
        $this->assertStringContainsString(
            '2026-03-02T09:00:00+00:00',
            $mail->sent[0]['body']
        );

        $this->assertCount(1, $mail->sent[0]['attachments']);
        $this->assertSame('appointment-101.ics', $mail->sent[0]['attachments'][0]['name']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $mail->sent[0]['attachments'][0]['content']);
        $this->assertStringContainsString('METHOD:PUBLISH', $mail->sent[0]['attachments'][0]['content']);

        $this->assertTrue($result['data']['sent']);
        $this->assertTrue($result['data']['ical_included']);
        $this->assertSame(0, $result['data']['revision']);

        $meta = $store->items[101]->meta;

        $this->assertArrayHasKey('_confirmation_sent_at', $meta);
        $this->assertCount(1, $meta[ CalendarNotification::META_LOG ]);
        $this->assertSame('confirmation', $meta[ CalendarNotification::META_LOG ][0]['kind']);
        $this->assertSame(0, $meta[ CalendarNotification::META_LOG ][0]['revision']);
    }

    public function test_the_invite_is_a_request_when_an_organizer_is_given(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array(
                'appointment_id'  => 101,
                'organizer_email' => 'clinic@example.test',
            ),
            self::CONTEXT
        );

        $ics = $mail->sent[0]['attachments'][0]['content'];

        $this->assertStringContainsString('METHOD:REQUEST', $ics);
        $this->assertStringContainsString('ORGANIZER:mailto:clinic@example.test', $ics);
        $this->assertStringContainsString(
            'ATTENDEE;CN="Ada Lovelace";RSVP=TRUE:mailto:ada@example.test',
            $ics
        );
        $this->assertStringContainsString('SEQUENCE:0', $ics);
        $this->assertStringContainsString('UID:appointment-101@nvoos.invalid', $ics);
    }

    public function test_a_reschedule_bumps_the_sequence(): void
    {
        $store = new FakeCalendarStore();
        $this->seed(
            $store,
            101,
            array(
                '_activity_log' => array(
                    array('action' => 'rescheduled', 'new_start' => '2026-03-02 11:00:00'),
                ),
            )
        );

        $mail = new FakeEmailService();
        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array('appointment_id' => 101),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['revision']);
        $this->assertStringContainsString('SEQUENCE:1', $mail->sent[0]['attachments'][0]['content']);
    }

    public function test_the_uid_uses_the_host_when_one_is_bound(): void
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(
            array(
                'name'      => 'test',
                'version'   => '1.0.0',
                'runtime'   => 'laravel',
                'contract'  => 'mcp-ai/v1',
                'site_name' => 'Clinic',
                'site_url'  => 'https://clinic.example/',
                'api_base'  => 'https://clinic.example/api',
            )
        );

        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        ( new SendBookingConfirmationTool($this->errors, $store, $mail, $identity) )->execute(
            array('appointment_id' => 101),
            self::CONTEXT
        );

        $this->assertStringContainsString(
            'UID:appointment-101@clinic.example',
            $mail->sent[0]['attachments'][0]['content']
        );
    }

    public function test_a_duplicate_confirmation_is_suppressed(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        $tool = new SendBookingConfirmationTool($this->errors, $store, $mail);

        $tool->execute(array('appointment_id' => 101), self::CONTEXT);
        $second = $tool->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertTrue($second['success']);
        $this->assertTrue($second['data']['already_sent']);
        $this->assertFalse($second['data']['sent']);
        $this->assertCount(1, $mail->sent);
        $this->assertCount(1, $store->items[101]->meta[ CalendarNotification::META_LOG ]);
    }

    public function test_resend_overrides_the_log(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        $tool = new SendBookingConfirmationTool($this->errors, $store, $mail);

        $tool->execute(array('appointment_id' => 101), self::CONTEXT);
        $second = $tool->execute(array('appointment_id' => 101, 'resend' => true), self::CONTEXT);

        $this->assertTrue($second['data']['sent']);
        $this->assertCount(2, $mail->sent);
    }

    public function test_a_failed_send_is_an_error_and_is_not_logged(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail         = new FakeEmailService();
        $mail->succeed = false;

        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertSame('email_failed', $result['error']['code']);
        $this->assertArrayNotHasKey('_confirmation_sent_at', $store->items[101]->meta);
        $this->assertArrayNotHasKey(CalendarNotification::META_LOG, $store->items[101]->meta);
    }

    public function test_a_missing_recipient_is_refused(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101, array('_client_email' => ''));

        $result = ( new SendBookingConfirmationTool($this->errors, $store, new FakeEmailService()) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertSame('missing_recipient', $result['error']['code']);
    }

    public function test_an_unavailable_transport_is_refused(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail            = new FakeEmailService();
        $mail->available = false;

        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertSame('mail_unavailable', $result['error']['code']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array('appointment_id' => 101, 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertFalse($result['data']['sent']);
        $this->assertStringContainsString('Your appointment is confirmed.', $result['data']['body']);
        $this->assertSame(array(), $mail->sent);
        $this->assertArrayNotHasKey('_confirmation_sent_at', $store->items[101]->meta);
    }

    public function test_include_ical_false_omits_the_attachment(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array('appointment_id' => 101, 'include_ical' => false),
            self::CONTEXT
        );

        $this->assertFalse($result['data']['ical_included']);
        $this->assertArrayNotHasKey('attachments', $mail->sent[0]);
    }

    public function test_an_appointment_without_a_start_sends_without_an_invite(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101, array('_start_time' => '', '_end_time' => ''));

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array('appointment_id' => 101),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['sent']);
        $this->assertFalse($result['data']['ical_included']);
        $this->assertArrayNotHasKey('attachments', $mail->sent[0]);
    }

    public function test_the_custom_message_reaches_the_body_and_the_invite(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        ( new SendBookingConfirmationTool($this->errors, $store, $mail) )->execute(
            array(
                'appointment_id' => 101,
                'custom_message' => 'Please arrive 10 minutes early.',
            ),
            self::CONTEXT
        );

        $this->assertStringContainsString('Please arrive 10 minutes early.', $mail->sent[0]['body']);
        $this->assertStringContainsString(
            'DESCRIPTION:Please arrive 10 minutes early.',
            $mail->sent[0]['attachments'][0]['content']
        );
    }

    public function test_an_overlong_custom_message_is_refused(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $result = ( new SendBookingConfirmationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array(
                'appointment_id' => 101,
                'custom_message' => str_repeat('x', CalendarNotification::MAX_CUSTOM_MESSAGE + 1),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('custom_message', $result['error']['data']);
    }

    public function test_an_invalid_organizer_is_refused(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $result = ( new SendBookingConfirmationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_id' => 101, 'organizer_email' => 'not-an-address'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('organizer_email', $result['error']['data']);
    }

    public function test_confirmation_requires_an_administrator(): void
    {
        $this->expectException(AccessDeniedException::class);

        ( new SendBookingConfirmationTool($this->errors, new FakeCalendarStore(), new FakeEmailService()) )
            ->execute(array('appointment_id' => 101), array('user_id' => 0));
    }

    // ─── send_booking_confirmations ──────────────────────────────────────

    public function test_a_batch_dry_run_previews_by_default(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed($store, 102, array('_client_email' => 'grace@example.test'));

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationsTool($this->errors, $store, $mail) )->execute(
            array('booking_ids' => array(101, 102)),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame('preview', $result['data']['recipients'][0]['status']);
        $this->assertSame(array(), $mail->sent);
    }

    public function test_a_batch_sends_and_stamps_each_booking(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed($store, 102, array('_client_email' => 'grace@example.test'));

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationsTool($this->errors, $store, $mail) )->execute(
            array('booking_ids' => array(101, 102), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame(0, $result['data']['failed']);
        $this->assertCount(2, $mail->sent);
        $this->assertSame('email', $store->items[101]->meta['_confirmation_method']);
        $this->assertArrayHasKey('_confirmation_sent_at', $store->items[102]->meta);
        $this->assertCount(1, $mail->sent[0]['attachments']);
    }

    public function test_a_batch_skips_missing_and_unreachable_bookings(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed($store, 102, array('_client_email' => ''));

        $mail   = new FakeEmailService();
        $result = ( new SendBookingConfirmationsTool($this->errors, $store, $mail) )->execute(
            array(
                'booking_ids' => array(101, 102, 999),
                'dry_run'     => false,
            ),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['processed']);
        $this->assertSame(2, $result['data']['skipped']);
        $this->assertCount(2, $result['data']['errors']);
        $this->assertCount(1, $mail->sent);
    }

    public function test_a_second_batch_reports_already_sent_without_failing(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed($store, 102, array('_client_email' => 'grace@example.test'));

        $mail = new FakeEmailService();
        $tool = new SendBookingConfirmationsTool($this->errors, $store, $mail);

        $tool->execute(array('booking_ids' => array(101, 102), 'dry_run' => false), self::CONTEXT);
        $second = $tool->execute(array('booking_ids' => array(101, 102), 'dry_run' => false), self::CONTEXT);

        $this->assertTrue($second['success']);
        $this->assertSame(0, $second['data']['processed']);
        $this->assertSame(2, $second['data']['already_sent']);
        $this->assertCount(2, $mail->sent);
    }

    public function test_a_batch_where_every_send_fails_is_an_error(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed($store, 102, array('_client_email' => 'grace@example.test'));

        $mail         = new FakeEmailService();
        $mail->succeed = false;

        $result = ( new SendBookingConfirmationsTool($this->errors, $store, $mail) )->execute(
            array('booking_ids' => array(101, 102), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertSame('delivery_failed', $result['error']['code']);
        $this->assertCount(2, $result['error']['data']['recipients']);
        $this->assertSame(2, $result['error']['data']['failed']);
    }

    public function test_a_batch_reads_the_appointment_type_and_ignores_the_dead_key(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);
        $this->seed(
            $store,
            102,
            array(
                '_appointment_type' => '',
                '_service_type'     => 'Massage',
            )
        );

        $result = ( new SendBookingConfirmationsTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('booking_ids' => array(101, 102), 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertSame('Consultation', $result['data']['recipients'][0]['appointment_type']);
        $this->assertSame('', $result['data']['recipients'][1]['appointment_type']);
    }

    public function test_a_batch_refuses_sms(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $result = ( new SendBookingConfirmationsTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('booking_ids' => array(101), 'method' => 'sms'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('method', $result['error']['data']);
    }

    public function test_a_batch_refuses_more_than_the_ceiling(): void
    {
        $result = ( new SendBookingConfirmationsTool($this->errors, new FakeCalendarStore(), new FakeEmailService()) )
            ->execute(
                array('booking_ids' => range(1, SendBookingConfirmationsTool::MAX_BATCH + 1)),
                self::CONTEXT
            );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('booking_ids', $result['error']['data']);
    }

    public function test_a_batch_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        ( new SendBookingConfirmationsTool($this->errors, new FakeCalendarStore(), new FakeEmailService()) )
            ->execute(array('booking_ids' => array(101)), array('user_id' => 0));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta Overrides.
     */
    private function seed(FakeCalendarStore $store, int $id, array $meta = array()): ContentItem
    {
        return $store->seed(
            $id,
            AppointmentDefinition::TYPE,
            'Appointment',
            array_merge(
                array(
                    '_client_name'      => 'Ada Lovelace',
                    '_client_email'     => 'ada@example.test',
                    '_start_time'       => '2026-03-02 09:00:00',
                    '_end_time'         => '2026-03-02 10:00:00',
                    '_appointment_type' => 'Consultation',
                    '_status'           => 'confirmed',
                ),
                $meta
            )
        );
    }
}
