<?php

/**
 * List the appointments marked no-show.
 *
 * Port of `WP_MCP_AI_Tool_Get_No_Show_Appointments` (Proposal 164 section
 * 164.85). Read-only: it reports what is stored and never marks a status.
 *
 * ## Corrections
 *
 * WordPress queried `_appointment_status = 'no_show'`, `_appointment_date` and
 * `_service_type`. The writers store `_status` with the hyphenated `no-show`,
 * `_start_time` and `_appointment_type`, so the original could not match an
 * appointment the toolkit created. See {@see AppointmentTriage} for the full
 * mapping.
 *
 * The row keeps the WordPress keys. `status` now reports the stored value
 * instead of a hard-coded literal, and `notes` reads the record content where
 * the writer kept them. Newest-first ordering is applied in PHP because the
 * store cannot order by meta; `truncated` reports when the scan ceiling was
 * smaller than the collection.
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
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class GetNoShowAppointmentsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_no_show_appointments';
    }

    public function getName(): string
    {
        return 'Get No-Show Appointments';
    }

    public function getDescription(): string
    {
        return 'Lists appointments marked no-show, newest first, with optional date-range and appointment-type filters. Read-only: it never changes a status.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'date_from'    => array(
                    'type'        => 'string',
                    'description' => 'Only appointments starting on or after this date (Y-m-d).',
                ),
                'date_to'      => array(
                    'type'        => 'string',
                    'description' => 'Only appointments starting on or before this date (Y-m-d).',
                ),
                'service_type' => array(
                    'type'        => 'string',
                    'description' => 'Only this appointment type, as stored in the appointment type field (optional).',
                ),
                'limit'        => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of results to return.',
                    'default'     => AppointmentTriage::DEFAULT_LIMIT,
                    'minimum'     => 1,
                    'maximum'     => AppointmentTriage::MAX_LIMIT,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
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
        $this->authorize($context, 'read');

        $window = AppointmentTriage::window(
            $arguments['date_from'] ?? null,
            $arguments['date_to'] ?? null
        );

        if (null !== $window['error']) {
            return $this->errors->validationFailed(
                $window['error']['message'],
                array($window['error']['field'] => array($window['error']['message']))
            );
        }

        $clauses = array(AppointmentTriage::statusClause(AppointmentTriage::NO_SHOW));

        foreach ($window['clauses'] as $clause) {
            $clauses[] = $clause;
        }

        $service = AppointmentTriage::serviceClause($this->stringParam($arguments, 'service_type'));

        if (null !== $service) {
            $clauses[] = $service;
        }

        $scan  = AppointmentTriage::scan($this->content, $clauses, 'DESC');
        $items = $scan['items'];

        AppointmentTriage::sort($items, true);

        $limit = max(1, min(
            AppointmentTriage::MAX_LIMIT,
            $this->intParam($arguments, 'limit', AppointmentTriage::DEFAULT_LIMIT)
        ));

        $appointments = array();

        foreach (array_slice($items, 0, $limit) as $item) {
            $appointments[] = AppointmentTriage::row($item);
        }

        return $this->success(
            sprintf('Found %d no-show appointments.', $scan['total']),
            array(
                'count'        => count($appointments),
                'total'        => $scan['total'],
                'appointments' => $appointments,
                'truncated'    => $scan['total'] > $scan['scanned'],
            )
        );
    }
}
