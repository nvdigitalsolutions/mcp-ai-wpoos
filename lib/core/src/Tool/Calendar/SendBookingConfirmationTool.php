<?php

/**
 * Send one booking confirmation.
 *
 * Port of `WP_MCP_AI_Tool_Send_Booking_Confirmation` (Proposal 164 section
 * 164.88).
 *
 * ## Corrections
 *
 * 1. **A failed send is an error.** WordPress returned `success => true` with
 *    `email_sent => false` whenever `wp_mail()` refused the message, so a caller
 *    could not tell a delivery from a failure without reading the flag. The port
 *    returns a named `email_failed` error and writes nothing.
 * 2. **`include_ical` does something.** It was declared, defaulted to `true` and
 *    never read. The invitation is now built and attached, through the
 *    attachment support the email contract declares.
 * 3. **The send is idempotent per revision.** A retried call no longer emails
 *    the client twice; `resend: true` overrides it.
 * 4. **The times are unambiguous.** WordPress printed the stored wall clock,
 *    which it happened to keep in site-local time; this runtime stores UTC, so
 *    the body renders ISO 8601 with an explicit offset.
 * 5. **The capability is the one WordPress enforced.** It declared `edit_posts`
 *    and required `manage_options`.
 *
 * ## Additions
 *
 * `organizer_email` makes the attachment a `METHOD:REQUEST` invitation with an
 * `ORGANIZER` and a `RSVP=TRUE` attendee; without it the document is published.
 * `dry_run` previews the recipient, subject, body and whether an invite would be
 * attached, sending nothing.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class SendBookingConfirmationTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The flags a mail send declares.
     *
     * Outbound and persistent: the message leaves the estate and the record is
     * stamped. A read-only profile must refuse it.
     */
    public const FLAGS = array('external-communication', 'network-dependent', 'state-changing', 'write');

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
        return 'send_booking_confirmation';
    }

    public function getName(): string
    {
        return 'Send Booking Confirmation';
    }

    public function getDescription(): string
    {
        return 'Emails one client to confirm an appointment, optionally attaching an RFC 5545 calendar invitation. Stamps the appointment as confirmed when the transport accepts the message and will not re-send the same revision unless asked.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'   => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID.',
                ),
                'include_ical'     => array(
                    'type'        => 'boolean',
                    'description' => 'Attach a calendar invitation.',
                    'default'     => true,
                ),
                'custom_message'   => array(
                    'type'        => 'string',
                    'description' => 'Extra plain-text note appended to the email.',
                    'maxLength'   => CalendarNotification::MAX_CUSTOM_MESSAGE,
                ),
                'organizer_email'  => array(
                    'type'        => 'string',
                    'format'      => 'email',
                    'description' => 'Organizer address. When given, the invite is a METHOD:REQUEST with an RSVP attendee.',
                ),
                'dry_run'          => array(
                    'type'        => 'boolean',
                    'description' => 'Preview the message without sending.',
                    'default'     => false,
                ),
                'resend'           => array(
                    'type'        => 'boolean',
                    'description' => 'Send even when this revision was already confirmed.',
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

        $custom = $this->customMessage($arguments);

        if (null === $custom) {
            return $this->errors->validationFailed(
                sprintf('custom_message must be at most %d characters.', CalendarNotification::MAX_CUSTOM_MESSAGE),
                array('custom_message' => array('Too long.'))
            );
        }

        $organizer = $this->organizer($arguments);

        if (null === $organizer) {
            return $this->errors->validationFailed(
                'organizer_email must be a valid email address.',
                array('organizer_email' => array('Not a valid address.'))
            );
        }

        $sequence  = CalendarNotification::sequence($item);
        $previous  = CalendarNotification::sentEntry($item, CalendarNotification::KIND_CONFIRMATION, $sequence);
        $resend    = $this->boolParam($arguments, 'resend');
        $dryRun    = $this->boolParam($arguments, 'dry_run');

        if (null !== $previous && ! $resend && ! $dryRun) {
            return $this->success(
                'A confirmation for this revision was already sent.',
                array(
                    'appointment_id' => $item->id,
                    'recipient'      => $recipient,
                    'sent'           => false,
                    'already_sent'   => true,
                    'sent_at'        => $previous['sent_at'],
                    'revision'       => $sequence,
                )
            );
        }

        $subject = sprintf('Appointment Confirmation #%d', $item->id);
        $body    = $this->body($item, $custom);
        $ics     = $this->boolParam($arguments, 'include_ical', true)
            ? CalendarNotification::invite($item, $this->identity, $organizer, $custom, $sequence)
            : '';

        if ($dryRun) {
            return $this->success(
                'Dry run: the confirmation was not sent.',
                array(
                    'dry_run'       => true,
                    'appointment_id' => $item->id,
                    'recipient'     => $recipient,
                    'subject'       => $subject,
                    'body'          => $body,
                    'ical_included' => '' !== $ics,
                    'revision'      => $sequence,
                    'sent'          => false,
                )
            );
        }

        $message = array(
            'to'      => $recipient,
            'subject' => $subject,
            'body'    => $body,
            'is_html' => false,
        );

        $attachments = CalendarNotification::attachment($ics, $item->id);

        if (array() !== $attachments) {
            $message['attachments'] = $attachments;
        }

        [$sent, $error, $messageId] = CalendarNotification::send($this->email, $message);

        if (! $sent) {
            return $this->errors->create(
                'email_failed',
                $error ?? 'The confirmation could not be sent.'
            );
        }

        $sentAt = gmdate(AppointmentDefinition::TIME_FORMAT);
        $recorded = true;
        $recordError = null;

        try {
            $this->content->update(
                $item->id,
                new UpdateContentCommand(
                    userId: $userId,
                    meta: array(
                        '_confirmation_sent_at' => $sentAt,
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
            'ical_included'  => array() !== $attachments,
            'message_id'     => $messageId,
            'sent_at'        => $sentAt,
            'revision'       => $sequence,
            'recorded'       => $recorded,
        );

        if (null !== $recordError) {
            $data['record_error'] = $recordError;
        }

        return $this->success(
            $recorded
                ? 'Confirmation sent successfully.'
                : 'Confirmation sent, but the appointment could not be stamped.',
            $data
        );
    }

    /**
     * The message body.
     */
    private function body(ContentItem $item, string $custom): string
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

        $body .= "\n";

        if ('' !== $custom) {
            $body .= $custom . "\n\n";
        }

        return $body . 'Thank you!';
    }

    /**
     * The cleaned custom message, '' when absent, or null when too long.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function customMessage(array $arguments): ?string
    {
        if (! array_key_exists('custom_message', $arguments) || ! is_scalar($arguments['custom_message'])) {
            return '';
        }

        $custom = Text::multiline((string) $arguments['custom_message']);

        return strlen($custom) > CalendarNotification::MAX_CUSTOM_MESSAGE ? null : $custom;
    }

    /**
     * The organizer address, '' when absent, or null when unusable.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function organizer(array $arguments): ?string
    {
        if (! array_key_exists('organizer_email', $arguments) || ! is_scalar($arguments['organizer_email'])) {
            return '';
        }

        $organizer = trim((string) $arguments['organizer_email']);

        if ('' === $organizer) {
            return '';
        }

        return $this->email->validateRecipient($organizer) ? $organizer : null;
    }
}
