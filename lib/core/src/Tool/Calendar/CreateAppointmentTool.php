<?php

/**
 * Create or replace a calendar appointment.
 *
 * Port of `WP_MCP_AI_Tool_Create_Appointment` (Proposal 164 section 164.82).
 * A call without `appointment_id` creates a booking; a call with one replaces
 * it — WordPress used the same slug for both, and the tool description says so.
 *
 * ## What the port had to translate
 *
 * - **Target system.** WordPress could route a booking into JetAppointment or
 *   JetBooking through `WP_MCP_AI_Booking_Adapter_Factory`. Neither adapter
 *   exists here, so `target_system` accepts `nvoos` only and any other value is
 *   a named validation error rather than a silent fall-through to the native
 *   path (which is what WordPress did when the adapter class was missing).
 * - **Times.** Accepted in the documented `Y-m-d H:i:s` form or ISO 8601 with
 *   an offset, stored in UTC. See {@see AppointmentDefinition}.
 * - **Email.** `wp_mail()` reported a boolean; the core has an optional
 *   `EmailServiceInterface`. A host without one still creates the booking and
 *   reports `notification_sent: false` plus `notification_error`, because
 *   losing a booking over a mail transport would be worse than a missed email.
 *
 * ## Two corrections
 *
 * 1. **Overlap detection missed contained appointments** — see
 *    {@see AppointmentDefinition::conflicts()}.
 * 2. **The replace path reported a status it had not stored.** WordPress left
 *    `_status` untouched on update but returned `confirmed`; a `pending`
 *    appointment was reported as confirmed. The port returns the stored status.
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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CreateAppointmentTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'create_appointment';
    }

    public function getName(): string
    {
        return 'Create Appointment';
    }

    public function getDescription(): string
    {
        return 'Create a new appointment or replace an existing one. If appointment_id is provided, updates the existing appointment instead of creating a new one. Supports client details, time slots, conflict detection and a confirmation email.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Optional appointment ID. If provided, replaces the existing appointment instead of creating a new one.',
                ),
                'client_name'       => array(
                    'type'        => 'string',
                    'description' => 'Client full name (required).',
                ),
                'client_email'      => array(
                    'type'        => 'string',
                    'format'      => 'email',
                    'description' => 'Client email address (required).',
                ),
                'client_phone'      => array(
                    'type'        => 'string',
                    'description' => 'Client phone number.',
                ),
                'appointment_type'  => array(
                    'type'        => 'string',
                    'description' => 'Type of appointment (consultation, meeting, service, …). Defaults to "general".',
                ),
                'start_time'        => array(
                    'type'        => 'string',
                    'description' => 'Start time in Y-m-d H:i:s (UTC) or ISO 8601 with an offset (required).',
                ),
                'end_time'          => array(
                    'type'        => 'string',
                    'description' => 'End time (Y-m-d H:i:s UTC or ISO 8601). Must be after the start.',
                ),
                'duration_minutes'  => array(
                    'type'        => 'integer',
                    'minimum'     => AppointmentDefinition::MIN_DURATION,
                    'maximum'     => AppointmentDefinition::MAX_DURATION,
                    'description' => 'Duration in minutes (alternative to end_time). Defaults to 60.',
                ),
                'location'          => array(
                    'type'        => 'string',
                    'description' => 'Appointment location or virtual meeting link.',
                ),
                'notes'             => array(
                    'type'        => 'string',
                    'description' => 'Additional notes or special requests.',
                ),
                'send_notification' => array(
                    'type'        => 'boolean',
                    'description' => 'Send a confirmation email to the client.',
                    'default'     => true,
                ),
                'check_conflicts'   => array(
                    'type'        => 'boolean',
                    'description' => 'Check for overlapping appointments.',
                    'default'     => true,
                ),
                'metadata'          => array(
                    'type'        => 'object',
                    'description' => 'Additional custom metadata, stored as _custom_<key> values.',
                ),
                'target_system'     => array(
                    'type'        => 'string',
                    'enum'        => array('nvoos'),
                    'description' => 'The booking system to write to. Only "nvoos" exists in this runtime.',
                    'default'     => 'nvoos',
                ),
            ),
            'required'             => array('client_name', 'client_email', 'start_time'),
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

        $userId   = (int) ( $context['user_id'] ?? 0 );
        $target   = Text::sanitizeKey($this->stringParam($arguments, 'target_system', 'nvoos'));

        if ('nvoos' !== $target) {
            return $this->errors->validationFailed(
                sprintf('The "%s" booking system is not available in this runtime.', $target),
                array('target_system' => array('Only "nvoos" is supported.'))
            );
        }

        $clientName = Text::singleLine($this->stringParam($arguments, 'client_name'));
        $clientMail = trim($this->stringParam($arguments, 'client_email'));

        if ('' === $clientName) {
            return $this->errors->create('missing_client_name', 'Client name is required.');
        }

        if ('' === $clientMail || false === filter_var($clientMail, FILTER_VALIDATE_EMAIL)) {
            return $this->errors->create('invalid_email', 'A valid client email address is required.');
        }

        if (! isset($arguments['start_time'])) {
            return $this->errors->create('missing_start_time', 'Appointment start time is required.');
        }

        $span = AppointmentDefinition::span(
            $arguments['start_time'] ?? null,
            $arguments['end_time'] ?? null,
            $arguments['duration_minutes'] ?? null
        );

        if (null === $span) {
            return $this->errors->validationFailed(
                'The appointment time is not valid.',
                array(
                    'start_time' => array('Use Y-m-d H:i:s (UTC) or ISO 8601, with the end after the start.'),
                )
            );
        }

        [$start, $end] = $span;

        $appointmentId = max(0, $this->intParam($arguments, 'appointment_id', 0));
        $existing      = null;

        if ($appointmentId > 0) {
            $existing = $this->content->find($appointmentId);

            if (! $existing instanceof ContentItem || AppointmentDefinition::TYPE !== $existing->type) {
                return $this->errors->create('appointment_not_found', 'Appointment not found.');
            }

            if (! $this->content->userCanAccess($appointmentId, $userId, 'edit')) {
                return $this->errors->forbidden('You do not have permission to update this appointment.');
            }
        }

        if ($this->boolParam($arguments, 'check_conflicts', true)) {
            $conflict = $this->conflicts($start, $end, $appointmentId);

            if (null !== $conflict) {
                return $conflict;
            }
        }

        $status   = null !== $existing
            ? AppointmentDefinition::status($existing->meta['_status'] ?? 'confirmed')
            : 'confirmed';
        $notes    = isset($arguments['notes']) && is_scalar($arguments['notes'])
            ? Text::multiline((string) $arguments['notes'])
            : '';
        $meta     = array(
            '_client_name'      => $clientName,
            '_client_email'     => $clientMail,
            '_client_phone'     => Text::singleLine($this->stringParam($arguments, 'client_phone')),
            '_appointment_type' => Text::sanitizeKey(
                $this->stringParam($arguments, 'appointment_type', 'general')
            ) ?: 'general',
            '_start_time'       => $start,
            '_end_time'         => $end,
            '_location'         => Text::singleLine($this->stringParam($arguments, 'location')),
            '_status'           => $status,
        );

        if (null === $existing) {
            $meta['_created_by'] = $userId;
            $meta['_created_at'] = gmdate(AppointmentDefinition::TIME_FORMAT);
        }

        $meta = array_merge($meta, AppointmentDefinition::customMeta($arguments['metadata'] ?? null));

        try {
            $item = null === $existing
                ? $this->content->create(
                    new CreateContentCommand(
                        title: AppointmentDefinition::title($clientName, $start),
                        type: AppointmentDefinition::TYPE,
                        authorId: $userId,
                        status: 'publish',
                        content: $notes,
                        meta: $meta,
                    )
                )
                : $this->content->update(
                    $appointmentId,
                    new UpdateContentCommand(
                        userId: $userId,
                        title: AppointmentDefinition::title($clientName, $start),
                        content: $notes,
                        meta: $meta,
                    )
                );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (ValidationException $error) {
            return $this->errors->validationFailed(
                $error->getMessage(),
                $error->hasFieldErrors() ? $error->errors : array()
            );
        } catch (\Throwable $error) {
            return $this->errors->create('appointment_write_failed', $error->getMessage());
        }

        [$sent, $sendError] = $this->boolParam($arguments, 'send_notification', true)
            ? $this->notify($item->id, $clientMail, $clientName, $start, $end)
            : array(false, null);

        $message = null === $existing
            ? 'Appointment created successfully.'
            : 'Appointment updated successfully.';

        $data = array(
            'appointment_id'    => $item->id,
            'appointment_title' => $item->title,
            'client_name'       => $clientName,
            'start_time'        => $start,
            'end_time'          => $end,
            'status'            => $status,
            'notification_sent' => $sent,
            'updated'           => null !== $existing,
            'message'           => $message,
        );

        if (null !== $sendError) {
            $data['notification_error'] = $sendError;
        }

        return $this->success($message, $data);
    }

    /**
     * The scheduling-conflict error, or null when the slot is free.
     */
    private function conflicts(string $start, string $end, int $excludeId): mixed
    {
        $found = AppointmentDefinition::conflicts($this->content, $start, $end, $excludeId);

        if (array() === $found['ids']) {
            return null;
        }

        return $this->errors->create(
            'scheduling_conflict',
            sprintf(
                'Scheduling conflict detected. %d existing appointment(s) overlap with this time slot.',
                count($found['ids'])
            ),
            array(
                'conflicts' => $found['ids'],
                'truncated' => $found['truncated'],
            )
        );
    }

    /**
     * Send the confirmation email the WordPress tool sent.
     *
     * @return array{0: bool, 1: string|null} Sent, and the reason when it was not.
     */
    private function notify(int $appointmentId, string $email, string $name, string $start, string $end): array
    {
        if (null === $this->email || ! $this->email->isAvailable()) {
            return array(false, 'This host has no email service.');
        }

        try {
            $result = $this->email->send(
                array(
                    'to'      => $email,
                    'subject' => sprintf('Appointment Confirmation #%d', $appointmentId),
                    'body'    => sprintf(
                        "Hello %1\$s,\n\nYour appointment has been confirmed.\n\nDate/Time: %2\$s to %3\$s\n\nThank you!",
                        $name,
                        $start,
                        $end
                    ),
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
