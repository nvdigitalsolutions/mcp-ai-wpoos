<?php

/**
 * Forecast registration expiries by risk band and by month.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Expiry_Forecast` (Proposal 164 section
 * 164.154). Every registration with an expiry date, grouped into expired, high,
 * medium and low risk, counted into a monthly timeline, and summarised as
 * recommendations.
 *
 * ## The month buckets skipped a month at the end of the month
 *
 * WordPress built the timeline with `gmdate( 'Y-m', strtotime( "+{$i} months" ) )`
 * for `$i` from zero to the horizon. PHP's `+1 month` is month arithmetic on
 * *today's day number*, so on the 31st it overflows: 31 January plus one month
 * is 3 March. The key `2026-02` therefore never existed, and because the counter
 * only incremented when `isset( $by_month[ $key ] )`, **every February expiry
 * was silently dropped from the timeline** on three days out of thirty-one.
 *
 * The port builds buckets from the first day of the current month, where month
 * arithmetic cannot overflow, and makes the horizon the last day of the final
 * month, so a bucket exists exactly when its month is inside the forecast.
 *
 * ## Risk bands are day-based; the horizon is not
 *
 * WordPress classified `expired`, `high` (`days <= threshold`) and `medium`
 * (`days <= threshold * 2`) by day count, and only `low` by the horizon. With
 * `forecast_months: 1` and `risk_threshold: 180`, a registration eleven months
 * out landed in `medium_risk` even though the forecast covers one month. The
 * bands are kept as WordPress defined them — they answer "how soon", not "how
 * soon within the window" — and the records that fall inside no band and no
 * bucket are counted in `beyond_horizon` rather than vanishing from the summary.
 *
 * ## An unreadable date is expired and named
 *
 * `strtotime` returning `false` made the record look fifty-five years overdue.
 * As in section 164.153 the verdict is kept and the value is named, and the day
 * count is `null` rather than a number the value does not support.
 *
 * `forecast_months` (1-36) and `risk_threshold` (30-180) are validated against
 * the schema bounds and refused outside them. WordPress read both with
 * `! empty()`, which was safe only because the schema forbids zero.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

class GenerateExpiryForecastTool extends RegulatoryTool
{
    /** The default forecast horizon, in months. */
    private const DEFAULT_MONTHS = 12;

    /** The default high-risk threshold, in days. */
    private const DEFAULT_RISK_THRESHOLD = 90;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_expiry_forecast';
    }

    public function getName(): string
    {
        return 'Generate Expiry Forecast';
    }

    public function getDescription(): string
    {
        return 'Forecasts registration expiries over 1-36 months: expired, high, medium and low risk bands, a month-by-month timeline and country counts, with renewal recommendations. Records beyond the forecast horizon are counted in beyond_horizon; a date that cannot be read is treated as expired and named.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress checks `read` in the handler and declares `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'forecast_months' => array(
                    'type'        => 'integer',
                    'description' => 'Months to forecast, 1-36. Default 12.',
                    'minimum'     => 1,
                    'maximum'     => 36,
                    'default'     => self::DEFAULT_MONTHS,
                ),
                'risk_threshold'  => array(
                    'type'        => 'integer',
                    'description' => 'Days before expiry that count as high risk, 30-180. Default 90.',
                    'minimum'     => 30,
                    'maximum'     => 180,
                    'default'     => self::DEFAULT_RISK_THRESHOLD,
                ),
                'countries'       => array(
                    'type'        => 'array',
                    'description' => 'Only registrations in these countries.',
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $months   = $this->intParam($arguments, 'forecast_months', self::DEFAULT_MONTHS);
        $threshold = $this->intParam($arguments, 'risk_threshold', self::DEFAULT_RISK_THRESHOLD);

        $fields = array();

        if ($months < 1 || $months > 36) {
            $fields['forecast_months'] = array('A whole number of months from 1 to 36.');
        }

        if ($threshold < 30 || $threshold > 180) {
            $fields['risk_threshold'] = array('A whole number of days from 30 to 180.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The forecast cannot be built.', $fields);
        }

        $countries = $this->countryFilter($arguments['countries'] ?? null);

        $metaQuery = array('relation' => 'AND');

        if (array() !== $countries) {
            $metaQuery[] = array('key' => 'country', 'value' => $countries, 'compare' => 'IN');
        }

        $now      = time();
        $buckets  = $this->buckets($months);
        $horizon  = array() !== $buckets ? $this->lastDayOf($buckets[ count($buckets) - 1 ]) : gmdate('Y-m-d');

        $expired      = array();
        $highRisk     = array();
        $mediumRisk   = array();
        $lowRisk      = array();
        $unparsed     = array();
        $byMonth      = array();
        $byCountry    = array();
        $beyondHorizon = 0;

        foreach ($buckets as $key) {
            $byMonth[ $key ] = 0;
        }

        foreach ($this->collectContent(array(self::REGISTRATION_TYPE), $metaQuery) as $registration) {
            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' === $expiryDate) {
                continue;
            }

            $country = $this->text($registration->meta['country'] ?? null);
            $days    = $this->daysUntil($expiryDate, $now);

            if ('' !== $country) {
                $byCountry[ $country ] = ( $byCountry[ $country ] ?? 0 ) + 1;
            }

            $row = array(
                'id'             => $registration->id,
                'title'          => $registration->title,
                'country'        => $country,
                'expiry_date'    => $expiryDate,
                'days_to_expiry' => $days,
                'product_id'     => $this->intValue($registration->meta['product_id'] ?? null),
            );

            if (null === $days) {
                $expired[]  = $row;
                $unparsed[] = array(
                    'registration_id' => $registration->id,
                    'expiry_date'     => $expiryDate,
                );

                continue;
            }

            if ($days < 0) {
                $expired[] = $row;

                continue;
            }

            // The timeline counts every non-expired record whose month is
            // inside the forecast, whatever its band: this is the "what
            // expires in March" list, and a March registration is in it
            // whether or not it is also high risk.
            $month         = $this->monthKey($expiryDate);
            $insideHorizon = null !== $month && isset($byMonth[ $month ]);

            if ($insideHorizon) {
                ++$byMonth[ $month ];
            }

            if ($days <= $threshold) {
                $highRisk[] = $row;

                continue;
            }

            if ($days <= $threshold * 2) {
                $mediumRisk[] = $row;

                continue;
            }

            if ($insideHorizon) {
                $lowRisk[] = $row;

                continue;
            }

            ++$beyondHorizon;
        }

        $expired    = $this->soonestFirst($expired);
        $highRisk   = $this->soonestFirst($highRisk);
        $mediumRisk = $this->soonestFirst($mediumRisk);
        $lowRisk    = $this->soonestFirst($lowRisk);

        $recommendations = array();

        if (array() !== $expired) {
            $recommendations[] = sprintf(
                'Urgent: %d expired registrations require immediate renewal action.',
                count($expired)
            );
        }

        if (array() !== $highRisk) {
            $recommendations[] = sprintf(
                'High Priority: %1$d registrations expiring within %2$d days.',
                count($highRisk),
                $threshold
            );
        }

        if (array() !== $mediumRisk) {
            $recommendations[] = sprintf(
                'Planning Required: %1$d registrations expiring within %2$d days.',
                count($mediumRisk),
                $threshold * 2
            );
        }

        $totalExpiring = count($expired) + count($highRisk) + count($mediumRisk) + count($lowRisk);

        return $this->success(
            sprintf(
                '%1$d expired, %2$d high risk, %3$d medium risk, %4$d low risk over %5$d month(s).',
                count($expired),
                count($highRisk),
                count($mediumRisk),
                count($lowRisk),
                $months
            ),
            array(
                'report_type'           => 'expiry_forecast',
                'generated_at'          => $this->nowStamp(),
                'forecast_months'       => $months,
                'risk_threshold'        => $threshold,
                'horizon'               => $horizon,
                'summary'               => array(
                    'total_expiring'   => $totalExpiring,
                    'total_considered' => $totalExpiring + $beyondHorizon,
                    'beyond_horizon'   => $beyondHorizon,
                    'expired'          => count($expired),
                    'high_risk'        => count($highRisk),
                    'medium_risk'      => count($mediumRisk),
                    'low_risk'         => count($lowRisk),
                ),
                'expiry_data'           => array(
                    'expired'     => $expired,
                    'high_risk'   => $highRisk,
                    'medium_risk' => $mediumRisk,
                    'low_risk'    => $lowRisk,
                    'by_month'    => $byMonth,
                    'by_country'  => $byCountry,
                ),
                'unparsed_expiry_dates' => $unparsed,
                'recommendations'       => $recommendations,
            )
        );
    }

    /**
     * The year-month keys the timeline covers, current month first.
     *
     * Month arithmetic is done from the first of the month, where it cannot
     * overflow: adding a month to the 31st does not land on the same day.
     *
     * @return list<string>
     */
    private function buckets(int $months): array
    {
        $utc    = new \DateTimeZone('UTC');
        $first  = date_create_immutable(gmdate('Y-m-01'), $utc);
        $keys   = array();

        if (false === $first) {
            return $keys;
        }

        for ($i = 0; $i <= $months; $i++) {
            $keys[] = $first->modify('+' . $i . ' months')->format('Y-m');
        }

        return $keys;
    }

    /**
     * The year-month key of a stored date, or null when it cannot be read.
     *
     * Derived from the parsed date rather than the first seven characters, so a
     * stored `01/03/2026` lands in its real month instead of the key `01/03/2`.
     */
    private function monthKey(string $date): ?string
    {
        $parsed = date_create_immutable($date, new \DateTimeZone('UTC'));

        return false === $parsed ? null : $parsed->format('Y-m');
    }

    /**
     * The last calendar day of a year-month key.
     */
    private function lastDayOf(string $month): string
    {
        $utc   = new \DateTimeZone('UTC');
        $first = date_create_immutable($month . '-01', $utc);

        if (false === $first) {
            return $month . '-01';
        }

        return $first->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * Order rows by days to expiry, soonest first, with unreadable dates ahead.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function soonestFirst(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $left  = $a['days_to_expiry'] ?? null;
                $right = $b['days_to_expiry'] ?? null;

                $left  = is_int($left) ? $left : PHP_INT_MIN;
                $right = is_int($right) ? $right : PHP_INT_MIN;

                $idLeft  = $a['id'] ?? 0;
                $idRight = $b['id'] ?? 0;

                return array($left, is_int($idLeft) ? $idLeft : 0)
                    <=> array($right, is_int($idRight) ? $idRight : 0);
            }
        );

        return $rows;
    }

    /**
     * The requested country filter, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    private function countryFilter(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $countries = array();

        foreach ($raw as $country) {
            $value = $this->text($country);

            if ('' !== $value && ! in_array($value, $countries, true)) {
                $countries[] = $value;
            }
        }

        return $countries;
    }
}
