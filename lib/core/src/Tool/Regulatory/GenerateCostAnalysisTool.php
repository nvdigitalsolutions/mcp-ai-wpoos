<?php

/**
 * Cost analysis across registrations.
 *
 * Port of `WP_MCP_AI_Tool_Generate_Cost_Analysis` (Proposal 164 section
 * 164.156). Sums the three fee fields, groups them by country, product, type or
 * month, and projects renewal cost for the next twelve months.
 *
 * ## Money here is a number, not an amount
 *
 * The fees are read from `_registration_fee`, `_renewal_fee` and
 * `_additional_fees` and summed as floats. There is no currency anywhere in the
 * record or the response: the toolkit stores one figure per registration and
 * the operator is responsible for it being the same unit throughout. The port
 * keeps that and says so, rather than inventing a currency the store cannot
 * confirm.
 *
 * ## A non-numeric fee became zero silently
 *
 * `(float) get_post_meta( ... )` turns `"TBD"` into `0.0`, so a registration
 * with an unreadable fee contributed nothing and the total looked complete.
 * The port parses the same way — the arithmetic cannot do otherwise — but counts
 * the values it could not read in `unparsed_fees` and names the fields, so a
 * total that is missing a fee says so.
 *
 * ## `custom` without dates meant "the default window"
 *
 * As in section 164.156's companion report, a `custom` range with missing dates
 * was ignored: the tool fell through with no date filter at all, returning the
 * whole estate while reporting `date_range: "custom"`. The port requires both
 * dates, validates them, and refuses otherwise.
 *
 * ## The forecast is month-aligned
 *
 * WordPress bounded the projection with `strtotime( '+12 months' )`, which
 * overflows from the 29th to the 31st. The port takes the last day of the
 * twelfth month ahead, so "the next twelve months" is a whole number of months
 * whatever today's date is, and the month keys come from the expiry date rather
 * than from a substring of it.
 *
 * `average_cost_per_registration` divides by the registrations actually summed,
 * not by the query's total, so a capped read cannot understate the average.
 * `truncated` reports the cap when it is hit.
 *
 * Gated on `read`; WordPress declared `edit_posts` and checked `read`.
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

class GenerateCostAnalysisTool extends RegulatoryTool
{
    /** The ranges this tool accepts. */
    private const RANGES = array('all', 'last_month', 'last_quarter', 'last_year', 'custom');

    /** The groupings this tool accepts. */
    private const GROUPINGS = array('country', 'product', 'type', 'month');

    /** The months the renewal projection covers. */
    private const FORECAST_MONTHS = 12;

    /** The fee fields, in the order WordPress summed them. */
    private const FEE_FIELDS = array('_registration_fee', '_renewal_fee', '_additional_fees');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'generate_cost_analysis';
    }

    public function getName(): string
    {
        return 'Generate Cost Analysis';
    }

    public function getDescription(): string
    {
        return 'Sums the registration, renewal and additional fees across registrations, grouped by country, product, type or month, with a twelve-month renewal projection by expiry month. Amounts carry no currency because the records carry none; fees that cannot be read are counted rather than silently treated as zero.';
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
                'date_range'       => array(
                    'type'        => 'string',
                    'description' => 'Registrations created inside the window. Default last_year.',
                    'enum'        => self::RANGES,
                    'default'     => 'last_year',
                ),
                'start_date'       => array(
                    'type'        => 'string',
                    'description' => 'Start of a custom range, YYYY-MM-DD. Required with date_range custom.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'end_date'         => array(
                    'type'        => 'string',
                    'description' => 'End of a custom range, YYYY-MM-DD. Required with date_range custom.',
                    'pattern'     => '^\d{4}-\d{2}-\d{2}$',
                ),
                'grouping'         => array(
                    'type'        => 'string',
                    'description' => 'Group costs by. Default country.',
                    'enum'        => self::GROUPINGS,
                    'default'     => 'country',
                ),
                'include_forecast' => array(
                    'type'        => 'boolean',
                    'description' => 'Include the twelve-month renewal projection. Default true.',
                    'default'     => true,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $range           = $this->stringParam($arguments, 'date_range', 'last_year');
        $grouping        = $this->stringParam($arguments, 'grouping', 'country');
        $includeForecast = $this->boolParam($arguments, 'include_forecast', true);
        $now             = time();

        $fields = array();

        if (! in_array($range, self::RANGES, true)) {
            $fields['date_range'] = array('One of: ' . implode(', ', self::RANGES) . '.');
        }

        if (! in_array($grouping, self::GROUPINGS, true)) {
            $fields['grouping'] = array('One of: ' . implode(', ', self::GROUPINGS) . '.');
        }

        $start = 'all' === $range ? null : $this->rangeStart($range, $now);
        $end   = null;

        if ('custom' === $range) {
            $window = $this->customWindow($arguments);

            $start  = $window['start'];
            $end    = $window['end'];
            $fields = array_merge($fields, $window['fields']);
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The cost analysis cannot be built.', $fields);
        }

        $items     = $this->collectContent(array(self::REGISTRATION_TYPE));
        $truncated = count($items) >= self::MAX_COLLECTED;

        $breakdown  = array();
        $totalCosts = 0.0;
        $counted    = 0;
        $unparsed   = array();

        foreach ($items as $registration) {
            if (! $this->withinRange($registration, $start, $end)) {
                continue;
            }

            ++$counted;

            $fees = $this->fees($registration, $unparsed);

            $key = $this->groupKey($registration, $grouping);
            $feeTotal = $fees['registration_fee'] + $fees['renewal_fee'] + $fees['additional_fees'];

            if (! isset($breakdown[ $key ])) {
                $breakdown[ $key ] = array(
                    'count'             => 0,
                    'registration_fees' => 0.0,
                    'renewal_fees'      => 0.0,
                    'additional_fees'   => 0.0,
                    'total'             => 0.0,
                );
            }

            ++$breakdown[ $key ]['count'];
            $breakdown[ $key ]['registration_fees'] += $fees['registration_fee'];
            $breakdown[ $key ]['renewal_fees']      += $fees['renewal_fee'];
            $breakdown[ $key ]['additional_fees']   += $fees['additional_fees'];
            $breakdown[ $key ]['total']             += $feeTotal;

            $totalCosts += $feeTotal;
        }

        $forecast = array();
        $forecastTotal = 0.0;

        if ($includeForecast) {
            $result = $this->forecast($now);

            $forecast      = $result['months'];
            $forecastTotal = $result['total'];
        }

        return $this->success(
            sprintf(
                '%d registration(s), total %s, average %s per registration.',
                $counted,
                $this->money($totalCosts),
                $this->money($counted > 0 ? $totalCosts / $counted : 0.0)
            ),
            array(
                'report_type'             => 'cost_analysis',
                'generated_at'            => $this->nowStamp(),
                'date_range'              => $range,
                'window'                  => array('start' => $start, 'end' => $end),
                'grouping'                => $grouping,
                'currency'                => null,
                'summary'                 => array(
                    'total_registrations'       => $counted,
                    'total_costs'               => $this->round($totalCosts),
                    'avg_cost_per_registration' => $this->round($counted > 0 ? $totalCosts / $counted : 0.0),
                    'unparsed_fees'             => count($unparsed),
                    'truncated'                 => $truncated,
                ),
                'cost_breakdown'          => $this->roundedBreakdown($breakdown),
                'unparsed_fee_fields'     => $unparsed,
                'forecast'                => $this->roundedForecast($forecast),
                'forecast_total_next_12m' => $this->round($forecastTotal),
            )
        );
    }

    /**
     * The three fees for one registration, accumulating unreadable values.
     *
     * @param list<array{registration_id: int, field: string, value: string}> $unparsed
     *
     * @return array{registration_fee: float, renewal_fee: float, additional_fees: float}
     */
    private function fees(ContentItem $registration, array &$unparsed): array
    {
        $fees = array(
            'registration_fee' => 0.0,
            'renewal_fee'      => 0.0,
            'additional_fees'  => 0.0,
        );

        $map = array(
            '_registration_fee' => 'registration_fee',
            '_renewal_fee'      => 'renewal_fee',
            '_additional_fees'  => 'additional_fees',
        );

        foreach (self::FEE_FIELDS as $field) {
            $raw = $registration->meta[ $field ] ?? null;

            if (null === $raw || '' === $raw) {
                continue;
            }

            if (! is_numeric($raw)) {
                $unparsed[] = array(
                    'registration_id' => $registration->id,
                    'field'           => $field,
                    'value'           => $this->text($raw),
                );

                continue;
            }

            $fees[ $map[ $field ] ] = (float) $raw;
        }

        return $fees;
    }

    /**
     * The renewal projection for the next twelve months.
     *
     * @return array{months: array<string, float>, total: float}
     */
    private function forecast(int $now): array
    {
        $utc   = new \DateTimeZone('UTC');
        $first = date_create_immutable(gmdate('Y-m-01', $now), $utc);

        // Month arithmetic is done from the first of the month, where it cannot
        // overflow, so "twelve months ahead" is twelve months and not
        // twelve-months-and-three-days.
        $horizon = false !== $first
            ? $first->modify('+' . self::FORECAST_MONTHS . ' months')->modify('last day of this month')
            : null;

        if (null === $horizon) {
            return array('months' => array(), 'total' => 0.0);
        }

        $limit  = $horizon->format('Y-m-d');
        $months = array();
        $total  = 0.0;

        foreach ($this->collectContent(array(self::REGISTRATION_TYPE)) as $registration) {
            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' === $expiryDate) {
                continue;
            }

            $days = $this->daysUntil($expiryDate, $now);

            if (null === $days || $days < 0) {
                continue;
            }

            if ($expiryDate > $limit) {
                continue;
            }

            $renewal = $this->text($registration->meta['_renewal_fee'] ?? null);
            $fee     = is_numeric($renewal) ? (float) $renewal : 0.0;

            $month = substr($expiryDate, 0, 7);

            $months[ $month ] = ( $months[ $month ] ?? 0.0 ) + $fee;
            $total           += $fee;
        }

        ksort($months);

        return array('months' => $months, 'total' => $total);
    }

    /**
     * The grouping key for one registration.
     */
    private function groupKey(ContentItem $registration, string $grouping): string
    {
        if ('month' === $grouping) {
            return $registration->createdAt->format('Y-m');
        }

        if ('product' === $grouping) {
            $productId = $this->intValue($registration->meta['product_id'] ?? null);

            if ($productId < 1) {
                return 'Unknown';
            }

            $product = $this->content->find($productId);

            return $product instanceof ContentItem && self::PRODUCT_TYPE === $product->type
                ? $product->title
                : 'Unknown';
        }

        $field = 'type' === $grouping ? 'registration_type' : 'country';

        $value = $this->text($registration->meta[ $field ] ?? null);

        return '' !== $value ? $value : 'Unknown';
    }

    /**
     * The validated custom window and any field errors.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{start: string|null, end: string|null, fields: array<string, array<int, string>>}
     */
    private function customWindow(array $arguments): array
    {
        $start = trim($this->stringParam($arguments, 'start_date'));
        $end   = trim($this->stringParam($arguments, 'end_date'));

        $fields = array();

        if ('' === $start || ! $this->isIsoDate($start)) {
            $fields['start_date'] = array('A date in YYYY-MM-DD form is required for a custom range.');
        }

        if ('' === $end || ! $this->isIsoDate($end)) {
            $fields['end_date'] = array('A date in YYYY-MM-DD form is required for a custom range.');
        }

        if (array() !== $fields) {
            return array('start' => null, 'end' => null, 'fields' => $fields);
        }

        if ($end < $start) {
            return array(
                'start'  => null,
                'end'    => null,
                'fields' => array('end_date' => array('The end date must not precede the start date.')),
            );
        }

        return array('start' => $start, 'end' => $end, 'fields' => array());
    }

    /**
     * Round every figure in a breakdown, so the response carries one precision.
     *
     * @param array<string, array<string, mixed>> $breakdown
     *
     * @return array<string, array<string, mixed>>
     */
    private function roundedBreakdown(array $breakdown): array
    {
        $rounded = array();

        foreach ($breakdown as $key => $row) {
            $rounded[ (string) $key ] = array(
                'count'             => $row['count'],
                'registration_fees' => $this->round((float) $row['registration_fees']),
                'renewal_fees'      => $this->round((float) $row['renewal_fees']),
                'additional_fees'   => $this->round((float) $row['additional_fees']),
                'total'             => $this->round((float) $row['total']),
            );
        }

        return $rounded;
    }

    /**
     * @param array<string, float> $forecast
     *
     * @return array<string, float>
     */
    private function roundedForecast(array $forecast): array
    {
        $rounded = array();

        foreach ($forecast as $month => $amount) {
            $rounded[ (string) $month ] = $this->round($amount);
        }

        return $rounded;
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }

    /**
     * A number for a sentence, without a currency it cannot know.
     */
    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
