<?php

/**
 * List the bookings awaiting confirmation.
 *
 * Port of `WP_MCP_AI_Tool_Get_Unconfirmed_Bookings` (Proposal 164 section
 * 164.85). A booking is unconfirmed when its status is `pending` and no
 * confirmation timestamp exists.
 *
 * ## Corrections
 *
 * WordPress matched `_appointment_status IN ('unconfirmed', 'pending', '')`,
 * but the writers store `_status` and never write `unconfirmed`; it also
 * ordered and filtered on keys no writer sets. The port reads `_status`,
 * `_start_time` and `_appointment_type` (see {@see AppointmentTriage}).
 *
 * `create_appointment` now stamps `_confirmation_sent_at` when its
 * notification is accepted, so a booking confirmed at creation is not listed
 * here on the next call. The row keeps the WordPress keys and adds
 * `client_phone` and the ISO timestamps the appointment readers return.
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

class GetUnconfirmedBookingsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_unconfirmed_bookings';
    }

    public function getName(): string
    {
        return 'Get Unconfirmed Bookings';
    }

    public function getDescription(): string
    {
        return 'Lists pending bookings that have no confirmation timestamp, oldest first, with optional date-range and appointment-type filters.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'date_from'    => array(
                    'type'        => 'string',
                    'description' => 'Only bookings starting on or after this date (Y-m-d).',
                ),
                'date_to'      => array(
                    'type'        => 'string',
                    'description' => 'Only bookings starting on or before this date (Y-m-d).',
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

        $clauses = array(
            AppointmentTriage::statusClause(AppointmentTriage::PENDING),
            AppointmentTriage::unconfirmedClause(),
        );

        foreach ($window['clauses'] as $clause) {
            $clauses[] = $clause;
        }

        $service = AppointmentTriage::serviceClause($this->stringParam($arguments, 'service_type'));

        if (null !== $service) {
            $clauses[] = $service;
        }

        $scan  = AppointmentTriage::scan($this->content, $clauses, 'ASC');
        $items = $scan['items'];

        AppointmentTriage::sort($items, false);

        $limit = max(1, min(
            AppointmentTriage::MAX_LIMIT,
            $this->intParam($arguments, 'limit', AppointmentTriage::DEFAULT_LIMIT)
        ));

        $bookings = array();

        foreach (array_slice($items, 0, $limit) as $item) {
            $bookings[] = AppointmentTriage::row($item);
        }

        return $this->success(
            sprintf('Found %d unconfirmed bookings.', $scan['total']),
            array(
                'count'     => count($bookings),
                'total'     => $scan['total'],
                'bookings'  => $bookings,
                'truncated' => $scan['total'] > $scan['scanned'],
            )
        );
    }
}
