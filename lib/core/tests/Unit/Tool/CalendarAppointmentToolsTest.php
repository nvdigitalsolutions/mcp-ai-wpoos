<?php

/**
 * The five appointment tools and the shared appointment rules.
 *
 * The content store is a mock rather than the real adapter so the tests can
 * assert the exact command and query each tool builds — in particular the
 * three-clause overlap query and the `mcp_appointment` meta family. The real
 * adapter path is exercised by the probe in the adapter suite.
 *
 * Pinned behaviours, collected here rather than buried in assertions:
 *
 * - Times are accepted as `Y-m-d H:i:s` or ISO 8601 and stored in UTC; a
 *   relative expression is refused.
 * - The overlap query is `start < new_end AND end > new_start` — three ANDed
 *   clauses — which catches an appointment contained in the new slot, the case
 *   WordPress's two OR'd range clauses missed.
 * - `create_appointment` with an id reports the **stored** status, not
 *   WordPress's hard-coded `confirmed`.
 * - Only supplied fields change on update; an empty update is `no_changes`.
 * - Cancel records the actor, the reason and the activity log, and says a
 *   refund is unavailable rather than pretending one happened.
 * - Reschedule keeps the original duration, regenerates the title and logs both
 *   spans; a past time is refused.
 * - A missing email transport never fails a booking write.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\CancelAppointmentTool;
use Nvoos\Core\Tool\Calendar\CreateAppointmentTool;
use Nvoos\Core\Tool\Calendar\GetAppointmentDetailsTool;
use Nvoos\Core\Tool\Calendar\RescheduleAppointmentTool;
use Nvoos\Core\Tool\Calendar\UpdateAppointmentTool;
use PHPUnit\Framework\TestCase;

final class CalendarAppointmentToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    private ?CreateContentCommand $created = null;

    private ?UpdateContentCommand $updated = null;

    private ?ContentQuery $queried = null;

    private ?array $mailed = null;

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

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );
    }

    // ─── Time handling ───────────────────────────────────────────────────

    public function test_times_are_stored_in_utc(): void
    {
        $this->assertSame(
            '2026-02-01 12:00:00',
            AppointmentDefinition::store(AppointmentDefinition::parseTime('2026-02-01T14:00:00+02:00'))
        );
        $this->assertSame(
            '2026-02-01 14:00:00',
            AppointmentDefinition::store(AppointmentDefinition::parseTime('2026-02-01 14:00:00'))
        );
        $this->assertSame(
            '2026-02-01 00:00:00',
            AppointmentDefinition::store(AppointmentDefinition::parseTime('2026-02-01T00:00:00Z'))
        );
    }

    public function test_a_relative_time_is_refused(): void
    {
        $this->assertNull(AppointmentDefinition::parseTime('next friday'));
        $this->assertNull(AppointmentDefinition::parseTime(''));
        $this->assertNull(AppointmentDefinition::parseTime(123));
    }

    public function test_a_span_defaults_to_sixty_minutes_and_clamps_a_duration(): void
    {
        $this->assertSame(
            array('2026-02-01 09:00:00', '2026-02-01 10:00:00'),
            AppointmentDefinition::span('2026-02-01 09:00:00', null, null)
        );
        $this->assertSame(
            array('2026-02-01 09:00:00', '2026-02-01 09:15:00'),
            AppointmentDefinition::span('2026-02-01 09:00:00', null, 5)
        );
        $this->assertSame(
            array('2026-02-01 09:00:00', '2026-02-01 17:00:00'),
            AppointmentDefinition::span('2026-02-01 09:00:00', null, 9999)
        );
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->assertNull(
            AppointmentDefinition::span('2026-02-01 10:00:00', '2026-02-01 09:00:00', null)
        );
        $this->assertNull(
            AppointmentDefinition::span('2026-02-01 10:00:00', '2026-02-01 10:00:00', null)
        );
    }

    // ─── create_appointment ──────────────────────────────────────────────

    public function test_create_stores_the_wordpress_meta_family(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array(
                'client_name'   => 'Ada Lovelace',
                'client_email'  => 'ada@example.com',
                'client_phone'  => '+44 20 7946 0000',
                'start_time'    => '2026-02-01 14:00:00',
                'duration_minutes' => 45,
                'location'      => 'Room 1',
                'notes'         => 'First visit.',
                'metadata'      => array('source' => 'web'),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($this->created);
        $this->assertSame(AppointmentDefinition::TYPE, $this->created->type);
        $this->assertSame('publish', $this->created->status);
        $this->assertSame('Appointment: Ada Lovelace - 2026-02-01 14:00:00', $this->created->title);
        $this->assertSame('First visit.', $this->created->content);

        $meta = $this->created->meta;
        $this->assertSame('Ada Lovelace', $meta['_client_name']);
        $this->assertSame('ada@example.com', $meta['_client_email']);
        $this->assertSame('2026-02-01 14:00:00', $meta['_start_time']);
        $this->assertSame('2026-02-01 14:45:00', $meta['_end_time']);
        $this->assertSame('confirmed', $meta['_status']);
        $this->assertSame('general', $meta['_appointment_type']);
        $this->assertSame('web', $meta['_custom_source']);
        $this->assertSame(1, $meta['_created_by']);
        $this->assertNotSame('', $meta['_created_at']);

        $this->assertFalse($result['data']['updated']);
        $this->assertSame('2026-02-01 14:00:00', $result['data']['start_time']);
    }

    public function test_create_normalises_an_iso_time(): void
    {
        $this->createTool($this->contentStore())->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01T14:00:00+02:00',
            ),
            self::CONTEXT
        );

        $this->assertSame('2026-02-01 12:00:00', $this->created->meta['_start_time']);
        $this->assertSame('2026-02-01 13:00:00', $this->created->meta['_end_time']);
    }

    public function test_create_refuses_a_relative_time(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array('client_name' => 'Ada', 'client_email' => 'ada@example.com', 'start_time' => 'next friday'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('start_time', $result['error']['data']);
    }

    public function test_create_requires_a_name_a_valid_email_and_a_start(): void
    {
        $tool = $this->createTool($this->contentStore());

        $this->assertSame(
            'missing_client_name',
            $tool->execute(array('client_email' => 'a@b.c', 'start_time' => '2026-02-01 09:00:00'), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'invalid_email',
            $tool->execute(array('client_name' => 'A', 'client_email' => 'nope', 'start_time' => '2026-02-01 09:00:00'), self::CONTEXT)['error']['code']
        );
        $this->assertSame(
            'missing_start_time',
            $tool->execute(array('client_name' => 'A', 'client_email' => 'a@b.co'), self::CONTEXT)['error']['code']
        );
    }

    public function test_create_refuses_an_unknown_target_system(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array(
                'client_name'   => 'Ada',
                'client_email'  => 'ada@example.com',
                'start_time'    => '2026-02-01 09:00:00',
                'target_system' => 'jetappointment',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('target_system', $result['error']['data']);
    }

    public function test_create_builds_three_anded_overlap_clauses(): void
    {
        $this->createTool(
            $this->contentStore(
                overlaps: array($this->appointment(array('_start_time' => '2026-02-01 14:30:00'), 7))
            )
        )->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01 14:00:00',
                'end_time'     => '2026-02-01 15:00:00',
            ),
            self::CONTEXT
        );

        $this->assertNotNull($this->queried);
        $meta = $this->queried->metaQuery;

        $this->assertSame('AND', $meta['relation']);
        $this->assertSame('_status', $meta[0]['key']);
        $this->assertSame('IN', $meta[0]['compare']);
        $this->assertSame('_start_time', $meta[1]['key']);
        $this->assertSame('<', $meta[1]['compare']);
        $this->assertSame('2026-02-01 15:00:00', $meta[1]['value']);
        $this->assertSame('_end_time', $meta[2]['key']);
        $this->assertSame('>', $meta[2]['compare']);
        $this->assertSame('2026-02-01 14:00:00', $meta[2]['value']);
    }

    public function test_create_refuses_a_contained_appointment(): void
    {
        $result = $this->createTool(
            $this->contentStore(
                overlaps: array($this->appointment(array('_start_time' => '2026-02-01 10:00:00'), 7))
            )
        )->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01 09:00:00',
                'end_time'     => '2026-02-01 12:00:00',
            ),
            self::CONTEXT
        );

        $this->assertSame('scheduling_conflict', $result['error']['code']);
        $this->assertSame(array(7), $result['error']['data']['conflicts']);
        $this->assertNull($this->created);
    }

    public function test_create_accepts_a_free_slot(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01 09:00:00',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($this->created);
    }

    public function test_create_with_an_id_replaces_and_reports_the_stored_status(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name'  => 'Old Name',
                '_status'       => 'pending',
                '_start_time'   => '2026-02-01 09:00:00',
                '_end_time'     => '2026-02-01 10:00:00',
                '_created_at'   => '2026-01-01 08:00:00',
            ),
            12
        );

        $result = $this->createTool($this->contentStore($existing))->execute(
            array(
                'appointment_id' => 12,
                'client_name'    => 'New Name',
                'client_email'   => 'new@example.com',
                'start_time'     => '2026-02-01 11:00:00',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertSame('pending', $result['data']['status']);
        $this->assertSame('pending', $this->updated->meta['_status']);
        $this->assertSame('New Name', $this->updated->meta['_client_name']);
        $this->assertSame('Appointment: New Name - 2026-02-01 11:00:00', $this->updated->title);
        $this->assertNull($this->created);
    }

    public function test_create_with_an_id_requires_edit_permission(): void
    {
        $result = $this->createTool($this->contentStore($this->appointment(array(), 12), canAccess: false))->execute(
            array(
                'appointment_id' => 12,
                'client_name'    => 'New Name',
                'client_email'   => 'new@example.com',
                'start_time'     => '2026-02-01 11:00:00',
            ),
            self::CONTEXT
        );

        $this->assertSame('forbidden', $result['error']['code']);
    }

    public function test_create_with_an_unknown_id_is_an_error(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array(
                'appointment_id' => 99,
                'client_name'    => 'New Name',
                'client_email'   => 'new@example.com',
                'start_time'     => '2026-02-01 11:00:00',
            ),
            self::CONTEXT
        );

        $this->assertSame('appointment_not_found', $result['error']['code']);
    }

    public function test_create_without_an_email_transport_still_books(): void
    {
        $result = $this->createTool($this->contentStore())->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01 09:00:00',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['notification_sent']);
        $this->assertSame('This host has no email service.', $result['data']['notification_error']);
    }

    public function test_create_sends_the_confirmation_when_a_transport_exists(): void
    {
        $result = $this->createTool($this->contentStore(), $this->emailService(true))->execute(
            array(
                'client_name'  => 'Ada',
                'client_email' => 'ada@example.com',
                'start_time'   => '2026-02-01 09:00:00',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['data']['notification_sent']);
        $this->assertStringContainsString('Your appointment has been confirmed.', $this->mailed['body']);
        $this->assertSame('Appointment Confirmation #100', $this->mailed['subject']);
    }

    // ─── update_appointment ──────────────────────────────────────────────

    public function test_update_changes_only_supplied_fields(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name'  => 'Ada',
                '_client_email' => 'ada@example.com',
                '_appointment_type' => 'consultation',
                '_start_time'   => '2026-02-01 09:00:00',
                '_end_time'     => '2026-02-01 10:00:00',
                '_status'       => 'confirmed',
            ),
            5
        );

        $result = $this->updateTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5, 'location' => 'Room 2', 'send_notification' => false),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('_location', $this->updated->meta);
        $this->assertSame('Room 2', $this->updated->meta['_location']);
        $this->assertSame('consultation', $this->updated->meta['_appointment_type']);
        $this->assertSame('2026-02-01 09:00:00', $this->updated->meta['_start_time']);
        $this->assertNull($this->updated->title);
    }

    public function test_update_refuses_an_empty_change_set(): void
    {
        $result = $this->updateTool($this->contentStore($this->appointment(array(), 5)))->execute(
            array('appointment_id' => 5),
            self::CONTEXT
        );

        $this->assertSame('no_changes', $result['error']['code']);
    }

    public function test_update_recomputes_the_title_when_the_time_moves(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name' => 'Ada',
                '_start_time'  => '2026-02-01 09:00:00',
                '_end_time'    => '2026-02-01 10:00:00',
            ),
            5
        );

        $this->updateTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5, 'start_time' => '2026-02-02 09:00:00', 'send_notification' => false),
            self::CONTEXT
        );

        $this->assertSame('Appointment: Ada - 2026-02-02 09:00:00', $this->updated->title);
        $this->assertSame('2026-02-02 10:00:00', $this->updated->meta['_end_time']);
    }

    public function test_update_appends_the_change_history(): void
    {
        $existing = $this->appointment(
            array(
                '_status'         => 'confirmed',
                '_change_history' => array(array('timestamp' => '2026-01-01 00:00:00', 'user_id' => 9)),
            ),
            5
        );

        $this->updateTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5, 'status' => 'completed', 'send_notification' => false),
            self::CONTEXT
        );

        $history = $this->updated->meta['_change_history'];

        $this->assertCount(2, $history);
        $this->assertSame(9, $history[0]['user_id']);
        $this->assertSame(1, $history[1]['user_id']);
        $this->assertSame(array('status' => 'completed'), $history[1]['changes']);
    }

    public function test_update_refuses_an_invalid_status(): void
    {
        $result = $this->updateTool($this->contentStore($this->appointment(array(), 5)))->execute(
            array('appointment_id' => 5, 'status' => 'maybe'),
            self::CONTEXT
        );

        $this->assertSame('invalid_status', $result['error']['code']);
    }

    public function test_update_refuses_an_unknown_appointment(): void
    {
        $result = $this->updateTool($this->contentStore())->execute(
            array('appointment_id' => 99, 'location' => 'X'),
            self::CONTEXT
        );

        $this->assertSame('invalid_appointment', $result['error']['code']);
    }

    // ─── cancel_appointment ──────────────────────────────────────────────

    public function test_cancel_records_status_actor_and_activity(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name'  => 'Ada',
                '_client_email' => 'ada@example.com',
                '_start_time'   => '2026-02-01 09:00:00',
                '_status'       => 'confirmed',
            ),
            5
        );

        $result = $this->cancelTool($this->contentStore($existing))->execute(
            array(
                'appointment_id'   => 5,
                'reason'           => 'Client unwell',
                'cancelled_by'     => 'client',
                'send_notification' => false,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $this->updated->meta['_status']);
        $this->assertSame('client', $this->updated->meta['_cancelled_by']);
        $this->assertSame(1, $this->updated->meta['_cancelled_by_user_id']);
        $this->assertSame('Client unwell', $this->updated->meta['_cancellation_reason']);
        $this->assertNotSame('', $this->updated->meta['_cancelled_at']);

        $log = $this->updated->meta['_activity_log'];
        $this->assertCount(1, $log);
        $this->assertSame('cancelled', $log[0]['action']);
        $this->assertSame('client', $log[0]['cancelled_by']);
        $this->assertSame('Client unwell', $log[0]['reason']);

        $this->assertSame('cancelled', $result['data']['status']);
    }

    public function test_cancel_refuses_a_second_cancellation(): void
    {
        $existing = $this->appointment(array('_status' => 'cancelled'), 5);

        $result = $this->cancelTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5),
            self::CONTEXT
        );

        $this->assertSame('already_cancelled', $result['error']['code']);
    }

    public function test_cancel_reports_a_refund_as_unavailable(): void
    {
        $existing = $this->appointment(array('_status' => 'confirmed'), 5);

        $result = $this->cancelTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5, 'process_refund' => true, 'send_notification' => false),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['refund_processed']);
        $this->assertSame('No refund gateway is bound in this runtime.', $result['data']['refund_error']);
        $this->assertArrayNotHasKey('_refund_processed', $this->updated->meta);
    }

    public function test_cancel_offers_a_reschedule_in_prose(): void
    {
        $existing = $this->appointment(
            array('_client_email' => 'ada@example.com', '_status' => 'confirmed'),
            5
        );

        $this->cancelTool($this->contentStore($existing), $this->emailService(true))->execute(
            array('appointment_id' => 5, 'offer_reschedule' => true),
            self::CONTEXT
        );

        $this->assertStringContainsString('by replying to this email', $this->mailed['body']);
        $this->assertStringNotContainsString('http', $this->mailed['body']);
    }

    // ─── reschedule_appointment ──────────────────────────────────────────

    public function test_reschedule_keeps_the_original_duration(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name' => 'Ada',
                '_client_email' => 'ada@example.com',
                '_start_time'  => '2026-02-01 09:00:00',
                '_end_time'    => '2026-02-01 10:30:00',
                '_status'      => 'confirmed',
            ),
            5
        );

        $result = $this->rescheduleTool($this->contentStore($existing))->execute(
            array(
                'appointment_id'    => 5,
                'new_start_time'    => '2099-02-02 09:00:00',
                'send_notification' => false,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('2099-02-02 09:00:00', $this->updated->meta['_start_time']);
        $this->assertSame('2099-02-02 10:30:00', $this->updated->meta['_end_time']);
        $this->assertSame('Appointment: Ada - 2099-02-02 09:00:00', $this->updated->title);
        $this->assertSame('2026-02-01 09:00:00', $result['data']['old_start_time']);
    }

    public function test_reschedule_refuses_a_past_time(): void
    {
        $existing = $this->appointment(array('_start_time' => '2026-02-01 09:00:00'), 5);

        $result = $this->rescheduleTool($this->contentStore($existing))->execute(
            array('appointment_id' => 5, 'new_start_time' => '2020-01-01 09:00:00'),
            self::CONTEXT
        );

        $this->assertSame('past_time', $result['error']['code']);
    }

    public function test_reschedule_logs_the_old_and_new_span(): void
    {
        $existing = $this->appointment(
            array('_start_time' => '2026-02-01 09:00:00', '_end_time' => '2026-02-01 10:00:00'),
            5
        );

        $this->rescheduleTool($this->contentStore($existing))->execute(
            array(
                'appointment_id'    => 5,
                'new_start_time'    => '2099-03-01 09:00:00',
                'reason'            => 'Staff holiday',
                'send_notification' => false,
            ),
            self::CONTEXT
        );

        $log = $this->updated->meta['_activity_log'];

        $this->assertSame('rescheduled', $log[0]['action']);
        $this->assertSame('Staff holiday', $log[0]['reason']);
        $this->assertSame('2026-02-01 09:00:00', $log[0]['old_start']);
        $this->assertSame('2099-03-01 09:00:00', $log[0]['new_start']);
        $this->assertSame('Staff holiday', $this->updated->meta['_reschedule_reason']);
    }

    public function test_reschedule_detects_a_conflict(): void
    {
        $existing = $this->appointment(array('_start_time' => '2026-02-01 09:00:00'), 5);

        $result = $this->rescheduleTool(
            $this->contentStore($existing, overlaps: array($this->appointment(array(), 8)))
        )->execute(
            array('appointment_id' => 5, 'new_start_time' => '2099-04-01 09:00:00'),
            self::CONTEXT
        );

        $this->assertSame('scheduling_conflict', $result['error']['code']);
        $this->assertSame(array(8), $result['error']['data']['conflicts']);
    }

    // ─── get_appointment_details ─────────────────────────────────────────

    public function test_details_projects_the_record(): void
    {
        $existing = $this->appointment(
            array(
                '_client_name'      => 'Ada',
                '_client_email'     => 'ada@example.com',
                '_client_phone'     => '123',
                '_appointment_type' => 'consultation',
                '_start_time'       => '2026-02-01 09:00:00',
                '_end_time'         => '2026-02-01 10:00:00',
                '_location'         => 'Room 1',
                '_status'           => 'confirmed',
                '_created_at'       => '2026-01-01 08:00:00',
                '_created_by'       => 3,
            ),
            5,
            'Appointment: Ada - 2026-02-01 09:00:00'
        );

        $result = ( new GetAppointmentDetailsTool($this->errors, $this->contentStore($existing)) )
            ->execute(array('appointment_id' => 5), self::CONTEXT);

        $details = $result['data']['appointment'];

        $this->assertSame(5, $details['id']);
        $this->assertSame('Ada', $details['client']['name']);
        $this->assertSame('consultation', $details['appointment']['type']);
        $this->assertSame('2026-02-01 09:00:00', $details['appointment']['start_time']);
        $this->assertSame('2026-02-01T09:00:00+00:00', $details['start_time_iso']);
        $this->assertSame('Room 1', $details['appointment']['location']);
        $this->assertSame(3, $details['created_by']);
        $this->assertArrayNotHasKey('history', $details);
        $this->assertArrayNotHasKey('custom_metadata', $details);
    }

    public function test_details_includes_history_and_custom_metadata_on_request(): void
    {
        $existing = $this->appointment(
            array(
                '_status'            => 'cancelled',
                '_cancelled_at'      => '2026-01-05 10:00:00',
                '_cancelled_by'      => 'staff',
                '_cancelled_by_user_id' => 1,
                '_cancellation_reason'  => 'Moved',
                '_activity_log'      => array(array('action' => 'cancelled')),
                '_custom_source'     => 'web',
            ),
            5
        );

        $result = ( new GetAppointmentDetailsTool($this->errors, $this->contentStore($existing)) )
            ->execute(
                array('appointment_id' => 5, 'include_history' => true, 'include_metadata' => true),
                self::CONTEXT
            );

        $details = $result['data']['appointment'];

        $this->assertCount(1, $details['history']['activity_log']);
        $this->assertSame('staff', $details['history']['cancelled']['by']);
        $this->assertSame('Moved', $details['history']['cancelled']['reason']);
        $this->assertSame('web', $details['custom_metadata']['source']);
    }

    public function test_details_refuses_an_unknown_appointment(): void
    {
        $result = ( new GetAppointmentDetailsTool($this->errors, $this->contentStore()) )
            ->execute(array('appointment_id' => 99), self::CONTEXT);

        $this->assertSame('invalid_appointment', $result['error']['code']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<ContentItem> $overlaps Items the conflict query returns.
     */
    private function contentStore(
        ?ContentItem $item = null,
        array $overlaps = array(),
        bool $canAccess = true,
    ): ContentStoreInterface {
        $store = $this->createMock(ContentStoreInterface::class);

        $store->method('find')->willReturn($item);
        $store->method('userCanAccess')->willReturn($canAccess);

        $store->method('query')->willReturnCallback(
            function (ContentQuery $query) use ($overlaps): ContentCollection {
                $this->queried = $query;

                return new ContentCollection(
                    items: $overlaps,
                    total: count($overlaps),
                    page: 1,
                    perPage: $query->perPage,
                    totalPages: 1,
                );
            }
        );

        $store->method('update')->willReturnCallback(
            function (int $id, UpdateContentCommand $command) use ($item): ContentItem {
                $this->updated = $command;

                return new ContentItem(
                    id: $id,
                    title: $command->title ?? ( $item?->title ?? 'Untitled' ),
                    content: $command->content ?? ( $item?->content ?? '' ),
                    status: $command->status ?? ( $item?->status ?? 'publish' ),
                    type: $item?->type ?? AppointmentDefinition::TYPE,
                    authorId: $command->userId,
                    createdAt: new DateTimeImmutable(),
                    updatedAt: new DateTimeImmutable(),
                    meta: array_merge($item?->meta ?? array(), $command->meta),
                );
            }
        );

        $store->method('create')->willReturnCallback(
            function (CreateContentCommand $command): ContentItem {
                $this->created = $command;

                return new ContentItem(
                    id: 100,
                    title: $command->title,
                    content: $command->content,
                    status: $command->status,
                    type: $command->type,
                    authorId: $command->authorId,
                    createdAt: new DateTimeImmutable(),
                    updatedAt: new DateTimeImmutable(),
                    meta: $command->meta,
                );
            }
        );

        return $store;
    }

    /**
     * @param array<string, mixed> $meta Meta.
     */
    private function appointment(array $meta, int $id = 5, string $title = 'Appointment'): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: AppointmentDefinition::TYPE,
            authorId: 1,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            meta: $meta,
        );
    }

    private function emailService(bool $available, bool $success = true): EmailServiceInterface
    {
        $email = $this->createMock(EmailServiceInterface::class);
        $email->method('isAvailable')->willReturn($available);
        $email->method('send')->willReturnCallback(
            function (array $message) use ($success): array {
                $this->mailed = $message;

                return $success
                    ? array('success' => true)
                    : array('success' => false, 'error' => 'Transport refused.');
            }
        );

        return $email;
    }

    private function createTool(ContentStoreInterface $content, ?EmailServiceInterface $email = null): CreateAppointmentTool
    {
        return new CreateAppointmentTool($this->errors, $content, $email);
    }

    private function updateTool(ContentStoreInterface $content, ?EmailServiceInterface $email = null): UpdateAppointmentTool
    {
        return new UpdateAppointmentTool($this->errors, $content, $email);
    }

    private function cancelTool(ContentStoreInterface $content, ?EmailServiceInterface $email = null): CancelAppointmentTool
    {
        return new CancelAppointmentTool($this->errors, $content, $email);
    }

    private function rescheduleTool(ContentStoreInterface $content, ?EmailServiceInterface $email = null): RescheduleAppointmentTool
    {
        return new RescheduleAppointmentTool($this->errors, $content, $email);
    }
}
