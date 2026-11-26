<?php

/**
 * Analyse the appointment book and recommend schedule changes.
 *
 * Port of `WP_MCP_AI_Tool_Optimize_Schedule` (Proposal 164 section 164.87).
 *
 * ## The WordPress tool was a stub
 *
 * It returned `utilization_rate: 0.75` and `average_gap_minutes: 30` as
 * literals regardless of the calendar, and both `generate_recommendations()`
 * parameters were unused: every call produced the same hardcoded
 * `consolidation` suggestion with `potential_improvement: '15%'`. Reproducing
 * that in the runtime would be a tool that claims to analyse and does not, so
 * the port implements the measurement (see `ScheduleAnalysis`) and derives the
 * recommendations from it.
 *
 * ## Corrections
 *
 * 1. **The goal is honoured.** `minimize_gaps`, `maximize_bookings` and
 *    `balance_load` produce different, measured recommendations; WordPress
 *    ignored the argument after accepting it.
 * 2. **The goal is validated.** WordPress let any string through and fell back
 *    silently; an unknown value is a caller error now, because the schema
 *    already declares the enum.
 * 3. **No invented percentages.** Each recommendation carries the minutes,
 *    counts and dates it was derived from. WordPress's `potential_improvement`
 *    was a constant, so a caller could not tell a full day from an empty one.
 * 4. **The range is validated and bounded.** Both dates are required
 *    `Y-m-d` values in order, and the range is capped at a year
 *    (`ScheduleAnalysis::MAX_RANGE_DAYS`). WordPress accepted anything and
 *    queried whichever 500 rows came back.
 * 5. **The capability is the one WordPress enforced.** The WordPress class
 *    declared `edit_posts` and required `manage_options` inside `execute()`;
 *    reading the whole book is an administrative act, so the port declares the
 *    enforced capability.
 *
 * ## Adaptations
 *
 * The analysis is read-only and never moves an appointment, which is what the
 * WordPress guidance promised even though its payload was fiction. Capacity
 * comes from the `business_hours` rules and is reported as `unconfigured`
 * rather than guessed. The WordPress `ai-feature` capability flag is not
 * declared: no model is called, so the claim would be false.
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
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class OptimizeScheduleTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** The goals the tool understands. */
    public const GOALS = array('minimize_gaps', 'maximize_bookings', 'balance_load');

    /** The most recommendations one call returns. */
    public const MAX_RECOMMENDATIONS = 5;

    /**
     * The smallest busiest-to-quietest difference worth reporting as an
     * imbalance, in minutes. Below this the load is already even.
     */
    public const BALANCE_THRESHOLD_MINUTES = 30;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly SettingsStoreInterface $settings,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'optimize_schedule';
    }

    public function getName(): string
    {
        return 'Optimize Schedule';
    }

    public function getDescription(): string
    {
        return 'Analyses appointment density, gaps and capacity over a date range and returns measured recommendations for gap reduction, booking growth or load balancing. Read-only: it never moves an appointment.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'date_range_start'  => array(
                    'type'        => 'string',
                    'description' => 'Start date (Y-m-d, inclusive).',
                    'format'      => 'date',
                ),
                'date_range_end'    => array(
                    'type'        => 'string',
                    'description' => 'End date (Y-m-d, inclusive).',
                    'format'      => 'date',
                ),
                'optimization_goal' => array(
                    'type'        => 'string',
                    'description' => 'What to optimise for.',
                    'enum'        => self::GOALS,
                    'default'     => 'minimize_gaps',
                ),
            ),
            'required'             => array('date_range_start', 'date_range_end'),
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

        $start = AppointmentDefinition::date($arguments['date_range_start'] ?? null);
        $end   = AppointmentDefinition::date($arguments['date_range_end'] ?? null);

        if (null === $start || null === $end) {
            $fields = array();

            if (null === $start) {
                $fields['date_range_start'] = array('A valid Y-m-d date is required.');
            }

            if (null === $end) {
                $fields['date_range_end'] = array('A valid Y-m-d date is required.');
            }

            return $this->errors->validationFailed('Date range is required.', $fields);
        }

        if ($end < $start) {
            return $this->errors->validationFailed(
                'date_range_end must be on or after date_range_start.',
                array('date_range_end' => array('The end date precedes the start date.'))
            );
        }

        $rangeDays = ScheduleAnalysis::rangeDays($start, $end);

        if ($rangeDays > ScheduleAnalysis::MAX_RANGE_DAYS) {
            return $this->errors->validationFailed(
                sprintf(
                    'The range covers %d days; the maximum is %d.',
                    $rangeDays,
                    ScheduleAnalysis::MAX_RANGE_DAYS
                ),
                array('date_range_end' => array('Range too long.'))
            );
        }

        $goal = array_key_exists('optimization_goal', $arguments) && is_scalar($arguments['optimization_goal'])
            ? Text::sanitizeKey((string) $arguments['optimization_goal'])
            : '';

        if ('' === $goal) {
            $goal = 'minimize_gaps';
        }

        if (! in_array($goal, self::GOALS, true)) {
            return $this->errors->validationFailed(
                'optimization_goal must be one of: ' . implode(', ', self::GOALS) . '.',
                array('optimization_goal' => array('Unknown goal.'))
            );
        }

        $scan = CalendarScan::scan(
            $this->content,
            new ContentQuery(
                types: array(AppointmentDefinition::TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_start_time',
                        'value'   => array($start . ' 00:00:00', $end . ' 23:59:59'),
                        'compare' => 'BETWEEN',
                    ),
                ),
                orderBy: 'id',
                order: 'ASC',
            )
        );

        $analysis = ScheduleAnalysis::build(
            $scan['items'],
            AvailabilityDefinition::rules($this->settings),
            $start,
            $end
        );

        $analysis['scan'] = array(
            'scanned'   => $scan['scanned'],
            'matched'   => $scan['total'],
            'truncated' => $scan['total'] > $scan['scanned'],
        );

        return $this->success(
            'Schedule analysed.',
            array(
                'date_range'        => array('start' => $start, 'end' => $end),
                'optimization_goal' => $goal,
                'analysis'          => $analysis,
                'recommendations'   => $this->recommendations($analysis, $goal),
            )
        );
    }

    /**
     * The measured recommendations for one goal.
     *
     * @param array<string, mixed> $analysis Built analysis.
     *
     * @return list<array<string, mixed>>
     */
    private function recommendations(array $analysis, string $goal): array
    {
        if ('maximize_bookings' === $goal) {
            return $this->fillRecommendations($analysis);
        }

        if ('balance_load' === $goal) {
            return $this->balanceRecommendations($analysis);
        }

        return $this->gapRecommendations($analysis);
    }

    /**
     * Days with gaps worth consolidating, largest first.
     *
     * @param array<string, mixed> $analysis Built analysis.
     *
     * @return list<array<string, mixed>>
     */
    private function gapRecommendations(array $analysis): array
    {
        $days = array_values(
            array_filter(
                $analysis['days'],
                static fn (array $day): bool => $day['gap_minutes'] > 0
            )
        );

        usort(
            $days,
            static fn (array $a, array $b): int => ( $b['gap_minutes'] <=> $a['gap_minutes'] )
                ?: ( $a['date'] <=> $b['date'] )
        );

        $recommendations = array();

        foreach (array_slice($days, 0, self::MAX_RECOMMENDATIONS) as $day) {
            $recommendations[] = array(
                'type'        => 'consolidate_gaps',
                'date'        => $day['date'],
                'gaps'        => $day['gaps'],
                'gap_minutes' => $day['gap_minutes'],
                'description' => sprintf(
                    '%s has %d gap%s totalling %d minutes between appointments; consolidating them would free that time.',
                    $day['date'],
                    $day['gaps'],
                    1 === $day['gaps'] ? '' : 's',
                    $day['gap_minutes']
                ),
            );
        }

        if (array() === $recommendations) {
            $recommendations[] = array(
                'type'        => 'no_action',
                'description' => 'No gaps were found between appointments in this range.',
            );
        }

        return $recommendations;
    }

    /**
     * Open days with room, quietest first.
     *
     * With capacity unconfigured there is no free-minutes figure to report, so
     * the quietest booked day is named and the caller is pointed at the
     * business-hours rules instead of being given a fabricated capacity.
     *
     * @param array<string, mixed> $analysis Built analysis.
     *
     * @return list<array<string, mixed>>
     */
    private function fillRecommendations(array $analysis): array
    {
        $recommendations = array();
        $quietest        = $analysis['quietest_working_day'];
        $quietestDate    = is_array($quietest) ? $quietest['date'] : null;

        if (is_array($quietest) && $quietest['free_minutes'] > 0) {
            $recommendations[] = array(
                'type'             => 'fill_capacity',
                'date'             => $quietest['date'],
                'free_minutes'     => $quietest['free_minutes'],
                'booked_minutes'   => $quietest['booked_minutes'],
                'capacity_minutes' => $quietest['capacity_minutes'],
                'description'      => sprintf(
                    '%s has %d of %d configured minutes unbooked and is the quietest open day in the range.',
                    $quietest['date'],
                    $quietest['free_minutes'],
                    $quietest['capacity_minutes']
                ),
            );
        }

        $candidates = array_values(
            array_filter(
                $analysis['days'],
                static fn (array $day): bool => null !== $day['utilization_rate']
                    && $day['utilization_rate'] < 1
                    && $day['date'] !== $quietestDate
            )
        );

        usort(
            $candidates,
            static fn (array $a, array $b): int => ( $a['utilization_rate'] <=> $b['utilization_rate'] )
                ?: ( $a['date'] <=> $b['date'] )
        );

        $room = self::MAX_RECOMMENDATIONS - count($recommendations);

        foreach (array_slice($candidates, 0, max(0, $room)) as $day) {
            $free = max(0, $day['capacity_minutes'] - $day['booked_minutes']);

            $recommendations[] = array(
                'type'             => 'fill_capacity',
                'date'             => $day['date'],
                'free_minutes'     => $free,
                'booked_minutes'   => $day['booked_minutes'],
                'capacity_minutes' => $day['capacity_minutes'],
                'description'      => sprintf(
                    '%s is at %s%% of its configured capacity with %d minutes unbooked.',
                    $day['date'],
                    number_format($day['utilization_rate'] * 100, 1),
                    $free
                ),
            );
        }

        if (array() === $recommendations) {
            $recommendations[] = $this->fillFallback($analysis);
        }

        return $recommendations;
    }

    /**
     * The "nothing to fill" answer, which says why.
     *
     * @param array<string, mixed> $analysis Built analysis.
     *
     * @return array<string, mixed>
     */
    private function fillFallback(array $analysis): array
    {
        if (array() === $analysis['days']) {
            if ('unconfigured' === $analysis['capacity']['basis']) {
                return array(
                    'type'        => 'no_action',
                    'description' => 'No appointments were found in this range; configure business hours to measure capacity.',
                );
            }

            return array(
                'type'        => 'no_action',
                'description' => 'No appointments were found in this range.',
            );
        }

        if ('unconfigured' === $analysis['capacity']['basis']) {
            $days = $analysis['days'];

            usort(
                $days,
                static fn (array $a, array $b): int => ( $a['booked_minutes'] <=> $b['booked_minutes'] )
                    ?: ( $a['date'] <=> $b['date'] )
            );

            return array(
                'type'             => 'fill_capacity',
                'date'             => $days[0]['date'],
                'free_minutes'     => null,
                'booked_minutes'   => $days[0]['booked_minutes'],
                'capacity_minutes' => null,
                'description'      => sprintf(
                    '%s is the quietest day in the range with %d booked minutes. Configure business hours to measure capacity and target specific free slots.',
                    $days[0]['date'],
                    $days[0]['booked_minutes']
                ),
            );
        }

        return array(
            'type'        => 'no_action',
            'description' => 'Every open day in the range is at or above its configured capacity.',
        );
    }

    /**
     * The widest busiest-to-quietest imbalance, when it is worth moving work.
     *
     * @param array<string, mixed> $analysis Built analysis.
     *
     * @return list<array<string, mixed>>
     */
    private function balanceRecommendations(array $analysis): array
    {
        $days = $analysis['days'];

        if (count($days) < 2) {
            return array(
                array(
                    'type'        => 'no_action',
                    'description' => 'Load balancing needs at least two days with appointments in the range.',
                ),
            );
        }

        usort(
            $days,
            static fn (array $a, array $b): int => ( $b['booked_minutes'] <=> $a['booked_minutes'] )
                ?: ( $a['date'] <=> $b['date'] )
        );

        $busiest = $days[0];
        $quietest = $days[ count($days) - 1 ];
        $difference = $busiest['booked_minutes'] - $quietest['booked_minutes'];

        if ($difference < self::BALANCE_THRESHOLD_MINUTES) {
            return array(
                array(
                    'type'        => 'no_action',
                    'description' => sprintf(
                        'The busiest and quietest days differ by %d minutes, within the %d-minute balance threshold.',
                        $difference,
                        self::BALANCE_THRESHOLD_MINUTES
                    ),
                ),
            );
        }

        $move    = (int) floor($difference / 2);
        $average = $analysis['average_appointment_minutes'];

        return array(
            array(
                'type'                 => 'rebalance',
                'from_date'            => $busiest['date'],
                'to_date'              => $quietest['date'],
                'minutes_to_move'      => $move,
                'appointments_to_move' => is_numeric($average) && $average > 0
                    ? (int) round($move / $average)
                    : null,
                'description'          => sprintf(
                    '%s has %d booked minutes against %d on %s; moving about %d minutes from the busier day would even the load.',
                    $busiest['date'],
                    $busiest['booked_minutes'],
                    $quietest['booked_minutes'],
                    $quietest['date'],
                    $move
                ),
            ),
        );
    }
}
