<?php

/**
 * Order operations: read one, list or search, and move the status.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Woo_Orders` (Proposal 164 section 164.175). The
 * register deferred it with "generic order CRUD over WooCommerce. Prerequisite:
 * OrderStoreInterface, then an adapter" — both now exist, so this is the tool
 * that tests whether the contract was designed for more than one caller.
 *
 * ## The reference advertises three actions, implements six, and documents six
 *
 * Its schema enum is `get`, `list`, `search`. Its description promises "update
 * statuses, add notes, and process refunds". Its `execute()` switch implements
 * all six, and its usage guidance repeats the three. A caller reading the schema
 * cannot discover half the tool; a caller reading the description cannot tell
 * what is actually callable. The port declares every action it performs and
 * refuses the ones it does not, by name.
 *
 * ## Two actions are refused, not omitted
 *
 * `add_note` needs an order-note record, which this model has none of, and a
 * note that is stored nowhere is worse than a refusal. `refund` moves money: it
 * needs a payment and refund model, and the reference's version is not something
 * to reproduce — it splits the requested amount across the lines proportionally
 * and calls `floatval()` on the amount, so `"abc"` becomes a zero refund attempt
 * and a partial refund silently refunds every line by a fraction. Both actions
 * are refused with that reason, so a caller learns why rather than receiving a
 * success envelope for work that did not happen.
 *
 * ## `search` is a real search
 *
 * In the reference, `search` calls `list_orders()` unchanged — there is no search
 * term parameter anywhere in the schema, so the action is a synonym for `list`.
 * The port implements it: a term is required and matches the order number or the
 * billing email.
 *
 * ## The status change is guarded
 *
 * The reference calls `$order->update_status( $new_status, '', true )` with no
 * check, so an order can move from `refunded` to `processing`. The port asks
 * {@see OrderStatusTransition} first, through `Order::withStatus()`, and the store
 * asks again on write. A status is a claim about what happened to money.
 *
 * ## Corrections
 *
 * - `per_page` was clamped with `min( absint( ... ), 100 )`, so asking for 1,000
 *   silently returned 100. Out of range is a field error here.
 * - `customer` went through `absint()`, turning `-5` into `5`, so a caller asking
 *   about one customer was answered about another. It must be positive.
 * - `status` was `sanitize_key()`'d and passed through; an unknown status is an
 *   empty result rather than an error. It is validated against the seven.
 * - A `wc-` prefix is still stripped from `new_status`, because that is what the
 *   reference did and a caller may be carrying it.
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
use Nvoos\Core\Tool\AbstractTool;

final class WoocommerceOrdersTool extends AbstractTool
{
    /** The actions this tool performs. */
    public const ACTIONS = array('get', 'list', 'search', 'update_status');

    /**
     * The reference's remaining actions, and why this tool does not perform them.
     *
     * @var array<string, string>
     */
    public const REFUSED_ACTIONS = array(
        'add_note' => 'Order notes have no model or storage in this runtime, and a note that is stored nowhere is worse than a refusal.',
        'refund'   => 'A refund moves money and needs a payment and refund model this runtime does not have. The reference also splits the amount across lines proportionally and reads it with floatval, which is not behaviour to reproduce.',
    );

    /**
     * Arguments that belong to a refused action.
     *
     * Declared here so a caller who sends one learns that the action is refused,
     * rather than that the field is unknown.
     *
     * @var array<string, string>
     */
    public const REFUSED_ARGUMENTS = array(
        'note'           => 'Belongs to add_note, which this tool does not perform.',
        'note_type'      => 'Belongs to add_note, which this tool does not perform.',
        'refund_amount'  => 'Belongs to refund, which this tool does not perform.',
        'refund_reason'  => 'Belongs to refund, which this tool does not perform.',
        'restock_items'  => 'Belongs to refund, which this tool does not perform.',
    );

    /** The most orders one page will hold. */
    public const MAX_PER_PAGE = 100;

    /** How many orders a page holds when the caller does not say. */
    public const DEFAULT_PER_PAGE = 10;

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'woo_orders';
    }

    public function getName(): string
    {
        return 'WooCommerce Orders';
    }

    public function getDescription(): string
    {
        return 'Reads orders and moves their status. Actions: get one, list, search by order number or billing email, and update_status, which is refused if the order cannot move to the requested status. Notes and refunds are not supported.';
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
                'action'     => array(
                    'type'        => 'string',
                    'description' => 'get reads one order, list pages them, search requires a term, update_status moves the status.',
                    'enum'        => self::ACTIONS,
                    'default'     => 'list',
                ),
                'order_id'   => array(
                    'type'        => 'integer',
                    'description' => 'The order to read or move. Required for get and update_status.',
                    'minimum'     => 1,
                ),
                'per_page'   => array(
                    'type'        => 'integer',
                    'description' => 'How many orders this page holds. Default 10, at most 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => self::DEFAULT_PER_PAGE,
                ),
                'page'       => array(
                    'type'        => 'integer',
                    'description' => 'Which page to read. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'status'     => array(
                    'type'        => 'string',
                    'description' => 'Restrict the listing to one status.',
                    'enum'        => OrderStatus::values(),
                ),
                'customer'   => array(
                    'type'        => 'integer',
                    'description' => 'Restrict the listing to one customer.',
                    'minimum'     => 1,
                ),
                'search'     => array(
                    'type'        => 'string',
                    'description' => 'Matched against the order number and the billing email. Required for the search action.',
                ),
                'new_status' => array(
                    'type'        => 'string',
                    'description' => 'The status to move to, for update_status. A wc- prefix is ignored.',
                    'enum'        => OrderStatus::values(),
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $action = strtolower($this->stringParam($arguments, 'action', 'list'));

        if (isset(self::REFUSED_ACTIONS[$action])) {
            return $this->errors->validationFailed(
                'The order action cannot be performed.',
                array('action' => array(self::REFUSED_ACTIONS[$action]))
            );
        }

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                'The order action cannot be performed.',
                array('action' => array('One of: ' . implode(', ', self::ACTIONS) . '.'))
            );
        }

        $fields = array();
        $this->refuseUnsupported($arguments, $fields);

        if ('get' === $action) {
            return $this->read($arguments, $fields);
        }

        if ('update_status' === $action) {
            return $this->move($arguments, $fields);
        }

        return $this->listing($arguments, $action, $fields);
    }

    /**
     * Record a field error for every argument the tool does not take.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function refuseUnsupported(array $arguments, array &$fields): void
    {
        $known = array_merge(
            array('action', 'order_id', 'per_page', 'page', 'status', 'customer', 'search', 'new_status'),
            array_keys(self::REFUSED_ARGUMENTS)
        );

        foreach (array_keys($arguments) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            $fields[$key] = array('Unknown field.');
        }

        // A refused action's arguments are named as such rather than as unknown.
        foreach (self::REFUSED_ARGUMENTS as $key => $reason) {
            if (array_key_exists($key, $arguments)) {
                $fields[$key] = array($reason);
            }
        }
    }

    /**
     * The `get` action.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function read(array $arguments, array &$fields): mixed
    {
        $id = $this->intParam($arguments, 'order_id', 0);

        if ($id < 1) {
            $fields['order_id'] = array('A positive order id is required for get.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The order cannot be read.', $fields);
        }

        $order = $this->orders->find($id);

        if (null === $order) {
            return $this->errors->notFound(
                sprintf('Order %d not found.', $id),
                array('order_id' => $id)
            );
        }

        return $this->success(
            sprintf('Order %s.', $order->number),
            $this->present($order, true)
        );
    }

    /**
     * The `list` and `search` actions.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function listing(array $arguments, string $action, array &$fields): mixed
    {
        $perPage = $this->intParam($arguments, 'per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            $fields['per_page'] = array(sprintf('1 to %d.', self::MAX_PER_PAGE));
        }

        $page = $this->intParam($arguments, 'page', 1);

        if ($page < 1) {
            $fields['page'] = array('A page number starts at 1.');
        }

        $status = null;

        if (isset($arguments['status'])) {
            $status = OrderStatus::tryFrom($this->stringParam($arguments, 'status'));

            if (null === $status) {
                $fields['status'] = array('One of: ' . implode(', ', OrderStatus::values()) . '.');
            }
        }

        $customerId = null;

        if (isset($arguments['customer'])) {
            $candidate = $this->intParam($arguments, 'customer', 0);

            if ($candidate < 1) {
                $fields['customer'] = array('A customer id starts at 1.');
            } else {
                $customerId = $candidate;
            }
        }

        $search = trim($this->stringParam($arguments, 'search'));

        if ('search' === $action && '' === $search) {
            $fields['search'] = array('The search action needs a term.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The order list cannot be read.', $fields);
        }

        $collection = $this->orders->query(
            new OrderQuery(
                search: $search,
                status: $status,
                customerId: $customerId,
                page: $page,
                perPage: $perPage,
            )
        );

        $orders = array_map(
            fn (Order $order): array => $this->present($order, false),
            $collection->items
        );

        return $this->success(
            sprintf('Found %d orders.', $collection->total),
            array(
                'total_count' => $collection->total,
                'returned'    => count($orders),
                'page'        => $collection->page,
                'per_page'    => $collection->perPage,
                'total_pages' => $collection->totalPages,
                'orders'      => $orders,
            )
        );
    }

    /**
     * The `update_status` action.
     *
     * @param array<string, mixed> $arguments Raw arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function move(array $arguments, array &$fields): mixed
    {
        $id = $this->intParam($arguments, 'order_id', 0);

        if ($id < 1) {
            $fields['order_id'] = array('A positive order id is required for update_status.');
        }

        $status = null;

        if (isset($arguments['new_status'])) {
            // The reference stripped a `wc-` prefix; a caller may still carry one.
            $raw = preg_replace('/^wc-/', '', strtolower($this->stringParam($arguments, 'new_status')));
            $status = OrderStatus::tryFrom((string) $raw);

            if (null === $status) {
                $fields['new_status'] = array('One of: ' . implode(', ', OrderStatus::values()) . '.');
            }
        } else {
            $fields['new_status'] = array('A new status is required for update_status.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The order status cannot be changed.', $fields);
        }

        // `$status` is set whenever no field error was recorded. The check makes
        // that visible to the type checker rather than asserting it in prose.
        if (null === $status) {
            return $this->errors->validationFailed(
                'The order status cannot be changed.',
                array('new_status' => array('A new status is required for update_status.'))
            );
        }

        $order = $this->orders->find($id);

        if (null === $order) {
            return $this->errors->notFound(
                sprintf('Order %d not found.', $id),
                array('order_id' => $id)
            );
        }

        $previous = $order->status;

        try {
            $candidate = $order->withStatus($status);

            // `withStatus()` returns the same instance for a no-op, and a no-op
            // should not become a write.
            $moved = $candidate === $order ? $order : $this->orders->save($candidate);
        } catch (ConflictException $e) {
            return $this->errors->create(
                'invalid_status_transition',
                $e->getMessage(),
                $e->context
            );
        }

        $changed = $moved !== $order;

        return $this->success(
            $changed
                ? sprintf('Order %s moved from %s to %s.', $moved->number, $previous->value, $moved->status->value)
                : sprintf('Order %s is already %s.', $moved->number, $moved->status->value),
            array(
                'order_id'     => $moved->id,
                'number'       => $moved->number,
                'old_status'   => $previous->value,
                'new_status'   => $moved->status->value,
                'changed'      => $changed,
                'allowed_next' => array_map(
                    static fn (OrderStatus $next): string => $next->value,
                    OrderStatusTransition::allowedFrom($moved->status)
                ),
            )
        );
    }

    /**
     * The response shape for one order.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order, bool $withLines): array
    {
        $data = $order->jsonSerialize();

        if (! $withLines) {
            unset($data['lines']);
        }

        return $data;
    }
}
