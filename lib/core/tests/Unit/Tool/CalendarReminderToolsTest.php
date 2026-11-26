<?php

/**
 * Tests for the reminder and reschedule-invitation tools.
 *
 * Proposal 164 section 164.88. The cases pin the corrections that matter:
 * `reminder_type` is refused rather than silently downgraded to email,
 * `hours_before` identifies the reminder, and the reschedule gate reads
 * `_status` with the hyphenated `no-show` the writers store instead of the
 * `_appointment_status` key nothing ever wrote.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\CalendarNotification;
use Nvoos\Core\Tool\Calendar\SendAppointmentReminderTool;
use Nvoos\Core\Tool\Calendar\SendRescheduleInvitationTool;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use Nvoos\Core\Tests\Support\FakeEmailService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarReminderToolsTest extends TestCase
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

    // ─── send_appointment_reminder ───────────────────────────────────────

    public function test_a_reminder_sends_and_records_both_logs(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail   = new FakeEmailService();
        $result = ( new SendAppointmentReminderTool($this->errors, $store, $mail) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('ada@example.test', $mail->sent[0]['to']);
        $this->assertSame('Appointment Reminder #101', $mail->sent[0]['subject']);
        $this->assertStringContainsString('2026-03-02T09:00:00+00:00', $mail->sent[0]['body']);
        $this->assertArrayNotHasKey('attachments', $mail->sent[0]);

        $meta = $store->items[101]->meta;

        $this->assertSame(24, $meta['_reminders_sent'][0]['hours_before']);
        $this->assertSame('email', $meta['_reminders_sent'][0]['type']);
        $this->assertSame('reminder:24', $meta[ CalendarNotification::META_LOG ][0]['kind']);
        $this->assertTrue($result['data']['sent']);
    }

    public function test_a_reminder_is_suppressed_per_hours_before(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        $tool = new SendAppointmentReminderTool($this->errors, $store, $mail);

        $first  = $tool->execute(array('appointment_id' => 101, 'hours_before' => 24), self::CONTEXT);
        $second = $tool->execute(array('appointment_id' => 101, 'hours_before' => 24), self::CONTEXT);
        $third  = $tool->execute(array('appointment_id' => 101, 'hours_before' => 2), self::CONTEXT);

        $this->assertTrue($first['data']['sent']);
        $this->assertTrue($second['data']['already_sent']);
        $this->assertTrue($third['data']['sent']);
        $this->assertCount(2, $mail->sent);
        $this->assertCount(2, $store->items[101]->meta[ CalendarNotification::META_LOG ]);
    }

    public function test_a_reminder_resend_overrides_the_log(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail = new FakeEmailService();
        $tool = new SendAppointmentReminderTool($this->errors, $store, $mail);

        $tool->execute(array('appointment_id' => 101), self::CONTEXT);
        $second = $tool->execute(array('appointment_id' => 101, 'resend' => true), self::CONTEXT);

        $this->assertTrue($second['data']['sent']);
        $this->assertCount(2, $mail->sent);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function smsTypes(): array
    {
        return array(
            'sms'  => array('sms'),
            'both' => array('both'),
        );
    }

    /**
     * @dataProvider smsTypes
     */
    public function test_a_reminder_refuses_sms(string $type): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $result = ( new SendAppointmentReminderTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_id' => 101, 'reminder_type' => $type),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('reminder_type', $result['error']['data']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function badHours(): array
    {
        return array(
            'zero'      => array(0),
            'negative'  => array(-4),
            'beyond 30 days' => array(SendAppointmentReminderTool::MAX_HOURS_BEFORE + 1),
        );
    }

    /**
     * @dataProvider badHours
     */
    public function test_hours_before_is_bounded(int $hours): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $result = ( new SendAppointmentReminderTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_id' => 101, 'hours_before' => $hours),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('hours_before', $result['error']['data']);
    }

    public function test_a_failed_reminder_is_an_error_and_is_not_logged(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail         = new FakeEmailService();
        $mail->succeed = false;

        $result = ( new SendAppointmentReminderTool($this->errors, $store, $mail) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertSame('email_failed', $result['error']['code']);
        $this->assertArrayNotHasKey('_reminders_sent', $store->items[101]->meta);
        $this->assertArrayNotHasKey(CalendarNotification::META_LOG, $store->items[101]->meta);
    }

    public function test_a_reminder_dry_run_previews(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101);

        $mail   = new FakeEmailService();
        $result = ( new SendAppointmentReminderTool($this->errors, $store, $mail) )->execute(
            array('appointment_id' => 101, 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(array(), $mail->sent);
        $this->assertStringContainsString('reminder about your upcoming appointment', $result['data']['body']);
    }

    public function test_a_reminder_requires_an_administrator(): void
    {
        $this->expectException(AccessDeniedException::class);

        ( new SendAppointmentReminderTool($this->errors, new FakeCalendarStore(), new FakeEmailService()) )
            ->execute(array('appointment_id' => 101), array('user_id' => 0));
    }

    public function test_a_reminder_needs_a_recipient(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 101, array('_client_email' => ''));

        $result = ( new SendAppointmentReminderTool($this->errors, $store, new FakeEmailService()) )
            ->execute(array('appointment_id' => 101), self::CONTEXT);

        $this->assertSame('missing_recipient', $result['error']['code']);
    }

    // ─── send_reschedule_invitation ──────────────────────────────────────

    public function test_a_no_show_is_invited_and_stamped(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));

        $mail   = new FakeEmailService();
        $result = ( new SendRescheduleInvitationTool($this->errors, $store, $mail) )->execute(
            array('appointment_ids' => array(201), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['processed']);
        $this->assertSame('sent', $result['data']['recipients'][0]['status']);
        $this->assertStringContainsString('missed your appointment', $mail->sent[0]['body']);
        $this->assertArrayHasKey('_reschedule_invitation_sent_at', $store->items[201]->meta);
        $this->assertSame(
            'reschedule_invitation',
            $store->items[201]->meta[ CalendarNotification::META_LOG ][0]['kind']
        );
    }

    public function test_a_confirmed_appointment_is_skipped(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201);

        $mail   = new FakeEmailService();
        $result = ( new SendRescheduleInvitationTool($this->errors, $store, $mail) )->execute(
            array('appointment_ids' => array(201), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertSame(0, $result['data']['processed']);
        $this->assertSame(1, $result['data']['skipped']);
        $this->assertStringContainsString('current status: confirmed', $result['data']['errors'][0]['error']);
        $this->assertSame(array(), $mail->sent);
    }

    public function test_the_dead_status_key_does_not_qualify(): void
    {
        $store = new FakeCalendarStore();
        $this->seed(
            $store,
            201,
            array(
                '_status'            => 'confirmed',
                '_appointment_status' => 'no_show',
            )
        );

        $result = ( new SendRescheduleInvitationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_ids' => array(201), 'dry_run' => true),
            self::CONTEXT
        );

        $this->assertSame(0, $result['data']['processed']);
        $this->assertSame(1, $result['data']['skipped']);
    }

    public function test_the_legacy_underscore_status_qualifies(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no_show'));

        $result = ( new SendRescheduleInvitationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_ids' => array(201), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertSame(1, $result['data']['processed']);
    }

    public function test_a_reschedule_batch_dry_run_is_the_default(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));
        $this->seed($store, 202, array('_status' => 'no-show', '_client_email' => 'grace@example.test'));

        $mail   = new FakeEmailService();
        $result = ( new SendRescheduleInvitationTool($this->errors, $store, $mail) )->execute(
            array('appointment_ids' => array(201, 202)),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame('preview', $result['data']['recipients'][0]['status']);
        $this->assertSame(array(), $mail->sent);
    }

    public function test_a_reschedule_invitation_refuses_sms(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));

        $result = ( new SendRescheduleInvitationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array('appointment_ids' => array(201), 'method' => 'sms'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('method', $result['error']['data']);
    }

    public function test_a_reschedule_batch_where_every_send_fails_is_an_error(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));

        $mail         = new FakeEmailService();
        $mail->succeed = false;

        $result = ( new SendRescheduleInvitationTool($this->errors, $store, $mail) )->execute(
            array('appointment_ids' => array(201), 'dry_run' => false),
            self::CONTEXT
        );

        $this->assertSame('delivery_failed', $result['error']['code']);
        $this->assertSame(1, $result['error']['data']['failed']);
    }

    public function test_a_duplicate_invitation_is_suppressed(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));

        $mail = new FakeEmailService();
        $tool = new SendRescheduleInvitationTool($this->errors, $store, $mail);

        $tool->execute(array('appointment_ids' => array(201), 'dry_run' => false), self::CONTEXT);
        $second = $tool->execute(array('appointment_ids' => array(201), 'dry_run' => false), self::CONTEXT);

        $this->assertTrue($second['success']);
        $this->assertSame(1, $second['data']['already_sent']);
        $this->assertCount(1, $mail->sent);
    }

    public function test_the_invitation_message_is_included_and_bounded(): void
    {
        $store = new FakeCalendarStore();
        $this->seed($store, 201, array('_status' => 'no-show'));

        $mail = new FakeEmailService();
        ( new SendRescheduleInvitationTool($this->errors, $store, $mail) )->execute(
            array(
                'appointment_ids' => array(201),
                'message'         => 'We have evening slots too.',
                'dry_run'         => false,
            ),
            self::CONTEXT
        );

        $this->assertStringContainsString('We have evening slots too.', $mail->sent[0]['body']);

        $tooLong = ( new SendRescheduleInvitationTool($this->errors, $store, new FakeEmailService()) )->execute(
            array(
                'appointment_ids' => array(201),
                'message'         => str_repeat('x', CalendarNotification::MAX_CUSTOM_MESSAGE + 1),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $tooLong['error']['code']);
        $this->assertArrayHasKey('message', $tooLong['error']['data']);
    }

    public function test_a_reschedule_batch_refuses_more_than_the_ceiling(): void
    {
        $result = ( new SendRescheduleInvitationTool($this->errors, new FakeCalendarStore(), new FakeEmailService()) )
            ->execute(
                array('appointment_ids' => range(1, SendRescheduleInvitationTool::MAX_BATCH + 1)),
                self::CONTEXT
            );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('appointment_ids', $result['error']['data']);
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
