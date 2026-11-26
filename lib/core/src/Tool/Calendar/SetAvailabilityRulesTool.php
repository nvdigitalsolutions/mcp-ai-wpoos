<?php

/**
 * Define one day's availability rules.
 *
 * Port of `WP_MCP_AI_Tool_Set_Availability_Rules` (Proposal 164 section
 * 164.83). One call writes one weekday; the other six are untouched.
 *
 * ## The day is replaced, not merged
 *
 * WordPress wrote every field of the day from the arguments or from its
 * defaults, so a call that supplies only `enabled: false` also resets that
 * day's hours to 09:00–17:00, a 60-minute slot and no buffer. The port keeps
 * that contract because the schema documents the defaults — unlike a nested
 * object with optional sub-keys, the caller can see exactly what will be
 * written.
 *
 * ## Corrections
 *
 * - **The clock times are validated.** WordPress stored whatever string it was
 *   given, so `9am` became a rule that no reader could parse. Only `HH:MM` is
 *   accepted.
 * - **A reversed window is refused.** `end_time` before `start_time` produced a
 *   day with no bookable slots at all; the port says so instead.
 * - **The day is validated** against the seven weekdays rather than trusted.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class SetAvailabilityRulesTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'set_availability_rules';
    }

    public function getName(): string
    {
        return 'Set Availability Rules';
    }

    public function getDescription(): string
    {
        return 'Define availability rules and business hours for one day of the week: enabled, open/close clock times, slot duration and buffer between appointments.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'day_of_week'   => array(
                    'type'        => 'string',
                    'description' => 'Day of week.',
                    'enum'        => AvailabilityDefinition::DAYS,
                ),
                'enabled'       => array(
                    'type'        => 'boolean',
                    'description' => 'Enable appointments on this day.',
                    'default'     => true,
                ),
                'start_time'    => array(
                    'type'        => 'string',
                    'description' => 'Opening time (HH:MM, UTC). Defaults to 09:00.',
                ),
                'end_time'      => array(
                    'type'        => 'string',
                    'description' => 'Closing time (HH:MM, UTC). Must be after the opening time. Defaults to 17:00.',
                ),
                'slot_duration' => array(
                    'type'        => 'integer',
                    'minimum'     => AppointmentDefinition::MIN_DURATION,
                    'maximum'     => AppointmentDefinition::MAX_DURATION,
                    'description' => 'Appointment slot duration in minutes. Defaults to 60.',
                ),
                'buffer_time'   => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'maximum'     => 240,
                    'description' => 'Buffer between offered slots in minutes. Defaults to 0.',
                ),
            ),
            'required'             => array('day_of_week'),
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

        $day = Text::sanitizeKey($this->stringParam($arguments, 'day_of_week'));

        if (! in_array($day, AvailabilityDefinition::DAYS, true)) {
            return $this->errors->validationFailed(
                'A valid day of the week is required.',
                array('day_of_week' => array('Use one of: ' . implode(', ', AvailabilityDefinition::DAYS) . '.'))
            );
        }

        $start = AvailabilityDefinition::clock(
            isset($arguments['start_time']) && '' !== $arguments['start_time']
                ? $arguments['start_time']
                : AvailabilityDefinition::DEFAULT_START
        );
        $end = AvailabilityDefinition::clock(
            isset($arguments['end_time']) && '' !== $arguments['end_time']
                ? $arguments['end_time']
                : AvailabilityDefinition::DEFAULT_END
        );

        if (null === $start) {
            return $this->errors->validationFailed(
                'The opening time is not a clock time.',
                array('start_time' => array('Use HH:MM, for example 09:00.'))
            );
        }

        if (null === $end) {
            return $this->errors->validationFailed(
                'The closing time is not a clock time.',
                array('end_time' => array('Use HH:MM, for example 17:00.'))
            );
        }

        if ($end <= $start) {
            return $this->errors->validationFailed(
                'The closing time must be after the opening time.',
                array('end_time' => array('Overnight windows are not supported.'))
            );
        }

        $rule = array(
            'enabled'       => $this->boolParam($arguments, 'enabled', true),
            'start_time'    => $start,
            'end_time'      => $end,
            'slot_duration' => max(
                AppointmentDefinition::MIN_DURATION,
                min(
                    AppointmentDefinition::MAX_DURATION,
                    $this->intParam($arguments, 'slot_duration', AvailabilityDefinition::DEFAULT_SLOT_DURATION)
                )
            ),
            'buffer_time'   => max(0, min(240, $this->intParam($arguments, 'buffer_time', AvailabilityDefinition::DEFAULT_BUFFER))),
        );

        $rules         = AvailabilityDefinition::rules($this->settings);
        $rules[ $day ] = $rule;

        try {
            $this->settings->set(AvailabilityDefinition::SETTINGS_KEY, $rules);
        } catch (\Throwable $error) {
            return $this->errors->create('availability_write_failed', $error->getMessage());
        }

        return $this->success(
            'Availability rules updated successfully.',
            array(
                'day_of_week' => $day,
                'rules'       => $rule,
                'message'     => 'Availability rules updated successfully.',
            )
        );
    }
}
