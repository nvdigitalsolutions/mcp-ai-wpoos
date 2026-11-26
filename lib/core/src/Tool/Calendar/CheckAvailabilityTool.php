<?php

/**
 * Is one specific window bookable?
 *
 * Port of `WP_MCP_AI_Tool_Check_Availability` (Proposal 164 section 164.83).
 * Three questions, each independently switchable: does an appointment overlap
 * the window, is the window inside business hours, and is it blocked.
 *
 * ## Corrections
 *
 * 1. **Business hours were only a weekday test.** WordPress returned true as
 *    soon as the day was enabled, so a 22:00 booking on an enabled Monday was
 *    reported as within hours. The port requires the whole span to sit inside
 *    the day's open–close window.
 * 2. **The conflict query missed contained appointments** — see
 *    {@see AppointmentDefinition::conflicts()}.
 * 3. **Times are validated strictly and read as UTC**, where WordPress accepted
 *    any `strtotime()` expression.
 *
 * ## `per_system`
 *
 * WordPress reported a `per_system` map so a caller could see which booking
 * system refused. Only the native system exists here, so the map has one entry;
 * it is kept because a host that routes bookings elsewhere may want to add
 * keys, and because clients already read it.
 *
 * The tool is read-only and declares `edit_posts`, the capability WordPress
 * declared and never checked.
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

class CheckAvailabilityTool extends AbstractTool implements ToolCapabilityFlagsInterface
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
        return 'check_availability';
    }

    public function getName(): string
    {
        return 'Check Availability';
    }

    public function getDescription(): string
    {
        return 'Checks whether one time window is bookable: no overlapping appointment, inside business hours, and not blocked.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'start_time'           => array(
                    'type'        => 'string',
                    'description' => 'Start of the window to check (Y-m-d H:i:s UTC or ISO 8601).',
                ),
                'end_time'             => array(
                    'type'        => 'string',
                    'description' => 'End of the window to check (Y-m-d H:i:s UTC or ISO 8601).',
                ),
                'check_business_hours' => array(
                    'type'        => 'boolean',
                    'description' => 'Validate against the configured business hours.',
                    'default'     => true,
                ),
                'check_blocked_times'  => array(
                    'type'        => 'boolean',
                    'description' => 'Check for blocked ranges.',
                    'default'     => true,
                ),
            ),
            'required'             => array('start_time', 'end_time'),
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

        $span = AppointmentDefinition::span(
            $arguments['start_time'] ?? null,
            $arguments['end_time'] ?? null,
            null
        );

        if (null === $span) {
            return $this->errors->validationFailed(
                'The window is not valid.',
                array('start_time' => array('Use Y-m-d H:i:s (UTC) or ISO 8601, with the end after the start.'))
            );
        }

        [$start, $end] = $span;

        $available = true;
        $reasons   = array();

        $conflicts = AppointmentDefinition::conflicts($this->content, $start, $end);

        if (array() !== $conflicts['ids']) {
            $available = false;
            $reasons[] = sprintf(
                '%d existing appointment(s) conflict with this time slot.',
                count($conflicts['ids'])
            );
        }

        if (
            $this->boolParam($arguments, 'check_business_hours', true)
            && ! AvailabilityDefinition::withinHours(AvailabilityDefinition::rules($this->settings), $start, $end)
        ) {
            $available = false;
            $reasons[] = 'Time slot is outside business hours.';
        }

        if ($this->boolParam($arguments, 'check_blocked_times', true)) {
            $blocks = AvailabilityDefinition::blocks($this->content, $start, $end);

            if (array() !== $blocks['ids']) {
                $available = false;
                $reasons[] = 'Time slot has been blocked.';
            }
        }

        $message = $available
            ? 'Time slot is available.'
            : 'Time slot is not available.';

        return $this->success(
            $message,
            array(
                'available'  => $available,
                'start_time' => $start,
                'end_time'   => $end,
                'per_system' => array(
                    'nvoos' => array(
                        'available' => array() === $conflicts['ids'],
                        'conflicts' => $conflicts['ids'],
                        'truncated' => $conflicts['truncated'],
                    ),
                ),
                'conflicts'  => $conflicts['ids'],
                'reasons'    => $reasons,
                'message'    => $message,
            )
        );
    }
}
