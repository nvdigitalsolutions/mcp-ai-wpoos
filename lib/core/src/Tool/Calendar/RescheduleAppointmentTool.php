<?php

/**
 * Move a calendar appointment to a new time.
 *
 * Port of `WP_MCP_AI_Tool_Reschedule_Appointment` (Proposal 164 section
 * 164.82). The old span is kept in the audit log; only the stored times, the
 * title and the reschedule markers change.
 *
 * ## Corrections and adaptations
 *
 * - **The title now follows the time.** WordPress embedded the start time in
 *   the post title and then left it behind, so a moved appointment still
 *   advertised its old slot in every listing. The port regenerates the title
 *   from the client name and the new start.
 * - **The store's edit rule applies**; WordPress checked only `manage_options`.
 * - **An end before the start is refused**; the old duration is preserved when
 *   `new_end_time` is omitted, as before.
 * - **The overlap query is corrected** — see
 *   {@see AppointmentDefinition::conflicts()}.
 *
 * The past-time check is kept: moving a booking into the past is almost always
 * a mistake, and WordPress refused it.
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

class RescheduleAppointmentTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'reschedule_appointment';
    }

    public function getName(): string
    {
        return 'Reschedule Appointment';
    }

    public function getDescription(): string
    {
        return 'Moves an existing appointment to a new time, keeping the original span in the audit log, checking conflicts and optionally notifying the client.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID to reschedule (required).',
                ),
                'new_start_time'    => array(
                    'type'        => 'string',
                    'description' => 'New start time (Y-m-d H:i:s UTC or ISO 8601, required).',
                ),
                'new_end_time'      => array(
                    'type'        => 'string',
                    'description' => 'New end time. Defaults to the original duration after the new start.',
                ),
                'reason'            => array(
                    'type'        => 'string',
                    'description' => 'Reason for rescheduling.',
                ),
                'send_notification' => array(
                    'type'        => 'boolean',
                    'description' => 'Send a reschedule notification to the client.',
                    'default'     => true,
                ),
                'check_conflicts'   => array(
                    'type'        => 'boolean',
                    'description' => 'Check for overlapping appointments.',
                    'default'     => true,
                ),
            ),
            'required'             => array('appointment_id', 'new_start_time'),
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

        if (! isset($arguments['new_start_time']) || '' === trim((string) $arguments['new_start_time'])) {
            return $this->errors->create('missing_new_start_time', 'New start time is required.');
        }

        $item = $this->content->find($appointmentId);

        if (! $item instanceof ContentItem || AppointmentDefinition::TYPE !== $item->type) {
            return $this->errors->create('invalid_appointment', 'Invalid appointment ID.');
        }

        if (! $this->content->userCanAccess($appointmentId, $userId, 'edit')) {
            return $this->errors->forbidden('You do not have permission to reschedule this appointment.');
        }

        $oldStart = AppointmentDefinition::string($item->meta['_start_time'] ?? '');
        $oldEnd   = AppointmentDefinition::string($item->meta['_end_time'] ?? '');

        $newStart = AppointmentDefinition::parseTime($arguments['new_start_time']);

        if (null === $newStart) {
            return $this->errors->validationFailed(
                'The new start time is not valid.',
                array('new_start_time' => array('Use Y-m-d H:i:s (UTC) or ISO 8601.'))
            );
        }

        $newEnd = isset($arguments['new_end_time']) && '' !== (string) $arguments['new_end_time']
            ? $arguments['new_end_time']
            : $newStart->modify('+' . AppointmentDefinition::minutesBetween($oldStart, $oldEnd) . ' minutes')
                ->format(AppointmentDefinition::TIME_FORMAT);

        $span = AppointmentDefinition::span(AppointmentDefinition::store($newStart), $newEnd, null);

        if (null === $span) {
            return $this->errors->validationFailed(
                'The new time is not valid.',
                array('new_end_time' => array('The end must be after the start.'))
            );
        }

        [$newStartStored, $newEndStored] = $span;

        $newStartTime = AppointmentDefinition::parseTime($newStartStored);

        if (null !== $newStartTime && $newStartTime->getTimestamp() < time()) {
            return $this->errors->create('past_time', 'Cannot reschedule to a time in the past.');
        }

        if ($this->boolParam($arguments, 'check_conflicts', true)) {
            $found = AppointmentDefinition::conflicts($this->content, $newStartStored, $newEndStored, $appointmentId);

            if (array() !== $found['ids']) {
                return $this->errors->create(
                    'scheduling_conflict',
                    sprintf(
                        'Scheduling conflict detected. %d existing appointment(s) overlap with the new time slot.',
                        count($found['ids'])
                    ),
                    array('conflicts' => $found['ids'], 'truncated' => $found['truncated'])
                );
            }
        }

        $rescheduledAt = gmdate(AppointmentDefinition::TIME_FORMAT);
        $reason        = isset($arguments['reason']) && is_scalar($arguments['reason'])
            ? Text::multiline((string) $arguments['reason'])
            : '';

        $meta = $item->meta;
        $meta['_start_time']       = $newStartStored;
        $meta['_end_time']         = $newEndStored;
        $meta['_rescheduled_at']   = $rescheduledAt;
        $meta['_rescheduled_by']   = $userId;

        if ('' !== $reason) {
            $meta['_reschedule_reason'] = $reason;
        }

        $meta = AppointmentDefinition::appendLog(
            $meta,
            '_activity_log',
            array(
                'action'    => 'rescheduled',
                'timestamp' => $rescheduledAt,
                'user_id'   => $userId,
                'old_start' => $oldStart,
                'old_end'   => $oldEnd,
                'new_start' => $newStartStored,
                'new_end'   => $newEndStored,
                'reason'    => $reason,
            )
        );

        $title = AppointmentDefinition::title(
            AppointmentDefinition::string($meta['_client_name'] ?? ''),
            $newStartStored
        );

        try {
            $item = $this->content->update(
                $appointmentId,
                new UpdateContentCommand(userId: $userId, title: $title, meta: $meta)
            );
        } catch (AccessDeniedException $error) {
            return $this->errors->forbidden($error->getMessage());
        } catch (\Throwable $error) {
            return $this->errors->create('appointment_write_failed', $error->getMessage());
        }

        [$sent, $sendError] = $this->boolParam($arguments, 'send_notification', true)
            ? $this->notify($item, $oldStart, $newStartStored, $newEndStored, $reason)
            : array(false, null);

        $data = array(
            'appointment_id'    => $appointmentId,
            'old_start_time'    => $oldStart,
            'old_end_time'      => $oldEnd,
            'new_start_time'    => $newStartStored,
            'new_end_time'      => $newEndStored,
            'rescheduled_at'    => $rescheduledAt,
            'notification_sent' => $sent,
            'message'           => 'Appointment rescheduled successfully.',
        );

        if (null !== $sendError) {
            $data['notification_error'] = $sendError;
        }

        return $this->success('Appointment rescheduled successfully.', $data);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function notify(ContentItem $item, string $oldStart, string $newStart, string $newEnd, string $reason): array
    {
        $email = AppointmentDefinition::string($item->meta['_client_email'] ?? '');

        if ('' === $email) {
            return array(false, 'The appointment has no client email.');
        }

        if (null === $this->email || ! $this->email->isAvailable()) {
            return array(false, 'This host has no email service.');
        }

        $body = sprintf(
            "Hello %1\$s,\n\nYour appointment has been rescheduled.\n\nOriginal time: %2\$s\nNew time: %3\$s to %4\$s\n\n",
            AppointmentDefinition::string($item->meta['_client_name'] ?? ''),
            $oldStart,
            $newStart,
            $newEnd
        );

        if ('' !== $reason) {
            $body .= sprintf("Reason: %s\n\n", $reason);
        }

        $body .= 'Thank you for your understanding!';

        try {
            $result = $this->email->send(
                array(
                    'to'      => $email,
                    'subject' => sprintf('Appointment Rescheduled #%d', $item->id),
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
