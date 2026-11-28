<?php

/**
 * Move many orders to one status, one guarded transition at a time.
 *
 * Port of `WP_MCP_AI_Tool_Bulk_Order_Status_Update` (Proposal 164 section
 * 164.176). The register deferred it for a specific reason — *"a bulk update that
 * skips the rule is how an order jumps from refunded to processing"* — and the
 * reference skips it: `update_status()` is called with no check at all.
 *
 * ## Every order is checked, and a refusal does not stop the rest
 *
 * Each selected order goes through `Order::withStatus()`, so the same graph that
 * guards a single change guards a bulk one. A refused order is reported with the
 * statuses it could have moved to, and the remaining orders are still processed:
 * refusing the whole batch because one order is `refunded` would make the tool
 * unusable on any real store, and the caller can select precisely by status
 * instead.
 *
 * ## Partial application is safe because a retry is a no-op
 *
 * Each order is written independently and the response names exactly which ones
 * changed. A call that fails part-way does not roll back what it already wrote,
 * which is normally a reason to add a transaction — but moving an order to the
 * status it already has is a successful no-op here, so re-running the same call
 * finishes the job and changes nothing twice. That is why idempotence is worth
 * more than atomicity for this operation.
 *
 * ## There is no `page`
 *
 * A filter selects by *current* status, and applying the change moves rows out of
 * that filter. Page 2 of the same filter would therefore skip orders rather than
 * continue — the classic offset-paging-over-a-mutating-set bug. The call is
 * capped at 100 orders and reports `truncated` when more matched than were
 * selected, so a caller narrows the filter instead of paging through it.
 *
 * ## Three arguments are refused, not ignored
 *
 * `send_notification` is refused when true: this runtime binds no mail channel to
 * orders, and silently not sending an email the caller asked for is the kind of
 * quiet failure this project keeps correcting. `note` and `note_type` are refused
 * because order notes have no model or storage here.
 *
 * ## Corrections
 *
 * - `new_status` was `sanitize_text_field()`'d — an HTML filter on an enum — and
 *   never validated against the seven statuses. It is validated here, and a `wc-`
 *   prefix is still stripped as the reference did.
 * - `order_ids` went through `absint()`, turning a negative id into a positive
 *   one. Ids must be positive.
 * - `limit` had no upper bound, so a filter could select an unbounded number of
 *   orders to write. It is capped at 100, and the cap is reported rather than
 *   applied silently.
 * - The filter's dates were concatenated into a range string with no validation,
 *   so an unparseable date silently widened the selection to everything. Both
 *   bounds are parsed strictly and `date_to` includes the day it names.
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
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Service\Order\OrderStatusTransition;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Support\DateBound;
use Nvoos\Core\Tool\AbstractTool;

final class BulkOrderStatusUpdateTool extends AbstractTool
{
    /** The most orders one call will consider. */
    public const MAX_ORDERS = 100;

    /** The filter keys this tool honours. */
    public const FILTER_KEYS = array('status', 'customer_id', 'date_from', 'date_to', 'limit');

    /**
     * Arguments the reference accepts that this tool refuses, with the reason.
     *
     * @var array<string, string>
     */
    public const REFUSED_ARGUMENTS = array(
        'send_notification' => 'This runtime binds no mail channel to orders, so a notification cannot be sent. A status change here is silent.',
        'note'              => 'Order notes have no model or storage in this runtime, so a note would be discarded.',
        'note_type'         => 'Order notes have no model or storage in this runtime, so a note would be discarded.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'bulk_order_status_update';
    }

    public function getName(): string
    {
        return 'Bulk Update Order Status';
    }

    public function getDescription(): string
    {
        return 'Moves up to 100 orders to one status, selecting them by id or by a filter on their current status, customer or dates. Each order is checked against the allowed status changes, and a refused order is reported without stopping the rest. Supports a dry run.';
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
                'new_status' => array(
                    'type'        => 'string',
                    'description' => 'The status every selected order should move to. A wc- prefix is ignored.',
                    'enum'        => OrderStatus::values(),
                ),
                'order_ids'  => array(
                    'type'        => 'array',
                    'description' => 'The orders to move. Wins over filter when supplied. At most 100.',
                    'items'       => array('type' => 'integer'),
                    'maxItems'    => self::MAX_ORDERS,
                ),
                'filter'     => array(
                    'type'        => 'object',
                    'description' => 'Selects orders when order_ids is omitted. Selects on the current status, so applying the change removes an order from the filter.',
                    'properties'  => array(
                        'status'      => array(
                            'type'        => 'string',
                            'description' => 'The current status to select.',
                            'enum'        => OrderStatus::values(),
                        ),
                        'customer_id' => array(
                            'type'        => 'integer',
                            'description' => 'Select one customer.',
                            'minimum'     => 1,
                        ),
                        'date_from'   => array(
                            'type'        => 'string',
                            'description' => 'Include orders created on or after this day (YYYY-MM-DD).',
                        ),
                        'date_to'     => array(
                            'type'        => 'string',
                            'description' => 'Include orders created on or before this day (YYYY-MM-DD).',
                        ),
                        'limit'       => array(
                            'type'        => 'integer',
                            'description' => 'How many orders to select. Default 100, at most 100.',
                            'minimum'     => 1,
                            'maximum'     => self::MAX_ORDERS,
                            'default'     => self::MAX_ORDERS,
                        ),
                    ),
                ),
                'dry_run'    => array(
                    'type'        => 'boolean',
                    'description' => 'Report what would change without writing anything.',
                ),
            ),
            'required'   => array('new_status'),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();
        $status = $this->newStatus($arguments, $fields);
        $dryRun = $this->boolParam($arguments, 'dry_run', false);

        $this->refuseUnsupported($arguments, $fields);

        if (array() !== $fields) {
            return $this->errors->validationFailed('The status change cannot be applied.', $fields);
        }

        if (null === $status) {
            return $this->errors->validationFailed(
                'The status change cannot be applied.',
                array('new_status' => array('A new status is required.'))
            );
        }

        $selection = $this->select($arguments, $fields);

        if (array() !== $fields) {
            return $this->errors->validationFailed('The status change cannot be applied.', $fields);
        }

        $outcomes = array();
        $updated = 0;
        $unchanged = 0;
        $refused = 0;

        foreach ($selection['orders'] as $order) {
            $outcome = $this->move($order, $status, $dryRun);
            $outcomes[] = $outcome;

            if ('updated' === $outcome['outcome']) {
                $updated++;
            } elseif ('unchanged' === $outcome['outcome']) {
                $unchanged++;
            } else {
                $refused++;
            }
        }

        foreach ($selection['missing'] as $id) {
            $outcomes[] = array('order_id' => $id, 'outcome' => 'not_found');
        }

        $selected = count($selection['orders']);

        return $this->success(
            sprintf(
                '%s %d of %d order(s) to %s, %d refused, %d already there.',
                $dryRun ? 'Would move' : 'Moved',
                $updated,
                $selected,
                $status->value,
                $refused,
                $unchanged
            ),
            array(
                'dry_run'    => $dryRun,
                'new_status' => $status->value,
                'requested'  => $selection['requested'],
                'selected'   => $selected,
                'updated'    => $updated,
                'unchanged'  => $unchanged,
                'refused'    => $refused,
                'not_found'  => count($selection['missing']),
                'truncated'  => $selection['requested'] > $selected,
                'results'    => $outcomes,
            )
        );
    }

    /**
     * The requested status, or null after recording a field error.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function newStatus(array $arguments, array &$fields): ?OrderStatus
    {
        if (! isset($arguments['new_status'])) {
            $fields['new_status'] = array('A new status is required.');

            return null;
        }

        // The reference stripped a `wc-` prefix; a caller may still carry one.
        $raw = preg_replace('/^wc-/', '', strtolower($this->stringParam($arguments, 'new_status')));
        $status = OrderStatus::tryFrom((string) $raw);

        if (null === $status) {
            $fields['new_status'] = array('One of: ' . implode(', ', OrderStatus::values()) . '.');
        }

        return $status;
    }

    /**
     * Record a field error for every argument the tool does not take.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function refuseUnsupported(array $arguments, array &$fields): void
    {
        $known = array('new_status', 'order_ids', 'filter', 'dry_run', 'send_notification', 'note', 'note_type');

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $known, true)) {
                $fields[$key] = array('Unknown field.');
            }
        }

        // A false notification is not a request to send one, so it is honoured
        // by doing nothing; a true one cannot be honoured.
        if ($this->boolParam($arguments, 'send_notification', false)) {
            $fields['send_notification'] = array(self::REFUSED_ARGUMENTS['send_notification']);
        }

        foreach (array('note', 'note_type') as $key) {
            if (array_key_exists($key, $arguments) && null !== $arguments[$key]) {
                $fields[$key] = array(self::REFUSED_ARGUMENTS[$key]);
            }
        }
    }

    /**
     * The orders to consider, by explicit id or by filter.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     *
     * @return array{orders: list<Order>, requested: int, missing: list<int>}
     */
    private function select(array $arguments, array &$fields): array
    {
        if (array_key_exists('order_ids', $arguments)) {
            return $this->selectById($arguments['order_ids'], $fields);
        }

        return $this->selectByFilter($arguments['filter'] ?? array(), $fields);
    }

    /**
     * Resolve an explicit id list.
     *
     * @param mixed                $raw    Raw ids.
     * @param array<string, mixed> $fields Field errors, by reference.
     *
     * @return array{orders: list<Order>, requested: int, missing: list<int>}
     */
    private function selectById(mixed $raw, array &$fields): array
    {
        if (! is_array($raw)) {
            $fields['order_ids'] = array('An array of order ids.');

            return array('orders' => array(), 'requested' => 0, 'missing' => array());
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        )));

        if (array() === $ids) {
            $fields['order_ids'] = array('At least one positive order id is required.');

            return array('orders' => array(), 'requested' => 0, 'missing' => array());
        }

        if (count($ids) > self::MAX_ORDERS) {
            $fields['order_ids'] = array(sprintf('At most %d orders per call.', self::MAX_ORDERS));

            return array('orders' => array(), 'requested' => 0, 'missing' => array());
        }

        $orders = array();
        $missing = array();

        foreach ($ids as $id) {
            $order = $this->orders->find($id);

            if (null === $order) {
                $missing[] = $id;
            } else {
                $orders[] = $order;
            }
        }

        return array('orders' => $orders, 'requested' => count($ids), 'missing' => $missing);
    }

    /**
     * Select by filter.
     *
     * @param mixed                $raw    Raw filter.
     * @param array<string, mixed> $fields Field errors, by reference.
     *
     * @return array{orders: list<Order>, requested: int, missing: list<int>}
     */
    private function selectByFilter(mixed $raw, array &$fields): array
    {
        if (! is_array($raw)) {
            $fields['filter'] = array('An object of filter criteria.');

            return array('orders' => array(), 'requested' => 0, 'missing' => array());
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::FILTER_KEYS, true)) {
                $fields['filter.' . $key] = array('Unknown filter.');
            }
        }

        $status = null;

        if (isset($raw['status'])) {
            $candidate = is_string($raw['status']) ? strtolower(trim($raw['status'])) : '';
            $status = OrderStatus::tryFrom((string) preg_replace('/^wc-/', '', $candidate));

            if (null === $status) {
                $fields['filter.status'] = array('One of: ' . implode(', ', OrderStatus::values()) . '.');
            }
        }

        $customerId = null;

        if (isset($raw['customer_id'])) {
            $candidate = (int) $raw['customer_id'];

            if ($candidate < 1) {
                $fields['filter.customer_id'] = array('A customer id starts at 1.');
            } else {
                $customerId = $candidate;
            }
        }

        $createdAfter = null;

        if (isset($raw['date_from'])) {
            $createdAfter = DateBound::parse($raw['date_from']);

            if (null === $createdAfter) {
                $fields['filter.date_from'] = array(DateBound::reason());
            }
        }

        $createdBefore = null;

        if (isset($raw['date_to'])) {
            $createdBefore = DateBound::parse($raw['date_to'], true);

            if (null === $createdBefore) {
                $fields['filter.date_to'] = array(DateBound::reason());
            }
        }

        if (null !== $createdAfter && null !== $createdBefore && $createdAfter > $createdBefore) {
            $fields['filter.date_from'] = array('Must not be after date_to.');
        }

        $limit = isset($raw['limit']) ? (int) $raw['limit'] : self::MAX_ORDERS;

        if ($limit < 1 || $limit > self::MAX_ORDERS) {
            $fields['filter.limit'] = array(sprintf('1 to %d.', self::MAX_ORDERS));
        }

        if (array() !== $fields) {
            return array('orders' => array(), 'requested' => 0, 'missing' => array());
        }

        $collection = $this->orders->query(
            new OrderQuery(
                status: $status,
                customerId: $customerId,
                createdAfter: $createdAfter,
                createdBefore: $createdBefore,
                perPage: $limit,
            )
        );

        return array(
            'orders'    => $collection->items,
            'requested' => $collection->total,
            'missing'   => array(),
        );
    }

    /**
     * Apply the move to one order, or say why it cannot be applied.
     *
     * @return array<string, mixed>
     */
    private function move(Order $order, OrderStatus $status, bool $dryRun): array
    {
        try {
            $candidate = $order->withStatus($status);
        } catch (ConflictException $e) {
            return array(
                'order_id' => $order->id,
                'number'   => $order->number,
                'outcome'  => 'refused',
                'status'   => $order->status->value,
                'reason'   => $e->getMessage(),
                'allowed'  => $e->context['allowed'] ?? array(),
            );
        }

        if ($candidate === $order) {
            return array(
                'order_id' => $order->id,
                'number'   => $order->number,
                'outcome'  => 'unchanged',
                'status'   => $order->status->value,
            );
        }

        if (! $dryRun) {
            $this->orders->save($candidate);
        }

        return array(
            'order_id'   => $order->id,
            'number'     => $order->number,
            'outcome'    => 'updated',
            'old_status' => $order->status->value,
            'new_status' => $candidate->status->value,
            'allowed'    => array_map(
                static fn (OrderStatus $next): string => $next->value,
                OrderStatusTransition::allowedFrom($candidate->status)
            ),
        );
    }
}
