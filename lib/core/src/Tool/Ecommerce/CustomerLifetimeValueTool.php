<?php

/**
 * Lifetime value for one customer or the biggest spenders.
 *
 * Port of `WP_MCP_AI_Tool_Customer_Lifetime_Value` (Proposal 164 section
 * 164.183).
 *
 * ## One query per customer, each unbounded
 *
 * The reference listed customers with one `$wpdb` query and then called
 * `wc_get_orders( array( 'limit' => -1, 'billing_email' => $email ) )` **once per
 * customer**, so `include_all` over a hundred customers was a hundred and one
 * queries and every one of them hydrated the customer's whole order history.
 * This reads `OrderStoreInterface::topCustomers()`, which is one grouped
 * aggregate for the whole selection (section 164.181).
 *
 * ## The projection multiplied a first order by twelve
 *
 * `calculate_predictive_clv()` divided the spend by the customer's own active
 * span, floored at one month, and multiplied by the prediction window. A
 * customer whose only order was three days ago therefore had `months_active = 1`
 * and was projected at twelve times that order — and `both` reported thirteen
 * times it as lifetime value. The floor is what makes the arithmetic safe; the
 * span is what makes it meaningless on a short history.
 *
 * The rate here is `historical / timeframe_months`, the period that was actually
 * observed. With a twelve-month history and a twelve-month prediction the two
 * components are equal, which reads as "at the pace they have kept for the last
 * year", and a store younger than its window understates rather than inflates.
 *
 * ## `reset()` and `end()` assumed the query returned newest first
 *
 * Both the projection and the churn test took the newest order with
 * `reset( $orders )` and the oldest with `end( $orders )`, on the strength of
 * `wc_get_orders()`' default ordering, which the call did not ask for. If the
 * default changed the two swapped and the projection divided by a negative span.
 * The dates come from `MIN` and `MAX` in the aggregate.
 *
 * ## Identity is the account, and an email is refused
 *
 * The reference resolved `customer_id` through `WC_Customer` and `email` through
 * `get_user_by()`, then queried orders **by billing email**, so a customer whose
 * older orders carried a different address had them silently left out. The
 * selector here is the account id, which the order already carries. `email` is
 * refused by name: resolving an address to an account needs a user directory
 * this runtime does not have — the same gap behind `woo_customers` (164.181).
 *
 * ## No currency
 *
 * Sums were floats labelled with `get_woocommerce_currency()`, and the window and
 * prediction are amounts in whatever that was. The currency is required and is a
 * filter.
 *
 * ## Two ways to say nothing, and one to say both
 *
 * The reference returned a `no_customers_found` error when neither selector nor
 * `include_all` was given, which reads as "nobody bought" rather than "you did
 * not say who". Both selectors at once silently preferred `customer_id`. Here at
 * least one is required, both together is a field error, and a specific customer
 * with no paid orders in the window is a not-found rather than an empty success.
 *
 * `total_clv` sums only the components the caller asked for: a predictive
 * projection is derived **from** historical spend, so historical is always
 * reported as the basis, but asking for `predictive` does not silently add it.
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
use Nvoos\Core\Tool\AbstractTool;

final class CustomerLifetimeValueTool extends AbstractTool
{
    /** The calculation types this tool performs. */
    public const TYPES = array('historical', 'predictive', 'both');

    /** Bounds on the historical window, in months. */
    public const MIN_TIMEFRAME = 1;
    public const MAX_TIMEFRAME = 60;

    /** Bounds on the prediction window, in months. */
    public const MIN_PREDICTION = 3;
    public const MAX_PREDICTION = 36;

    /** How many spenders `include_all` reads when the caller does not say. */
    public const DEFAULT_TOP = 100;

    /** The most customers one call will report. */
    public const MAX_TOP = 1000;

    /** Days since the last paid order at which churn reads medium and high. */
    public const CHURN_MEDIUM_DAYS = 30;
    public const CHURN_HIGH_DAYS = 90;

    /** Arguments the reference offers that this tool does not use. */
    public const REFUSED_ARGUMENTS = array(
        'email' => 'Identity is the account id the order carries. Resolving an address to an account needs a user directory this runtime does not have, which is the same gap woo_customers waits on.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'customer_lifetime_value';
    }

    public function getName(): string
    {
        return 'Customer Lifetime Value';
    }

    public function getDescription(): string
    {
        return 'Reports lifetime value in one currency for one customer account or the biggest spenders: paid spend over a historical window, a forward projection at the observed monthly rate, order count, average order value and a churn-risk band.';
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
                    'description' => 'The ISO 4217 currency to analyse. Required, because spend and every projection are amounts in it.',
                ),
                'customer_id'       => array(
                    'type'        => 'integer',
                    'description' => 'One customer account to analyse.',
                    'minimum'     => 1,
                ),
                'include_all'       => array(
                    'type'        => 'boolean',
                    'description' => 'Analyse the biggest spenders instead of one account.',
                    'default'     => false,
                ),
                'calculation_type'  => array(
                    'type'        => 'string',
                    'description' => 'Which components to report. Default both.',
                    'enum'        => self::TYPES,
                    'default'     => 'both',
                ),
                'timeframe_months'  => array(
                    'type'        => 'integer',
                    'description' => 'Length of the historical window, ending today. Default 12.',
                    'minimum'     => self::MIN_TIMEFRAME,
                    'maximum'     => self::MAX_TIMEFRAME,
                    'default'     => 12,
                ),
                'prediction_months' => array(
                    'type'        => 'integer',
                    'description' => 'How far forward to project. Default 12.',
                    'minimum'     => self::MIN_PREDICTION,
                    'maximum'     => self::MAX_PREDICTION,
                    'default'     => 12,
                ),
                'top_count'         => array(
                    'type'        => 'integer',
                    'description' => 'How many spenders to report when include_all is true. Default 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_TOP,
                    'default'     => self::DEFAULT_TOP,
                ),
            ),
            'required'   => array('currency'),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();
        $known = array(
            'currency',
            'customer_id',
            'include_all',
            'calculation_type',
            'timeframe_months',
            'prediction_months',
            'top_count',
        );

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $known, true)) {
                $fields[ $key ] = array(self::REFUSED_ARGUMENTS[ $key ] ?? 'Unknown field.');
            }
        }

        $currency = strtoupper($this->stringParam($arguments, 'currency'));

        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            $fields['currency'] = array('An ISO 4217 code is required, because spend and every projection are amounts in it.');
        }

        $customerId = null;

        if (array_key_exists('customer_id', $arguments)) {
            $value = $this->intParam($arguments, 'customer_id', 0);

            if ($value < 1) {
                $fields['customer_id'] = array('A positive account id.');
            } else {
                $customerId = $value;
            }
        }

        $includeAll = $this->boolParam($arguments, 'include_all', false);

        if (null !== $customerId && $includeAll) {
            $fields['include_all'] = array('Choose one account with customer_id or every spender with include_all, not both.');
        }

        if (null === $customerId && ! $includeAll) {
            $fields['customer_id'] = array('Name an account with customer_id, or set include_all to analyse the biggest spenders.');
        }

        $type = strtolower($this->stringParam($arguments, 'calculation_type', 'both'));

        if (! in_array($type, self::TYPES, true)) {
            $fields['calculation_type'] = array('One of: ' . implode(', ', self::TYPES) . '.');
        }

        $timeframe = $this->intParam($arguments, 'timeframe_months', 12);

        if ($timeframe < self::MIN_TIMEFRAME || $timeframe > self::MAX_TIMEFRAME) {
            $fields['timeframe_months'] = array('Between ' . self::MIN_TIMEFRAME . ' and ' . self::MAX_TIMEFRAME . '.');
        }

        $prediction = $this->intParam($arguments, 'prediction_months', 12);

        if ($prediction < self::MIN_PREDICTION || $prediction > self::MAX_PREDICTION) {
            $fields['prediction_months'] = array('Between ' . self::MIN_PREDICTION . ' and ' . self::MAX_PREDICTION . '.');
        }

        $top = $this->intParam($arguments, 'top_count', self::DEFAULT_TOP);

        if ($top < 1 || $top > self::MAX_TOP) {
            $fields['top_count'] = array('Between 1 and ' . self::MAX_TOP . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The lifetime value request cannot be read.', $fields);
        }

        $today = new \DateTimeImmutable('today');
        $from = $today->modify('-' . $timeframe . ' months');

        $customers = $this->orders->topCustomers(
            new OrderQuery(
                customerId: $customerId,
                createdAfter: $from,
                createdBefore: $today->modify('+1 day -1 second'),
            ),
            $currency,
            $includeAll ? $top : 1
        );

        if (null !== $customerId && array() === $customers) {
            return $this->errors->notFound(
                'No paid orders for that account in the window.',
                array(
                    'customer_id' => $customerId,
                    'currency'    => $currency,
                    'date_from'   => $from->format('Y-m-d'),
                )
            );
        }

        $rows = array();

        foreach ($customers as $customer) {
            $rows[] = $this->calculate($customer, $type, $timeframe, $prediction, $today);
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['total_clv'] <=> $a['total_clv'])
                ?: ((int) $a['customer_id'] <=> (int) $b['customer_id'])
        );

        return $this->success(
            sprintf(
                '%d customer(s) valued in %s over %d month(s).',
                count($rows),
                $currency,
                $timeframe
            ),
            array(
                'calculation_type'  => $type,
                'currency'          => $currency,
                'timeframe_months'  => $timeframe,
                'prediction_months' => $prediction,
                'window'            => array(
                    'from' => $from->format('Y-m-d'),
                    'to'   => $today->format('Y-m-d'),
                ),
                'total_customers'   => count($rows),
                'customers'         => $rows,
            )
        );
    }

    /**
     * One customer's value, in the components the caller asked for.
     *
     * @return array<string, mixed>
     */
    private function calculate(
        CustomerSpend $customer,
        string $type,
        int $timeframe,
        int $prediction,
        \DateTimeImmutable $today,
    ): array {
        $historical = (float) $customer->spend->toDecimal();
        $rate = $timeframe > 0 ? $historical / $timeframe : 0.0;
        $projected = 'historical' === $type ? 0.0 : $rate * $prediction;

        $total = match ($type) {
            'historical' => $historical,
            'predictive' => $projected,
            default      => $historical + $projected,
        };

        $days = null === $customer->lastOrderAt
            ? null
            : (int) $customer->lastOrderAt->diff($today)->days;

        return array(
            'customer_id'      => $customer->customerId,
            'email'            => $customer->email,
            'name'             => $customer->name,
            'historical_clv'   => round($historical, 2),
            'predictive_clv'   => round($projected, 2),
            'total_clv'        => round($total, 2),
            'monthly_clv_rate' => round($rate, 2),
            'order_count'      => $customer->orderCount,
            'avg_order_value'  => $customer->orderCount > 0
                ? round($historical / $customer->orderCount, 2)
                : 0.0,
            'churn_risk'       => $this->churnRisk($days),
            'first_order_date' => $customer->firstOrderAt?->format('Y-m-d'),
            'last_order_date'  => $customer->lastOrderAt?->format('Y-m-d'),
            'days_since_last'  => $days,
        );
    }

    /**
     * A band for how long it has been since the customer last bought.
     *
     * The thresholds are the reference's, named here because they were literals
     * in three branches there: under a month is low, under three is medium, and
     * anything longer is high.
     */
    private function churnRisk(?int $days): string
    {
        if (null === $days) {
            return 'high';
        }

        if ($days < self::CHURN_MEDIUM_DAYS) {
            return 'low';
        }

        return $days < self::CHURN_HIGH_DAYS ? 'medium' : 'high';
    }
}
