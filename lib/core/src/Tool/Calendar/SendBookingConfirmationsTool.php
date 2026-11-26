<?php

/**
 * Send booking confirmations in bulk.
 *
 * Port of `WP_MCP_AI_Tool_Send_Booking_Confirmations` (Proposal 164 section
 * 164.88).
 *
 * ## Corrections
 *
 * 1. **The envelope no longer reports success for a run that delivered
 *    nothing.** WordPress returned `success => true` with `failed` counting the
 *    casualties; a run where every send failed is now a `delivery_failed` error
 *    carrying the per-booking detail.
 * 2. **`_appointment_type` is read.** The WordPress tool collected
 *    `_service_type`, a key no writer sets, so the preview was always blank.
 * 3. **`method` is validated.** WordPress accepted any string; `sms` and `both`
 *    then matched nothing and every booking counted as failed with "no delivery
 *    method succeeded". This runtime has no SMS transport, so the two are
 *    refused up front and `email` is the documented method.
 * 4. **The batch is bounded.** More than fifty bookings is refused rather than
 *    mailed in one call.
 * 5. **The send is idempotent per revision.** A booking already confirmed at the
 *    same revision is reported as `already_sent` and not mailed again.
 *
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
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\AbstractTool;

class SendBookingConfirmationsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The ceiling on one batch. */
    public const MAX_BATCH = 50;

    /**
     * The flags a bulk mail send declares.
     *
     * `mass-email` is in the read-only gate: a batch send is exactly the
     * operation an operator wants a read-only profile to stop.
     */
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
        private readonly ?RuntimeIdentityInterface $identity = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'send_booking_confirmations';
    }

    public function getName(): string
    {
        return 'Send Booking Confirmations';
    }

    public function getDescription(): string
    {
        return 'Emails confirmations for a batch of appointments, optionally attaching a calendar invitation to each. Dry run is the default and previews every recipient without sending.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'booking_ids'  => array(
                    'type'        => 'array',
                    'description' => 'Appointment IDs to confirm.',
                    'items'       => array('type' => 'integer', 'minimum' => 1),
                    'minItems'    => 1,
                    'maxItems'    => self::MAX_BATCH,
                ),
                'method'       => array(
                    'type'        => 'string',
                    'description' => 'Delivery method. Only email is available in this runtime.',
                    'enum'        => array('email', 'sms', 'both'),
                    'default'     => 'email',
                ),
                'dry_run'      => array(
                    'type'        => 'boolean',
                    'description' => 'Preview without sending.',
                    'default'     => true,
                ),
                'include_ical' => array(
                    'type'        => 'boolean',
                    'description' => 'Attach a calendar invitation to each confirmation.',
                    'default'     => true,
                ),
                'resend'       => array(
                    'type'        => 'boolean',
                    'description' => 'Send even when the booking was already confirmed at this revision.',
                    'default'     => false,
                ),
            ),
            'required'             => array('booking_ids'),
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
        $ids    = $this->intListParam($arguments, 'booking_ids');

        if (array() === $ids) {
            return $this->errors->validationFailed(
                'At least one booking ID is required.',
                array('booking_ids' => array('A non-empty list of appointment IDs is required.'))
            );
        }

        if (count($ids) > self::MAX_BATCH) {
            return $this->errors->validationFailed(
                sprintf('At most %d bookings may be confirmed in one call.', self::MAX_BATCH),
                array('booking_ids' => array('Too many bookings.'))
            );
        }

        $method = strtolower(trim($this->stringParam($arguments, 'method', 'email')));

        if ('email' !== $method) {
            return $this->errors->validationFailed(
                'Only email delivery is available in this runtime; no SMS transport is bound.',
                array('method' => array('Not available.'))
            );
        }

        if (! $this->email->isAvailable()) {
            return $this->errors->create('mail_unavailable', 'Email service not available.');
        }

        $dryRun   = $this->boolParam($arguments, 'dry_run', true);
        $include  = $this->boolParam($arguments, 'include_ical', true);
        $resend   = $this->boolParam($arguments, 'resend');

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
                $errors[] = array('booking_id' => $id, 'error' => 'Invalid appointment.');

                continue;
            }

            $email = CalendarNotification::email($item);
            $row   = array(
                'booking_id'       => $item->id,
                'client_name'      => AppointmentDefinition::string($item->meta[ '_client_name' ] ?? ''),
                'client_email'     => $email,
                'appointment_type' => AppointmentDefinition::string($item->meta[ '_appointment_type' ] ?? ''),
                'start_time'       => CalendarNotification::time($item, '_start_time'),
                'end_time'         => CalendarNotification::time($item, '_end_time'),
                'status'           => '',
                'message_id'       => '',
            );

            if ('' === $email || ! $this->email->validateRecipient($email)) {
                ++$skipped;
                $row['status'] = 'skipped';
                $recipients[]  = $row;
                $errors[]      = array('booking_id' => $item->id, 'error' => 'No valid client email.');

                continue;
            }

            $sequence = CalendarNotification::sequence($item);
            $previous = CalendarNotification::sentEntry($item, CalendarNotification::KIND_CONFIRMATION, $sequence);

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

            $ics = $include
                ? CalendarNotification::invite($item, $this->identity, '', '', $sequence)
                : '';

            $message = array(
                'to'      => $email,
                'subject' => sprintf('Appointment Confirmation #%d', $item->id),
                'body'    => $this->body($item),
                'is_html' => false,
            );

            $attachments = CalendarNotification::attachment($ics, $item->id);

            if (array() !== $attachments) {
                $message['attachments'] = $attachments;
            }

            [$sent, $error, $messageId] = CalendarNotification::send($this->email, $message);

            if (! $sent) {
                ++$failed;
                $row['status'] = 'failed';
                $recipients[]  = $row;
                $errors[]      = array(
                    'booking_id' => $item->id,
                    'error'      => $error ?? 'The confirmation could not be sent.',
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
                            '_confirmation_sent_at' => $sentAt,
                            '_confirmation_method'  => 'email',
                            CalendarNotification::META_LOG => CalendarNotification::withEntry(
                                $item,
                                CalendarNotification::KIND_CONFIRMATION,
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
                $errors[]      = array('booking_id' => $item->id, 'error' => $denied->getMessage());

                continue;
            } catch (\Throwable $failure) {
                ++$failed;
                $row['status'] = 'failed';
                $recipients[]  = $row;
                $errors[]      = array('booking_id' => $item->id, 'error' => $failure->getMessage());

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
                'No confirmation was delivered.',
                $data
            );
        }

        $message = $dryRun
            ? sprintf('Dry run: %d booking(s) previewed, %d already confirmed, %d skipped.', $processed, $already, $skipped)
            : sprintf(
                'Confirmations: %d sent, %d already confirmed, %d failed, %d skipped.',
                $processed,
                $already,
                $failed,
                $skipped
            );

        return $this->success($message, $data);
    }

    /**
     * The message body for one booking.
     */
    private function body(ContentItem $item): string
    {
        $name  = AppointmentDefinition::string($item->meta[ '_client_name' ] ?? '');
        $start = CalendarNotification::time($item, '_start_time');
        $end   = CalendarNotification::time($item, '_end_time');

        $body = sprintf("Hello %s,\n\nYour appointment is confirmed.\n", '' !== $name ? $name : 'there');

        if ('' !== $start) {
            $body .= '' !== $end
                ? sprintf("Time: %s to %s\n", $start, $end)
                : sprintf("Time: %s\n", $start);
        }

        return $body . "\nThank you for booking with us!";
    }
}
