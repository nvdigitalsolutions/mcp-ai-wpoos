<?php

/**
 * Send one appointment reminder.
 *
 * Port of `WP_MCP_AI_Tool_Send_Appointment_Reminder` (Proposal 164 section
 * 164.88).
 *
 * ## Corrections
 *
 * 1. **A failed send is an error.** WordPress returned `success => true` with
 *    `reminder_sent => false`.
 * 2. **`reminder_type` is honoured.** The enum promised `email`, `sms` and
 *    `both`; WordPress always sent email and logged `type => email`, whatever
 *    the caller asked for. This runtime binds no SMS transport, so the two SMS
 *    values are refused instead of silently downgraded.
 * 3. **`hours_before` is bounded and validated.** WordPress ran `absint` over
 *    it and used the result only in the log; a reminder tagged `0` or
 *    `999999` was accepted. The value is now the reminder's identity — a
 *    24-hour reminder and a 2-hour reminder are different notifications — and
 *    is bounded to 1–720.
 * 4. **The send is idempotent per revision and schedule.** Retrying the same
 *    reminder no longer emails the client twice; `resend: true` overrides it.
 *
 * No calendar attachment: a reminder does not change the invitation, and a
 * second `METHOD:REQUEST` for an unchanged event only prompts clients to
 * re-accept the same booking.
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
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\AbstractTool;

class SendAppointmentReminderTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The reminder window bounds, in hours. */
    public const MIN_HOURS_BEFORE = 1;

    public const MAX_HOURS_BEFORE = 720;

    /**
     * The flags a reminder declares: outbound, and persistent because the
     * appointment is stamped.
     */
    public const FLAGS = array('external-communication', 'network-dependent', 'state-changing', 'write');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly EmailServiceInterface $email,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'send_appointment_reminder';
    }

    public function getName(): string
    {
        return 'Send Appointment Reminder';
    }

    public function getDescription(): string
    {
        return 'Emails a client a reminder for a specific appointment, tagged with how many hours before the appointment it is meant to go out. Records the reminder on the appointment and will not repeat the same reminder unless asked.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id' => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID.',
                ),
                'reminder_type'  => array(
                    'type'        => 'string',
                    'description' => 'Delivery method. Only email is available in this runtime.',
                    'enum'        => array('email', 'sms', 'both'),
                    'default'     => 'email',
                ),
                'hours_before'   => array(
                    'type'        => 'integer',
                    'description' => 'How many hours before the appointment this reminder is sent.',
                    'default'     => 24,
                    'minimum'     => self::MIN_HOURS_BEFORE,
                    'maximum'     => self::MAX_HOURS_BEFORE,
                ),
                'dry_run'        => array(
                    'type'        => 'boolean',
                    'description' => 'Preview the message without sending.',
                    'default'     => false,
                ),
                'resend'         => array(
                    'type'        => 'boolean',
                    'description' => 'Send even when this reminder was already sent for this revision.',
                    'default'     => false,
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
        $id     = $this->intParam($arguments, 'appointment_id', 0);

        if ($id <= 0) {
            return $this->errors->validationFailed(
                'appointment_id is required.',
                array('appointment_id' => array('A positive appointment ID is required.'))
            );
        }

        $type = strtolower(trim($this->stringParam($arguments, 'reminder_type', 'email')));

        if ('email' !== $type) {
            return $this->errors->validationFailed(
                'Only email delivery is available in this runtime; no SMS transport is bound.',
                array('reminder_type' => array('Not available.'))
            );
        }

        $hours = $this->intParam($arguments, 'hours_before', 24);

        if ($hours < self::MIN_HOURS_BEFORE || $hours > self::MAX_HOURS_BEFORE) {
            return $this->errors->validationFailed(
                sprintf(
                    'hours_before must be between %d and %d.',
                    self::MIN_HOURS_BEFORE,
                    self::MAX_HOURS_BEFORE
                ),
                array('hours_before' => array('Out of range.'))
            );
        }

        $item = $this->content->find($id);

        if (! $item instanceof ContentItem || AppointmentDefinition::TYPE !== $item->type) {
            return $this->errors->create('appointment_not_found', 'Appointment not found.');
        }

        $recipient = CalendarNotification::email($item);

        if ('' === $recipient || ! $this->email->validateRecipient($recipient)) {
            return $this->errors->create(
                'missing_recipient',
                'The appointment has no valid client email.'
            );
        }

        if (! $this->email->isAvailable()) {
            return $this->errors->create('mail_unavailable', 'Email service not available.');
        }

        $sequence  = CalendarNotification::sequence($item);
        $kind      = CalendarNotification::KIND_REMINDER . ':' . $hours;
        $previous  = CalendarNotification::sentEntry($item, $kind, $sequence);
        $resend    = $this->boolParam($arguments, 'resend');
        $dryRun    = $this->boolParam($arguments, 'dry_run');

        if (null !== $previous && ! $resend && ! $dryRun) {
            return $this->success(
                'This reminder was already sent for this revision.',
                array(
                    'appointment_id' => $item->id,
                    'recipient'      => $recipient,
                    'sent'           => false,
                    'already_sent'   => true,
                    'sent_at'        => $previous['sent_at'],
                    'hours_before'   => $hours,
                    'revision'       => $sequence,
                )
            );
        }

        $subject = sprintf('Appointment Reminder #%d', $item->id);
        $body    = $this->body($item);

        if ($dryRun) {
            return $this->success(
                'Dry run: the reminder was not sent.',
                array(
                    'dry_run'       => true,
                    'appointment_id' => $item->id,
                    'recipient'     => $recipient,
                    'subject'       => $subject,
                    'body'          => $body,
                    'hours_before'  => $hours,
                    'revision'      => $sequence,
                    'sent'          => false,
                )
            );
        }

        [$sent, $error, $messageId] = CalendarNotification::send(
            $this->email,
            array(
                'to'      => $recipient,
                'subject' => $subject,
                'body'    => $body,
                'is_html' => false,
            )
        );

        if (! $sent) {
            return $this->errors->create('email_failed', $error ?? 'The reminder could not be sent.');
        }

        $sentAt   = gmdate(AppointmentDefinition::TIME_FORMAT);
        $recorded = true;
        $recordError = null;

        try {
            $this->content->update(
                $item->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array(
                        '_reminders_sent' => $this->legacyLog($item, $sentAt, $hours),
                        CalendarNotification::META_LOG => CalendarNotification::withEntry(
                            $item,
                            $kind,
                            $sequence,
                            $sentAt,
                            $messageId
                        ),
                    )
                )
            );
        } catch (AccessDeniedException $denied) {
            $recorded    = false;
            $recordError = $denied->getMessage();
        } catch (\Throwable $failure) {
            $recorded    = false;
            $recordError = $failure->getMessage();
        }

        $data = array(
            'appointment_id' => $item->id,
            'recipient'      => $recipient,
            'sent'           => true,
            'already_sent'   => false,
            'message_id'     => $messageId,
            'sent_at'        => $sentAt,
            'hours_before'   => $hours,
            'revision'       => $sequence,
            'recorded'       => $recorded,
        );

        if (null !== $recordError) {
            $data['record_error'] = $recordError;
        }

        return $this->success(
            $recorded
                ? 'Reminder sent successfully.'
                : 'Reminder sent, but the appointment could not be stamped.',
            $data
        );
    }

    /**
     * The legacy `_reminders_sent` list, appended.
     *
     * Kept because it is part of the WordPress record family; the authoritative
     * idempotency index is `_notification_log`.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyLog(ContentItem $item, string $sentAt, int $hours): array
    {
        $log = $item->meta[ '_reminders_sent' ] ?? array();

        if (! is_array($log)) {
            $log = array();
        }

        $log[] = array(
            'sent_at'      => $sentAt,
            'hours_before' => $hours,
            'type'         => 'email',
        );

        return array_values($log);
    }

    /**
     * The message body.
     */
    private function body(ContentItem $item): string
    {
        $name  = AppointmentDefinition::string($item->meta[ '_client_name' ] ?? '');
        $start = CalendarNotification::time($item, '_start_time');

        $body = sprintf(
            "Hello %s,\n\nThis is a reminder about your upcoming appointment.\n",
            '' !== $name ? $name : 'there'
        );

        if ('' !== $start) {
            $body .= sprintf("Time: %s\n", $start);
        }

        return $body . "\nSee you soon!";
    }
}
