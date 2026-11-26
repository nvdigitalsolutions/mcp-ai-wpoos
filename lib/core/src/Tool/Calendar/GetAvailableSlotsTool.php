<?php

/**
 * List the bookable slots for one date.
 *
 * Port of `WP_MCP_AI_Tool_Get_Available_Slots` (Proposal 164 section 164.83).
 * The grid comes from the weekday's business-hours rule; appointments that
 * overlap a slot remove it, and so do blocked ranges.
 *
 * ## Corrections
 *
 * 1. **Blocked ranges were ignored.** The tool's own guidance promised they
 *    would be respected, and they were not: a blocked morning still listed its
 *    slots. They are excluded now.
 * 2. **`buffer_time` was ignored.** A rule stored a buffer and no reader used
 *    it. The grid advances by `slot_duration + buffer_time`, so consecutive
 *    offers keep the configured gap. `create_appointment` still accepts a
 *    back-to-back booking, as it always did.
 * 3. **The weekday came from the server's timezone.** `gmdate('l',
 *    strtotime($date))` shifts the date west of Greenwich. The port reads the
 *    date as UTC and validates its exact shape.
 * 4. **The duration is bounded.** WordPress passed `absint` straight into the
 *    loop, so `0` produced zero-length slots and a huge value produced none;
 *    it is clamped to 15–480 minutes.
 *
 * ## Empty results are explained
 *
 * WordPress returned an empty list for a disabled day, a missing rule, a fully
 * booked day and a fully blocked one alike. The port also returns
 * `day_of_week` and the effective `business_hours` rule, so a caller can tell
 * why a date offered nothing.
 *
 * Past slots are listed, as they were before: the runtime allows backfilling an
 * appointment, so "available" means "not taken", not "in the future".
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
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Tool\AbstractTool;

class GetAvailableSlotsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'get_available_slots';
    }

    public function getName(): string
    {
        return 'Get Available Slots';
    }

    public function getDescription(): string
    {
        return 'Lists the bookable time slots for a date, derived from the configured business hours and excluding booked and blocked ranges.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'date'             => array(
                    'type'        => 'string',
                    'description' => 'Date to check (Y-m-d, UTC).',
                ),
                'duration_minutes' => array(
                    'type'        => 'integer',
                    'minimum'     => AppointmentDefinition::MIN_DURATION,
                    'maximum'     => AppointmentDefinition::MAX_DURATION,
                    'description' => 'Required booking duration in minutes. Defaults to 60.',
                    'default'     => 60,
                ),
            ),
            'required'             => array('date'),
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
        return CapabilityFlagVocabulary::LOCAL_READ;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $date = $this->stringParam($arguments, 'date');
        $day  = '' === $date ? null : AvailabilityDefinition::weekday($date);

        if (null === $day) {
            return $this->errors->validationFailed(
                'A valid date is required.',
                array('date' => array('Use Y-m-d, for example 2026-02-01.'))
            );
        }

        $duration = max(
            AppointmentDefinition::MIN_DURATION,
            min(
                AppointmentDefinition::MAX_DURATION,
                $this->intParam($arguments, 'duration_minutes', AppointmentDefinition::DEFAULT_DURATION)
            )
        );

        $rules = AvailabilityDefinition::rules($this->settings);
        $slots = AvailabilityDefinition::slots($this->content, $rules, $date, $duration);
        $rule  = AvailabilityDefinition::slotRule($rules, $day);

        return $this->success(
            sprintf(
                'Found %d available slot(s) on %s.',
                count($slots),
                $date
            ),
            array(
                'date'             => $date,
                'day_of_week'      => $day,
                'business_hours'   => $rule,
                'duration_minutes' => $duration,
                'available_slots'  => $slots,
                'total_slots'      => count($slots),
            )
        );
    }
}
