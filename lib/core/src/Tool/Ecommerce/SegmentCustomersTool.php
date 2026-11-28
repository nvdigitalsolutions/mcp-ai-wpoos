<?php

/**
 * Groups customers into segments from their paid order history.
 *
 * Port of `WP_MCP_AI_Tool_Segment_Customers` (Proposal 164 section 164.182).
 *
 * ## The reference keyed customers by billing email
 *
 * Its query grouped by `_billing_email`, so a guest who checked out twice under
 * two addresses was two customers, and a registered customer who changed their
 * billing email was two accounts with their orders split across both. It also
 * read `post_author` as the customer id, which on a WooCommerce order is usually
 * zero: the real reference is `_customer_user`. This tool reads
 * `OrderStoreInterface::topCustomers()`, which keys on the account (section
 * 164.181).
 *
 * ## Two of the five segment types cannot be computed, and are refused by name
 *
 * `geographic` needs a billing address. The order aggregate carries a billing
 * name and email and nothing else, so there is no country, state, city or
 * postcode to group by — and the reference's own query fetched only country and
 * state while its schema advertised city and postcode, so those two values fell
 * into a single segment keyed by the empty string. Refused with the prerequisite
 * (billing address fields on the order) rather than answered with one bucket
 * called "".
 *
 * `product_preference` filters by **category**, and the product model has no
 * category field — the same gap that made `woo_products` refuse `category` in
 * section 164.177. The reference did not implement it either: it returned two
 * empty segments and a note saying order-item analysis was required. Answering
 * that with a product-id preference would be a different question wearing the
 * argument's name, so it is refused with the prerequisite (product categories).
 *
 * ## `custom_criteria` had two arguments nothing read
 *
 * `date_from` and `date_to` were accepted inside `custom_criteria` and never
 * referenced, so a caller who filtered by date got an unfiltered answer that
 * looked filtered. The window is the top-level `date_from`/`date_to` here, and
 * the two nested forms are refused by name rather than silently ignored.
 *
 * ## A segmentation pass has no currency in the reference
 *
 * `total_spent` was a bare float summed across whatever the store used, and the
 * monetary thresholds (`monetary_min`, value tiers) were numbers in no currency.
 * The currency is required here and is a filter, and every threshold is an
 * amount in it, the same rule the rest of this family follows since 164.178.
 *
 * ## Segments were unbounded payloads
 *
 * Each segment embedded the whole customer record for every member, so five
 * segments over a thousand customers was a megabyte of JSON in one tool result.
 * A segment reports its true `count` and lists at most `MAX_LISTED` customers,
 * with `truncated` when there are more. The count is never truncated; the sample
 * is.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\CustomerSpend;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Support\DateBound;
use Nvoos\Core\Tool\AbstractTool;

final class SegmentCustomersTool extends AbstractTool
{
    /** How many customers to analyse when the caller does not say. */
    public const DEFAULT_ANALYSED = 1000;

    /** The most customers one call will analyse. */
    public const MAX_ANALYSED = 10000;

    /** How many customers one segment lists before reporting truncation. */
    public const MAX_LISTED = 25;

    /** The segment types this tool computes. */
    public const TYPES = array('rfm', 'value_based', 'custom');

    /** The segment types the reference offers that this slice does not compute. */
    public const REFUSED_TYPES = array(
        'geographic'         => 'Geographic segments need a billing address, and the order aggregate carries only a billing name and email. Prerequisite: country, state, city and postcode on the order.',
        'product_preference' => 'Preference segments filter by product category, and the product model has no category field. Prerequisite: product categories, the same one woo_products waits on.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'segment_customers';
    }

    public function getName(): string
    {
        return 'Segment Customers';
    }

    public function getDescription(): string
    {
        return 'Groups customers into segments from their paid order history in one currency: RFM (recency, frequency, monetary), spend tiers, or custom order and spend bounds over a date window. Geographic and product-preference segments are not computed by this tool.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'currency'          => array(
                    'type'        => 'string',
                    'description' => 'The ISO 4217 currency to analyse. Required, because spend and every threshold are amounts in it.',
                ),
                'segmentation_type' => array(
                    'type'        => 'string',
                    'description' => 'Which segmentation to run. Default rfm.',
                    'enum'        => self::TYPES,
                    'default'     => 'rfm',
                ),
                'date_from'         => array(
                    'type'        => 'string',
                    'description' => 'Start of the order window (YYYY-MM-DD). Default: all time.',
                ),
                'date_to'           => array(
                    'type'        => 'string',
                    'description' => 'End of the order window (YYYY-MM-DD), included. Default: all time.',
                ),
                'rfm_segments'      => array(
                    'type'        => 'object',
                    'description' => 'Thresholds for the rfm type.',
                    'properties'  => array(
                        'recency_days'  => array(
                            'type'        => 'integer',
                            'description' => 'A customer counts as recent when their last paid order is this many days old or fewer. Default 30.',
                            'minimum'     => 0,
                            'maximum'     => 3650,
                        ),
                        'frequency_min' => array(
                            'type'        => 'integer',
                            'description' => 'Paid orders needed to count as frequent. Default 2.',
                            'minimum'     => 1,
                            'maximum'     => 10000,
                        ),
                        'monetary_min'  => array(
                            'type'        => 'number',
                            'description' => 'Spend needed to count as high value, in the reported currency. Default 100.',
                            'minimum'     => 0,
                        ),
                    ),
                ),
                'value_tiers'       => array(
                    'type'        => 'array',
                    'description' => 'Spend tiers for the value_based type. Half-open: min inclusive, max exclusive. Default High 500 and up, Medium 100 to 500, Low 0 to 100.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name' => array( 'type' => 'string' ),
                            'min'  => array( 'type' => 'number' ),
                            'max'  => array( 'type' => 'number' ),
                        ),
                        'required'   => array( 'name', 'min' ),
                    ),
                ),
                'custom_criteria'   => array(
                    'type'        => 'object',
                    'description' => 'Bounds for the custom type. All are optional and are ANDed.',
                    'properties'  => array(
                        'min_orders' => array( 'type' => 'integer', 'minimum' => 0 ),
                        'max_orders' => array( 'type' => 'integer', 'minimum' => 0 ),
                        'min_spent'  => array( 'type' => 'number', 'minimum' => 0 ),
                        'max_spent'  => array( 'type' => 'number', 'minimum' => 0 ),
                    ),
                ),
                'limit'             => array(
                    'type'        => 'integer',
                    'description' => 'How many of the biggest spenders to analyse. Default 1000.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_ANALYSED,
                    'default'     => self::DEFAULT_ANALYSED,
                ),
            ),
            'required'   => array('currency'),
        );
    }

    /** Arguments the reference offers that only the refused types use. */
    public const REFUSED_ARGUMENTS = array(
        'geographic_field' => 'Only the geographic segment type uses it, and that type is not computed here.',
        'product_category'  => 'Only the product-preference segment type uses it, and that type is not computed here.',
    );

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();
        $known = array(
            'currency',
            'segmentation_type',
            'date_from',
            'date_to',
            'rfm_segments',
            'value_tiers',
            'custom_criteria',
            'limit',
        );

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $known, true)) {
                $fields[ $key ] = array(self::REFUSED_ARGUMENTS[ $key ] ?? 'Unknown field.');
            }
        }

        $currency = strtoupper($this->stringParam($arguments, 'currency'));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $fields['currency'] = array('An ISO 4217 code is required, because spend and every threshold are amounts in it.');
        }

        $type = strtolower($this->stringParam($arguments, 'segmentation_type', 'rfm'));

        if (isset(self::REFUSED_TYPES[ $type ])) {
            $fields['segmentation_type'] = array(self::REFUSED_TYPES[ $type ]);
        } elseif (! in_array($type, self::TYPES, true)) {
            $fields['segmentation_type'] = array('One of: ' . implode(', ', self::TYPES) . '.');
        }

        $from = isset($arguments['date_from']) ? DateBound::parse($arguments['date_from']) : null;
        $to = isset($arguments['date_to']) ? DateBound::parse($arguments['date_to'], true) : null;

        if (isset($arguments['date_from']) && null === $from) {
            $fields['date_from'] = array(DateBound::reason());
        }

        if (isset($arguments['date_to']) && null === $to) {
            $fields['date_to'] = array(DateBound::reason());
        }

        if (null !== $from && null !== $to && $from > $to) {
            $fields['date_from'] = array('Must not be after date_to.');
        }

        $limit = $this->intParam($arguments, 'limit', self::DEFAULT_ANALYSED);

        if ($limit < 1 || $limit > self::MAX_ANALYSED) {
            $fields['limit'] = array('Between 1 and ' . self::MAX_ANALYSED . '.');
        }

        // Only the configuration the chosen type reads is validated, so a caller
        // is not refused for leaving another type's defaults in place.
        $rfm = 'rfm' === $type ? $this->rfmThresholds($arguments, $fields) : null;
        $tiers = 'value_based' === $type ? $this->tiers($arguments, $fields) : null;
        $criteria = 'custom' === $type ? $this->criteria($arguments, $fields) : null;

        if (array() !== $fields) {
            return $this->errors->validationFailed('The segmentation request cannot be read.', $fields);
        }

        $customers = $this->orders->topCustomers(
            new OrderQuery(createdAfter: $from, createdBefore: $to),
            $currency,
            $limit
        );

        $segments = match ($type) {
            'rfm'         => $this->byRfm(
                $customers,
                $rfm ?? array('recency_days' => 30, 'frequency_min' => 2, 'monetary_min' => 100.0)
            ),
            'value_based' => $this->byValue($customers, $tiers ?? array()),
            default       => $this->byCriteria($customers, $criteria ?? array()),
        };

        return $this->success(
            sprintf(
                '%d customer(s) analysed in %s, across %d segment(s).',
                count($customers),
                $currency,
                count($segments)
            ),
            array(
                'segmentation_type'  => $type,
                'currency'           => $currency,
                'window'             => array(
                    'from' => null === $from ? null : $from->format('Y-m-d'),
                    'to'   => null === $to ? null : $to->format('Y-m-d'),
                ),
                'analysed_customers' => count($customers),
                'segments'           => $segments,
            )
        );
    }

    /**
     * Split customers into the five RFM segments.
     *
     * @param list<CustomerSpend>                                  $customers Analysed customers.
     * @param array{recency_days: int, frequency_min: int, monetary_min: float} $rfm Thresholds.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byRfm(array $customers, array $rfm): array
    {
        $recencyDays = (int) ( $rfm['recency_days'] ?? 30 );
        $frequencyMin = (int) ( $rfm['frequency_min'] ?? 2 );
        $monetaryMin = (float) ( $rfm['monetary_min'] ?? 100.0 );

        $segments = array(
            'champions'   => $this->segment('Recent, frequent and high value'),
            'loyal'       => $this->segment('Frequent and high value, but not recent'),
            'potential'   => $this->segment('Recent, but not both frequent and high value'),
            'at_risk'     => $this->segment('Frequent, but not recent'),
            'hibernating' => $this->segment('Neither recent nor frequent'),
        );

        foreach ($customers as $customer) {
            $spend = (float) $customer->spend->toDecimal();

            $recent = null !== $customer->lastOrderAt
                && (int) $customer->lastOrderAt->diff(new \DateTimeImmutable('today'))->days <= $recencyDays;
            $frequent = $customer->orderCount >= $frequencyMin;
            $valuable = $spend >= $monetaryMin;

            if ($recent && $frequent && $valuable) {
                $key = 'champions';
            } elseif ($frequent && $valuable) {
                $key = 'loyal';
            } elseif ($recent) {
                $key = 'potential';
            } elseif ($frequent) {
                $key = 'at_risk';
            } else {
                $key = 'hibernating';
            }

            $segments[ $key ]['customers'][] = $this->row($customer);
        }

        return $this->finish($segments);
    }

    /**
     * Split customers into spend tiers.
     *
     * @param list<CustomerSpend> $customers Analysed customers.
     * @param list<array{name: string, min: float, max: float|null}> $tiers Validated, non-overlapping tiers.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byValue(array $customers, array $tiers): array
    {
        $segments = array();

        foreach ($tiers as $tier) {
            $segments[ (string) $tier['name'] ] = array(
                'criteria'  => $this->tierLabel((float) $tier['min'], $tier['max']),
                'min'       => (float) $tier['min'],
                'max'       => null === $tier['max'] ? null : (float) $tier['max'],
                'customers' => array(),
            );
        }

        foreach ($customers as $customer) {
            $spend = (float) $customer->spend->toDecimal();

            foreach ($tiers as $tier) {
                $min = (float) $tier['min'];
                $max = null === $tier['max'] ? null : (float) $tier['max'];

                if ($spend >= $min && (null === $max || $spend < $max)) {
                    $segments[ (string) $tier['name'] ]['customers'][] = $this->row($customer);
                    break;
                }
            }
        }

        return $this->finish($segments);
    }

    /**
     * One segment of everyone the bounds match.
     *
     * @param list<CustomerSpend> $customers Analysed customers.
     * @param array<string, int|float> $criteria Validated bounds.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byCriteria(array $customers, array $criteria): array
    {
        $matching = array();

        foreach ($customers as $customer) {
            $spend = (float) $customer->spend->toDecimal();

            if (isset($criteria['min_orders']) && $customer->orderCount < (int) $criteria['min_orders']) {
                continue;
            }

            if (isset($criteria['max_orders']) && $customer->orderCount > (int) $criteria['max_orders']) {
                continue;
            }

            if (isset($criteria['min_spent']) && $spend < (float) $criteria['min_spent']) {
                continue;
            }

            if (isset($criteria['max_spent']) && $spend > (float) $criteria['max_spent']) {
                continue;
            }

            $matching[] = $this->row($customer);
        }

        return $this->finish(
            array('matching' => array('criteria' => $criteria, 'customers' => $matching))
        );
    }

    /**
     * An empty segment carrying its criteria.
     *
     * @return array<string, mixed>
     */
    private function segment(string $criteria): array
    {
        return array('criteria' => $criteria, 'customers' => array());
    }

    /**
     * One customer as the response reports it.
     *
     * @return array<string, mixed>
     */
    private function row(CustomerSpend $customer): array
    {
        $row = $customer->jsonSerialize();

        $row['days_since_last'] = null === $customer->lastOrderAt
            ? null
            : (int) $customer->lastOrderAt->diff(new \DateTimeImmutable('today'))->days;

        return $row;
    }

    /**
     * Count every segment and list at most {@see self::MAX_LISTED} of its members.
     *
     * Empty segments are kept rather than dropped: "nobody is at risk" is an
     * answer, and a segment missing from the response reads as one that was not
     * computed.
     *
     * @param array<string, array<string, mixed>> $segments Built segments.
     *
     * @return array<string, array<string, mixed>>
     */
    private function finish(array $segments): array
    {
        foreach ($segments as $name => $segment) {
            $count = is_array($segment['customers'] ?? null) ? count($segment['customers']) : 0;

            $segments[ $name ]['count'] = $count;
            $segments[ $name ]['truncated'] = $count > self::MAX_LISTED;
            $segments[ $name ]['customers'] = array_slice((array) ($segment['customers'] ?? array()), 0, self::MAX_LISTED);
        }

        return $segments;
    }

    /**
     * The RFM thresholds, defaulted and validated.
     *
     * @param array<string, mixed>        $arguments Raw arguments.
     * @param array<string, list<string>> $fields    Field errors, by reference.
     *
     * @return array{recency_days: int, frequency_min: int, monetary_min: float}
     */
    private function rfmThresholds(array $arguments, array &$fields): array
    {
        $raw = $arguments['rfm_segments'] ?? array();

        if (! is_array($raw)) {
            $fields['rfm_segments'] = array('An object of thresholds.');

            return array('recency_days' => 30, 'frequency_min' => 2, 'monetary_min' => 100.0);
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, array('recency_days', 'frequency_min', 'monetary_min'), true)) {
                $fields['rfm_segments'] = array('Unknown threshold: ' . $key . '.');
            }
        }

        $recency = array_key_exists('recency_days', $raw) ? $this->intValue($raw['recency_days']) : 30;

        if (null === $recency || $recency < 0 || $recency > 3650) {
            $fields['rfm_segments'] = array('recency_days must be between 0 and 3650.');
            $recency = 30;
        }

        $frequency = array_key_exists('frequency_min', $raw) ? $this->intValue($raw['frequency_min']) : 2;

        if (null === $frequency || $frequency < 1) {
            $fields['rfm_segments'] = array('frequency_min must be one or more.');
            $frequency = 2;
        }

        $monetary = array_key_exists('monetary_min', $raw) ? $this->floatValue($raw['monetary_min']) : 100.0;

        if (null === $monetary || $monetary < 0) {
            $fields['rfm_segments'] = array('monetary_min must be zero or more.');
            $monetary = 100.0;
        }

        return array('recency_days' => $recency, 'frequency_min' => $frequency, 'monetary_min' => $monetary);
    }

    /**
     * The spend tiers, defaulted and validated.
     *
     * @param array<string, mixed>        $arguments Raw arguments.
     * @param array<string, list<string>> $fields    Field errors, by reference.
     *
     * @return list<array{name: string, min: float, max: float|null}>
     */
    private function tiers(array $arguments, array &$fields): array
    {
        $raw = $arguments['value_tiers'] ?? null;

        if (null === $raw) {
            return array(
                array('name' => 'High Value', 'min' => 500.0, 'max' => null),
                array('name' => 'Medium Value', 'min' => 100.0, 'max' => 500.0),
                array('name' => 'Low Value', 'min' => 0.0, 'max' => 100.0),
            );
        }

        if (! is_array($raw) || array() === $raw) {
            $fields['value_tiers'] = array('A list of tiers, each with a name and a min.');

            return array();
        }

        $tiers = array();
        $names = array();

        foreach ($raw as $tier) {
            if (! is_array($tier)) {
                $fields['value_tiers'] = array('Each tier is an object with a name and a min.');

                return array();
            }

            $name = isset($tier['name']) && is_string($tier['name']) ? trim($tier['name']) : '';

            if ('' === $name) {
                $fields['value_tiers'] = array('Each tier needs a name.');

                return array();
            }

            if (isset($names[ $name ])) {
                $fields['value_tiers'] = array('Two tiers share the name ' . $name . '.');

                return array();
            }

            $names[ $name ] = true;

            $min = isset($tier['min']) ? $this->floatValue($tier['min']) : null;

            if (null === $min) {
                $fields['value_tiers'] = array('Each tier needs a numeric min.');

                return array();
            }

            $max = isset($tier['max']) ? $this->floatValue($tier['max']) : null;

            if (array_key_exists('max', $tier) && null === $max) {
                $fields['value_tiers'] = array('A tier max must be numeric when given.');

                return array();
            }

            if (null !== $max && $max < $min) {
                $fields['value_tiers'] = array('A tier max cannot be below its min.');

                return array();
            }

            $tiers[] = array('name' => $name, 'min' => $min, 'max' => $max);
        }

        usort($tiers, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);

        $previous = null;

        foreach ($tiers as $tier) {
            if (null !== $previous) {
                if (null === $previous['max'] || $previous['max'] > $tier['min']) {
                    $fields['value_tiers'] = array(
                        'Tiers must not overlap: ' . $previous['name'] . ' and ' . $tier['name'] . '.',
                    );

                    return array();
                }
            }

            $previous = $tier;
        }

        return $tiers;
    }

    /**
     * The custom bounds, validated.
     *
     * @param array<string, mixed>        $arguments Raw arguments.
     * @param array<string, list<string>> $fields    Field errors, by reference.
     *
     * @return array<string, int|float>
     */
    private function criteria(array $arguments, array &$fields): array
    {
        $raw = $arguments['custom_criteria'] ?? array();

        if (! is_array($raw)) {
            $fields['custom_criteria'] = array('An object of bounds.');

            return array();
        }

        $criteria = array();

        foreach ($raw as $key => $value) {
            if (in_array($key, array('date_from', 'date_to'), true)) {
                $fields['custom_criteria'] = array('Set the window with the top-level date_from and date_to; the reference accepted these here and never read them.');
                continue;
            }

            if (! in_array($key, array('min_orders', 'max_orders', 'min_spent', 'max_spent'), true)) {
                $fields['custom_criteria'] = array('Unknown criterion: ' . $key . '.');
                continue;
            }

            if (str_ends_with($key, '_orders')) {
                $number = $this->intValue($value);
            } else {
                $number = $this->floatValue($value);
            }

            if (null === $number || $number < 0) {
                $fields['custom_criteria'] = array($key . ' must be zero or more.');
                continue;
            }

            $criteria[ $key ] = $number;
        }


        foreach (array('orders', 'spent') as $family) {
            $min = $criteria[ 'min_' . $family ] ?? null;
            $max = $criteria[ 'max_' . $family ] ?? null;

            if (null !== $min && null !== $max && $min > $max) {
                $fields['custom_criteria'] = array('min_' . $family . ' must not be above max_' . $family . '.');
            }
        }

        return $criteria;
    }

    /**
     * A human label for a half-open spend range.
     */
    private function tierLabel(float $min, ?float $max): string
    {
        if (null === $max) {
            return sprintf('Spend of %s or more', $this->amount($min));
        }

        return sprintf('Spend from %s up to %s', $this->amount($min), $this->amount($max));
    }

    /**
     * A decimal amount without trailing zeroes, for a label.
     */
    private function amount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
    /**
     * A whole number, or null when the input is not one.
     */
    private function intValue(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_float($raw) && (float) (int) $raw === $raw) {
            return (int) $raw;
        }

        if (is_string($raw) && 1 === preg_match('/^-?\d+$/', trim($raw))) {
            return (int) trim($raw);
        }

        return null;
    }

    /**
     * A decimal amount, or null when the input is not one.
     */
    private function floatValue(mixed $raw): ?float
    {
        if (! is_int($raw) && ! is_float($raw) && ! is_string($raw)) {
            return null;
        }

        return is_numeric($raw) ? (float) $raw : null;
    }
}
