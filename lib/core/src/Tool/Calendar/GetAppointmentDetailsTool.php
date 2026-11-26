<?php

/**
 * Read one calendar appointment.
 *
 * Port of `WP_MCP_AI_Tool_Get_Appointment_Details` (Proposal 164 section
 * 164.82). The record is projected from the same `_`-prefixed meta family the
 * writers store, so a booking created by any of the other four tools reads back
 * without a second code path.
 *
 * ## Two adaptations
 *
 * - **The store's read rule applies.** WordPress checked only `manage_options`;
 *   the port honours `userCanAccess(..., 'read')`, so a host can scope
 *   appointments per user without changing the tool.
 * - **Times are returned in both forms.** `start_time`/`end_time` keep the
 *   stored `Y-m-d H:i:s` (UTC) so a caller can write them back unchanged, and
 *   `start_time_iso`/`end_time_iso` are machine-readable. WordPress returned
 *   only the stored string.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class GetAppointmentDetailsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_appointment_details';
    }

    public function getName(): string
    {
        return 'Get Appointment Details';
    }

    public function getDescription(): string
    {
        return 'Retrieves one appointment with its client, time, status, notes and, on request, its audit history and custom metadata.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'appointment_id'   => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Appointment ID to retrieve (required).',
                ),
                'include_history'  => array(
                    'type'        => 'boolean',
                    'description' => 'Include the change and activity history.',
                    'default'     => false,
                ),
                'include_metadata' => array(
                    'type'        => 'boolean',
                    'description' => 'Include custom `_custom_` metadata fields.',
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
        return CapabilityFlagVocabulary::LOCAL_READ;
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

        if (! $this->content->userCanAccess($appointmentId, $userId, 'read')) {
            return $this->errors->forbidden('You do not have permission to view this appointment.');
        }

        $details = AppointmentDefinition::project(
            $item,
            $this->boolParam($arguments, 'include_history', false),
            $this->boolParam($arguments, 'include_metadata', false)
        );

        return $this->success(
            sprintf('Appointment #%d retrieved.', $appointmentId),
            array('appointment' => $details)
        );
    }
}
