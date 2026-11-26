<?php

/**
 * Partially update a calendar appointment.
 *
 * Port of `WP_MCP_AI_Tool_Update_Appointment` (Proposal 164 section 162.82).
 * Only the supplied fields change; a call that supplies none is a named
 * `no_changes` error rather than a silent success.
 *
 * ## Corrections
 *
 * 1. **No per-record permission check.** WordPress checked only
 *    `manage_options` and then wrote to any id, so an operator who could not
 *    edit the record could still change it. The port honours the content
 *    store's edit rule (`userCanAccess(..., 'edit')`).
 * 2. **A start-only update kept the old end.** Moving the start forward without
 *    supplying an end left the previous end in place, which could precede the
 *    new start; WordPress then stored a negative-length booking. The port
 *    keeps the **duration** instead, the same rule rescheduling uses.
 * 3. **An end before the start was accepted.** WordPress checked only that
 *    both parsed. The port refuses it.
 * 4. **The overlap query missed contained appointments** — see
 *    {@see AppointmentDefinition::conflicts()}.
 *
 * ## The audit trail
 *
 * Each successful call appends `{timestamp, user_id, changes}` to
 * `_change_history`, which is what `get_appointment_details(include_history)`
 * reads. The timestamp is UTC.
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
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class UpdateAppointmentTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'update_appointment';
    }

    public function getName(): string
    {
        return 'Update Appointment';
    }

    public function getDescription(): string
    {
        return 'Updates an existing appointment. Only the supplied fields change. Supports client details, time slots, status, conflict detection and an update notification.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID to update (required).',
                ),
                'client_name'       => array(
                    'type'        => 'string',
                    'description' => 'Updated client name.',
                ),
                'client_email'      => array(
                    'type'        => 'string',
                    'format'      => 'email',
                    'description' => 'Updated client email.',
                ),
                'client_phone'      => array(
                    'type'        => 'string',
                    'description' => 'Updated client phone.',
                ),
                'appointment_type'  => array(
                    'type'        => 'string',
                    'description' => 'Updated appointment type.',
                ),
                'start_time'        => array(
                    'type'        => 'string',
                    'description' => 'Updated start time (Y-m-d H:i:s UTC or ISO 8601).',
                ),
                'end_time'          => array(
                    'type'        => 'string',
                    'description' => 'Updated end time (Y-m-d H:i:s UTC or ISO 8601).',
                ),
                'location'          => array(
                    'type'        => 'string',
                    'description' => 'Updated location.',
                ),
                'notes'             => array(
                    'type'        => 'string',
                    'description' => 'Updated notes.',
                ),
                'status'            => array(
                    'type'        => 'string',
                    'enum'        => AppointmentDefinition::STATUSES,
                    'description' => 'Updated status.',
                ),
                'send_notification' => array(
                    'type'        => 'boolean',
                    'description' => 'Send an update notification to the client.',
                    'default'     => true,
                ),
                'check_conflicts'   => array(
                    'type'        => 'boolean',
                    'description' => 'Check for overlapping appointments when the time changes.',
                    'default'     => true,
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
            return $this->errors->forbidden('You do not have permission to update this appointment.');
        }

        $meta    = $item->meta;
        $changes = array();
        $title   = null;
        $content = null;

        if (isset($arguments['client_name']) && is_scalar($arguments['client_name'])) {
            $changes['client_name'] = Text::singleLine((string) $arguments['client_name']);
            $meta['_client_name']   = $changes['client_name'];
        }

        if (isset($arguments['client_email'])) {
            $email = trim($this->stringParam($arguments, 'client_email'));

            if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->errors->create('invalid_email', 'Invalid email address.');
            }

            $changes['client_email'] = $email;
            $meta['_client_email']   = $email;
        }

        if (isset($arguments['client_phone']) && is_scalar($arguments['client_phone'])) {
            $changes['client_phone'] = Text::singleLine((string) $arguments['client_phone']);
            $meta['_client_phone']   = $changes['client_phone'];
        }

        if (isset($arguments['appointment_type']) && is_scalar($arguments['appointment_type'])) {
            $changes['appointment_type'] = Text::sanitizeKey((string) $arguments['appointment_type']);
            $meta['_appointment_type']   = $changes['appointment_type'];
        }

        if (isset($arguments['start_time']) || isset($arguments['end_time'])) {
            $oldStart = AppointmentDefinition::string($meta['_start_time'] ?? '');
            $oldEnd   = AppointmentDefinition::string($meta['_end_time'] ?? '');

            $startArg = isset($arguments['start_time']) ? $arguments['start_time'] : $oldStart;
            $endArg   = isset($arguments['end_time']) ? $arguments['end_time'] : null;

            if (null === $endArg) {
                // Only the start moved: keep the duration, not the old end,
                // which may now precede the start.
                $from   = AppointmentDefinition::parseTime($startArg);
                $endArg = null === $from
                    ? null
                    : $from->modify('+' . AppointmentDefinition::minutesBetween($oldStart, $oldEnd) . ' minutes')
                        ->format(AppointmentDefinition::TIME_FORMAT);
            }

            $span = null === $endArg ? null : AppointmentDefinition::span($startArg, $endArg, null);

            if (null === $span) {
                return $this->errors->validationFailed(
                    'The appointment time is not valid.',
                    array('start_time' => array('Use a calendar time with the end after the start.'))
                );
            }

            [$start, $end] = $span;

            if ($this->boolParam($arguments, 'check_conflicts', true)) {
                $found = AppointmentDefinition::conflicts($this->content, $start, $end, $appointmentId);

                if (array() !== $found['ids']) {
                    return $this->errors->create(
                        'scheduling_conflict',
                        sprintf(
                            'Scheduling conflict detected. %d existing appointment(s) overlap with this time slot.',
                            count($found['ids'])
                        ),
                        array('conflicts' => $found['ids'], 'truncated' => $found['truncated'])
                    );
                }
            }

            $changes['start_time'] = $start;
            $changes['end_time']   = $end;
            $meta['_start_time']   = $start;
            $meta['_end_time']     = $end;
            $title                 = AppointmentDefinition::title(
                AppointmentDefinition::string($meta['_client_name'] ?? ''),
                $start
            );
        }

        if (isset($arguments['location']) && is_scalar($arguments['location'])) {
            $changes['location'] = Text::singleLine((string) $arguments['location']);
            $meta['_location']   = $changes['location'];
        }

        if (isset($arguments['notes']) && is_scalar($arguments['notes'])) {
            $changes['notes'] = Text::multiline((string) $arguments['notes']);
            $content          = $changes['notes'];
        }

        if (isset($arguments['status'])) {
            $status = Text::sanitizeKey((string) $arguments['status']);

            if (! in_array($status, AppointmentDefinition::STATUSES, true)) {
                return $this->errors->create('invalid_status', 'Invalid appointment status.');
            }

            $changes['status'] = $status;
            $meta['_status']   = $status;
        }

        if (array() === $changes) {
            return $this->errors->create('no_changes', 'No changes were made to the appointment.');
        }

        $meta = AppointmentDefinition::appendLog(
            $meta,
            '_change_history',
            array(
                'timestamp' => gmdate(AppointmentDefinition::TIME_FORMAT),
                'user_id'   => $userId,
                'changes'   => $changes,
            )
        );

        try {
            $item = $this->content->update(
                $appointmentId,
                new UpdateContentCommand(
                    userId: $userId,
                    title: $title,
                    content: $content,
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
            ? $this->notify($item, $changes)
            : array(false, null);

        $data = array(
            'appointment_id'    => $appointmentId,
            'changes'           => $changes,
            'notification_sent' => $sent,
            'message'           => 'Appointment updated successfully.',
        );

        if (null !== $sendError) {
            $data['notification_error'] = $sendError;
        }

        return $this->success('Appointment updated successfully.', $data);
    }

    /**
     * Send the update notification WordPress sent, listing each change.
     *
     * @param array<string, mixed> $changes Applied changes.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function notify(ContentItem $item, array $changes): array
    {
        $email = AppointmentDefinition::string($item->meta['_client_email'] ?? '');

        if ('' === $email) {
            return array(false, 'The appointment has no client email.');
        }

        if (null === $this->email || ! $this->email->isAvailable()) {
            return array(false, 'This host has no email service.');
        }

        $body = "Your appointment has been updated.\n\nChanges:\n";

        foreach ($changes as $field => $value) {
            $body .= sprintf(
                "- %s: %s\n",
                ucwords(str_replace('_', ' ', (string) $field)),
                is_scalar($value) ? (string) $value : json_encode($value)
            );
        }

        try {
            $result = $this->email->send(
                array(
                    'to'      => $email,
                    'subject' => sprintf('Appointment Update #%d', $item->id),
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
