<?php

/**
 * Invite clients who missed an appointment to rebook.
 *
 * Port of `WP_MCP_AI_Tool_Send_Reschedule_Invitation` (Proposal 164 section
 * 164.88).
 *
 * ## Corrections
 *
 * 1. **The no-show gate reads the key and value the writers store.** WordPress
 *    checked `_appointment_status === 'no_show'`; the toolkit writes
 *    `_status` with the hyphenated `no-show`, and no writer ever set
 *    `_appointment_status`. The original therefore skipped *every* appointment
 *    the toolkit created, including real no-shows. The port reads `_status`,
 *    accepts the underscore spelling for records migrated from older code, and
 *    reports the raw value when it is neither.
 * 2. **A run that delivered nothing is an error.** WordPress returned
 *    `success => true` with `failed` counting the casualties.
 * 3. **`method` is validated.** `sms` needs a gateway this runtime does not
 *    bind, so it is refused instead of producing a silent "failed" per row.
 * 4. **The subject is a plain string.** WordPress passed the client name to
 *    `sprintf()` for a format that had no placeholder.
 * 5. **The closing line is not a broken promise.** WordPress told the client to
 *    "visit our booking page" with no URL anywhere in the message; the port asks
 *    them to reply or contact the business.
 * 6. **The send is idempotent per revision.** A retried batch does not email the
 *    same client twice.
 *
 * No calendar attachment: the invitation carries no new time to request.
 * `dry_run` keeps its WordPress default of `true`.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class SendRescheduleInvitationTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The ceiling on one batch. */
    public const MAX_BATCH = 50;

    /** The stored value that means "did not attend". */
    public const NO_SHOW = 'no-show';

    /** The underscore spelling older records carry. */
    public const NO_SHOW_LEGACY = 'no_show';

    /** The flags a bulk outreach declares. */
    public const FLAGS = array(
        'external-communication',
        'mass-email',
        'network-dependent',
        'state-changing',
        'write',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly EmailServiceInterface $email,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'send_reschedule_invitation';
    }

    public function getName(): string
    {
        return 'Send Reschedule Invitation';
    }

    public function getDescription(): string
    {
        return 'Emails clients who were marked no-show, inviting them to rebook. Dry run is the default and previews every recipient without sending.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_ids' => array(
                    'type'        => 'array',
                    'description' => 'No-show appointment IDs to invite.',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_BATCH,
                ),
                'message'         => array(
                    'type'        => 'string',
                    'description' => 'Extra plain-text note included in the invitation.',
                    'maxLength'   => CalendarNotification::MAX_CUSTOM_MESSAGE,
                ),
                'method'          => array(
                    'type'        => 'string',
                    'description' => 'Delivery method. Only email is available in this runtime.',
                    'enum'        => array('email', 'sms'),
                    'default'     => 'email',
                ),
                'dry_run'         => array(
                    'type'        => 'boolean',
                    'description' => 'Preview without sending.',
                    'default'     => true,
                ),
                'resend'          => array(
                    'type'        => 'boolean',
                    'description' => 'Send even when the invitation was already sent for this revision.',
                    'default'     => false,
                ),
            ),
            'required'             => array('appointment_ids'),
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
        return self::FLAGS;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $userId = (int) ( $context['user_id'] ?? 0 );
        $ids    = $this->intListParam($arguments, 'appointment_ids');

        if (array() === $ids) {
            return $this->errors->validationFailed(
                'At least one appointment ID is required.',
                array('appointment_ids' => array('A non-empty list of appointment IDs is required.'))
            );
        }

        if (count($ids) > self::MAX_BATCH) {
            return $this->errors->validationFailed(
                sprintf('At most %d appointments may be invited in one call.', self::MAX_BATCH),
                array('appointment_ids' => array('Too many appointments.'))
            );
        }

        $method = strtolower(trim($this->stringParam($arguments, 'method', 'email')));

        if ('email' !== $method) {
            return $this->errors->validationFailed(
                'Only email delivery is available in this runtime; no SMS transport is bound.',
                array('method' => array('Not available.'))
            );
        }

        $custom = $this->customMessage($arguments);

        if (null === $custom) {
            return $this->errors->validationFailed(
                sprintf('message must be at most %d characters.', CalendarNotification::MAX_CUSTOM_MESSAGE),
                array('message' => array('Too long.'))
            );
        }

        if (! $this->email->isAvailable()) {
            return $this->errors->create('mail_unavailable', 'Email service not available.');
        }

        $dryRun     = $this->boolParam($arguments, 'dry_run', true);
        $resend     = $this->boolParam($arguments, 'resend');

        $recipients = array();
        $errors     = array();
        $processed  = 0;
        $already    = 0;
        $skipped    = 0;
        $failed     = 0;

        foreach ($ids as $id) {
            $item = $this->content->find($id);

            if (! $item instanceof ContentItem || AppointmentDefinition::TYPE !== $item->type) {
                ++$skipped;
                $errors[] = array('appointment_id' => $id, 'error' => 'Invalid appointment.');

                continue;
            }

            $rawStatus = AppointmentDefinition::string($item->meta[ '_status' ] ?? '');

            if (self::NO_SHOW !== $rawStatus && self::NO_SHOW_LEGACY !== $rawStatus) {
                ++$skipped;
                $errors[] = array(
                    'appointment_id' => $item->id,
                    'error'          => sprintf(
                        'Appointment is not marked as no-show (current status: %s).',
                        '' !== $rawStatus ? $rawStatus : 'unset'
                    ),
                );

                continue;
            }

            $email = CalendarNotification::email($item);
            $row   = array(
                'appointment_id'   => $item->id,
                'client_name'      => AppointmentDefinition::string($item->meta[ '_client_name' ] ?? ''),
                'client_email'     => $email,
                'appointment_type' => AppointmentDefinition::string($item->meta[ '_appointment_type' ] ?? ''),
                'missed_time'      => CalendarNotification::time($item, '_start_time'),
                'status'           => '',
                'message_id'       => '',
            );

            if ('' === $email || ! $this->email->validateRecipient($email)) {
                ++$skipped;
                $row['status'] = 'skipped';
                $recipients[]  = $row;
                $errors[]      = array('appointment_id' => $item->id, 'error' => 'No valid client email.');

                continue;
            }

            $sequence = CalendarNotification::sequence($item);
            $previous = CalendarNotification::sentEntry(
                $item,
                CalendarNotification::KIND_RESCHEDULE_INVITATION,
                $sequence
            );

            if (null !== $previous && ! $resend) {
                ++$already;
                $row['status'] = 'already_sent';
                $recipients[]  = $row;

                continue;
            }

            if ($dryRun) {
                ++$processed;
                $row['status'] = 'preview';
                $recipients[]  = $row;

                continue;
            }

            [$sent, $error, $messageId] = CalendarNotification::send(
                $this->email,
                array(
                    'to'      => $email,
                    'subject' => 'We Missed You — Reschedule Your Appointment',
                    'body'    => $this->body($item, $custom),
                    'is_html' => false,
                )
            );

            if (! $sent) {
                ++$failed;
                $row['status'] = 'failed';
                $recipients[]  = $row;
                $errors[]      = array(
                    'appointment_id' => $item->id,
                    'error'          => $error ?? 'The invitation could not be sent.',
                );

                continue;
            }

            $sentAt = gmdate(AppointmentDefinition::TIME_FORMAT);

            try {
                $this->content->update(
                    $item->id,
                    new UpdateContentCommand(
                        userId: $userId,
                        meta: array(
                            '_reschedule_invitation_sent_at' => $sentAt,
                            CalendarNotification::META_LOG => CalendarNotification::withEntry(
                                $item,
                                CalendarNotification::KIND_RESCHEDULE_INVITATION,
                                $sequence,
                                $sentAt,
                                $messageId
                            ),
                        )
                    )
                );
            } catch (AccessDeniedException $denied) {
                ++$failed;
                $row['status'] = 'failed';
                $recipients[]  = $row;
                $errors[]      = array('appointment_id' => $item->id, 'error' => $denied->getMessage());

                continue;
            } catch (\Throwable $failure) {
                ++$failed;
                $row['status'] = 'failed';
                $recipients[]  = $row;
                $errors[]      = array('appointment_id' => $item->id, 'error' => $failure->getMessage());

                continue;
            }

            ++$processed;
            $row['status']     = 'sent';
            $row['message_id'] = $messageId;
            $recipients[]      = $row;
        }

        $data = array(
            'dry_run'      => $dryRun,
            'method'       => $method,
            'total'        => count($ids),
            'processed'    => $processed,
            'already_sent' => $already,
            'skipped'      => $skipped,
            'failed'       => $failed,
            'recipients'   => $recipients,
            'errors'       => $errors,
        );

        if (! $dryRun && 0 === $processed && $failed > 0) {
            return $this->errors->create(
                'delivery_failed',
                'No invitation was delivered.',
                $data
            );
        }

        $message = $dryRun
            ? sprintf('Dry run: %d invitation(s) previewed, %d already sent, %d skipped.', $processed, $already, $skipped)
            : sprintf(
                'Reschedule invitations: %d sent, %d already sent, %d failed, %d skipped.',
                $processed,
                $already,
                $failed,
                $skipped
            );

        return $this->success($message, $data);
    }

    /**
     * The message body for one invitation.
     */
    private function body(ContentItem $item, string $custom): string
    {
        $name  = AppointmentDefinition::string($item->meta[ '_client_name' ] ?? '');
        $start = CalendarNotification::time($item, '_start_time');

        $body = sprintf(
            "Hello %s,\n\n",
            '' !== $name ? $name : 'there'
        );

        $body .= '' !== $start
            ? sprintf(
                "We noticed you missed your appointment on %s. We would love to help you reschedule at a more convenient time.\n\n",
                $start
            )
            : "We noticed you missed your appointment. We would love to help you reschedule at a more convenient time.\n\n";

        if ('' !== $custom) {
            $body .= $custom . "\n\n";
        }

        return $body . "Please reply to this email or contact us to reschedule.\n\nThank you!";
    }

    /**
     * The cleaned custom message, '' when absent, or null when too long.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function customMessage(array $arguments): ?string
    {
        if (! array_key_exists('message', $arguments) || ! is_scalar($arguments['message'])) {
            return '';
        }

        $custom = Text::multiline((string) $arguments['message']);

        return strlen($custom) > CalendarNotification::MAX_CUSTOM_MESSAGE ? null : $custom;
    }
}
