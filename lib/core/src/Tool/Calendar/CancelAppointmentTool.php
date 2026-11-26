<?php

/**
 * Cancel a calendar appointment.
 *
 * Port of `WP_MCP_AI_Tool_Cancel_Appointment` (Proposal 164 section 164.82).
 * Cancelling is a status change, not a deletion: the record keeps its client,
 * its time and its audit trail, which is what the WordPress tool did.
 *
 * ## Corrections and adaptations
 *
 * - **The store's edit rule applies.** WordPress checked `manage_options` and
 *   then wrote any id; the port honours `userCanAccess(..., 'edit')`.
 * - **`cancelled_by` is validated.** WordPress stored whatever string it was
 *   given; the port accepts the documented `client` / `staff` / `system` and
 *   falls back to `staff`, reporting the effective value.
 * - **Refunds are not ported.** WordPress raised the
 *   `wp_mcp_ai_process_appointment_refund` filter and recorded the result; the
 *   core has no refund gateway and no filter that can return a value.
 *   `process_refund: true` therefore reports `refund_processed: false` with a
 *   `refund_error`, and nothing writes `_refund_processed`.
 * - **The reschedule link is prose.** WordPress interpolated
 *   `home_url( '/book-appointment/' )`; the core has no site URL, so the email
 *   asks the client to reply instead of linking to a page that may not exist.
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
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CancelAppointmentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly ?EmailServiceInterface $email = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'cancel_appointment';
    }

    public function getName(): string
    {
        return 'Cancel Appointment';
    }

    public function getDescription(): string
    {
        return 'Cancels an existing appointment, records who cancelled it and why, and optionally notifies the client.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID to cancel (required).',
                ),
                'reason'            => array(
                    'type'        => 'string',
                    'description' => 'Reason for cancellation.',
                ),
                'cancelled_by'      => array(
                    'type'        => 'string',
                    'enum'        => AppointmentDefinition::CANCELLED_BY,
                    'description' => 'Who initiated the cancellation.',
                    'default'     => 'staff',
                ),
                'send_notification' => array(
                    'type'        => 'boolean',
                    'description' => 'Send a cancellation notification to the client.',
                    'default'     => true,
                ),
                'offer_reschedule'  => array(
                    'type'        => 'boolean',
                    'description' => 'Offer the client a reschedule in the notification.',
                    'default'     => false,
                ),
                'process_refund'    => array(
                    'type'        => 'boolean',
                    'description' => 'Request a refund. No refund gateway is bound in this runtime, so the response says so.',
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
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $userId        = (int) ( $context['user_id'] ?? 0 );
        $appointmentId = max(0, $this->intParam($arguments, 'appointment_id', 0));

        if ($appointmentId <= 0) {
            return $this->errors->create('missing_appointment_id', 'Appointment ID is required.');
        }

        $item = $this->content->find($appointmentId);

        if (! $item instanceof ContentItem || AppointmentDefinition::TYPE !== $item->type) {
            return $this->errors->create('invalid_appointment', 'Invalid appointment ID.');
        }

        if (! $this->content->userCanAccess($appointmentId, $userId, 'edit')) {
            return $this->errors->forbidden('You do not have permission to cancel this appointment.');
        }

        if ('cancelled' === AppointmentDefinition::string($item->meta['_status'] ?? '')) {
            return $this->errors->create(
                'already_cancelled',
                'This appointment has already been cancelled.'
            );
        }

        $actor = Text::sanitizeKey($this->stringParam($arguments, 'cancelled_by', 'staff'));
        $actor = in_array($actor, AppointmentDefinition::CANCELLED_BY, true) ? $actor : 'staff';
        $reason = isset($arguments['reason']) && is_scalar($arguments['reason'])
            ? Text::multiline((string) $arguments['reason'])
            : '';
        $cancelledAt = gmdate(AppointmentDefinition::TIME_FORMAT);

        $meta = $item->meta;
        $meta['_status']                 = 'cancelled';
        $meta['_cancelled_at']           = $cancelledAt;
        $meta['_cancelled_by']           = $actor;
        $meta['_cancelled_by_user_id']   = $userId;
        $meta['_cancellation_reason']    = $reason;
        $meta = AppointmentDefinition::appendLog(
            $meta,
            '_activity_log',
            array(
                'action'       => 'cancelled',
                'timestamp'    => $cancelledAt,
                'user_id'      => $userId,
                'cancelled_by' => $actor,
                'reason'       => $reason,
            )
        );

        try {
            $item = $this->content->update(
                $appointmentId,
                new UpdateContentCommand(userId: $userId, meta: $meta)
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (\Throwable $error) {
            return $this->errors->create('appointment_write_failed', $error->getMessage());
        }

        $refundProcessed = false;
        $refundError     = null;

        if ($this->boolParam($arguments, 'process_refund', false)) {
            $refundError = 'No refund gateway is bound in this runtime.';
        }

        [$sent, $sendError] = $this->boolParam($arguments, 'send_notification', true)
            ? $this->notify($item, $reason, $this->boolParam($arguments, 'offer_reschedule', false))
            : array(false, null);

        $data = array(
            'appointment_id'    => $appointmentId,
            'status'            => 'cancelled',
            'cancelled_at'      => $cancelledAt,
            'cancelled_by'      => $actor,
            'reason'            => $reason,
            'notification_sent' => $sent,
            'refund_processed'  => $refundProcessed,
            'message'           => 'Appointment cancelled successfully.',
        );

        if (null !== $sendError) {
            $data['notification_error'] = $sendError;
        }

        if (null !== $refundError) {
            $data['refund_error'] = $refundError;
        }

        return $this->success('Appointment cancelled successfully.', $data);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function notify(ContentItem $item, string $reason, bool $offerReschedule): array
    {
        $email = AppointmentDefinition::string($item->meta['_client_email'] ?? '');

        if ('' === $email) {
            return array(false, 'The appointment has no client email.');
        }

        if (null === $this->email || ! $this->email->isAvailable()) {
            return array(false, 'This host has no email service.');
        }

        $body = sprintf(
            "Hello %1\$s,\n\nYour appointment scheduled for %2\$s has been cancelled.\n\n",
            AppointmentDefinition::string($item->meta['_client_name'] ?? ''),
            AppointmentDefinition::string($item->meta['_start_time'] ?? '')
        );

        if ('' !== $reason) {
            $body .= sprintf("Reason: %s\n\n", $reason);
        }

        if ($offerReschedule) {
            $body .= "You can reschedule your appointment by replying to this email.\n\n";
        }

        $body .= "We apologize for any inconvenience.\n\nThank you!";

        try {
            $result = $this->email->send(
                array(
                    'to'      => $email,
                    'subject' => sprintf('Appointment Cancelled #%d', $item->id),
                    'body'    => $body,
                    'is_html' => false,
                )
            );
        } catch (\Throwable $error) {
            return array(false, $error->getMessage());
        }

        if (empty($result['success'])) {
            return array(false, (string) ( $result['error'] ?? 'The email could not be sent.' ));
        }

        return array(true, null);
    }
}
