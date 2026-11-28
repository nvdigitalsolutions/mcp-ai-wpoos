<?php

/**
 * Customer accounts: one, a page, a search, or one account's orders.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Woo_Customers` (Proposal 164 section 164.184).
 *
 * ## Read-only, like the original
 *
 * The reference advertises `get`, `list`, `search` and `get_orders`, and says so
 * in its own capability flags ("Only read operations for now"). This is the same
 * surface. Account creation and deletion belong to the host: an account is an
 * identity, an audit subject and a login, and a tool that wrote them would need a
 * policy for all three.
 *
 * ## Identity comes from the directory, orders from the order store
 *
 * The two halves are different stores on purpose. `CustomerDirectoryInterface`
 * answers who the host knows about — including accounts that never ordered, which
 * is the whole reason `list` and `search` can be answered at all. The order store
 * answers what one account bought. The reference read both through
 * `WC_Customer`, whose `get_order_count()` and `get_total_spent()` are
 * WooCommerce-maintained summaries that this runtime does not keep.
 *
 * That is why **`total_spent` is not reported**. A sum needs a currency, and a
 * lookup tool that quietly added one currency's orders or labelled euros with
 * the store default is the defect this family has corrected five times already.
 * `customer_lifetime_value` reports spend per currency; this reports the facts
 * that do not need one — an order count and the date of the last order.
 *
 * ## Six fields the host does not have
 *
 * The reference's detailed view adds `billing` and `shipping` address blocks,
 * `avatar_url`, `username`, `first_name` and `last_name`. This runtime stores a
 * single `name`, no address on the account or the order (which is also why
 * `segment_customers` refuses its geographic type), and an avatar would be an
 * external URL a lookup does not need to fetch. None of them is invented here.
 *
 * ## The role filter's boundary is the capability, not the enum
 *
 * The reference's schema offers `customer`, `subscriber` and `all`, and its
 * server passes whatever string it is given to `WP_User_Query` — so
 * `role: administrator` listed administrators despite not being offered. The
 * enumeration was decoration. Here the boundary is the declared `list_users`
 * capability, which is the capability the reference itself checked: a caller
 * allowed to list users may list them, and a role the host does not use returns
 * nobody, which is a true answer rather than an error.
 *
 * ## Counts that were floats
 *
 * `total_pages` was `ceil( $total / $per_page )`, a float, in a payload that
 * otherwise carries integers, and `get_orders` reported `total` from a paginated
 * query. Both are integers here, and `total` counts matches across pages.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\CustomerDirectoryInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\CustomerQuery;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Core\Tool\AbstractTool;

final class WoocommerceCustomersTool extends AbstractTool
{
    /** The actions this tool performs. */
    public const ACTIONS = array('get', 'list', 'search', 'get_orders');

    /** Accounts per page when the caller does not say. */
    public const DEFAULT_PER_PAGE = 10;

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly CustomerDirectoryInterface $directory,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'woo_customers';
    }

    public function getName(): string
    {
        return 'WooCommerce Customers';
    }

    public function getDescription(): string
    {
        return 'Looks up customer accounts by id, lists or searches them by name and email with pagination, and lists one account orders. Reports one name because the host stores one, and reports no spend because a sum needs a currency.';
    }

    public function getRequiredCapability(): string
    {
        return 'list_users';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'action'       => array(
                    'type'        => 'string',
                    'description' => 'What to do. Default list.',
                    'enum'        => self::ACTIONS,
                    'default'     => 'list',
                ),
                'customer_id'  => array(
                    'type'        => 'integer',
                    'description' => 'The account for get and get_orders.',
                    'minimum'     => 1,
                ),
                'search'       => array(
                    'type'        => 'string',
                    'description' => 'Matched against the account name and email, for the search action.',
                ),
                'role'         => array(
                    'type'        => 'string',
                    'description' => 'Filter by host role, or all. Default all.',
                    'default'     => 'all',
                ),
                'order_status' => array(
                    'type'        => 'string',
                    'description' => 'Filter the get_orders listing by status, or any. Default any.',
                    'enum'        => array_merge(OrderStatus::values(), array('any')),
                    'default'     => 'any',
                ),
                'per_page'     => array(
                    'type'        => 'integer',
                    'description' => 'Accounts or orders per page. Default 10.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => self::DEFAULT_PER_PAGE,
                ),
                'page'         => array(
                    'type'        => 'integer',
                    'description' => 'Page number. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();
        $known = array('action', 'customer_id', 'search', 'role', 'order_status', 'per_page', 'page');

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, $known, true)) {
                $fields[ $key ] = array('Unknown field.');
            }
        }

        $action = strtolower($this->stringParam($arguments, 'action', 'list'));

        if (! in_array($action, self::ACTIONS, true)) {
            $fields['action'] = array('One of: ' . implode(', ', self::ACTIONS) . '.');
        }

        $perPage = $this->intParam($arguments, 'per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1 || $perPage > 100) {
            $fields['per_page'] = array('Between 1 and 100.');
        }

        $page = $this->intParam($arguments, 'page', 1);

        if ($page < 1) {
            $fields['page'] = array('A page number starts at 1.');
        }

        $customerId = null;

        if (in_array($action, array('get', 'get_orders'), true)) {
            $customerId = $this->intParam($arguments, 'customer_id', 0);

            if ($customerId < 1) {
                $fields['customer_id'] = array('Required for the ' . $action . ' action.');
            }
        }

        $search = $this->stringParam($arguments, 'search');

        if ('search' === $action && '' === $search) {
            $fields['search'] = array('Required for the search action.');
        }

        $role = strtolower($this->stringParam($arguments, 'role', 'all'));
        $roleFilter = 'all' === $role ? null : $role;

        if (null !== $roleFilter && 1 !== preg_match('/^[a-z][a-z0-9_]{0,31}$/', $roleFilter)) {
            $fields['role'] = array('A host role name, or all.');
        }

        $statusName = strtolower($this->stringParam($arguments, 'order_status', 'any'));
        $statusFilter = null;

        if ('any' !== $statusName) {
            $statusFilter = OrderStatus::tryFrom($statusName);

            if (null === $statusFilter) {
                $fields['order_status'] = array('One of: ' . implode(', ', OrderStatus::values()) . ', or any.');
            }
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The customer lookup cannot be read.', $fields);
        }

        return match ($action) {
            'get'        => $this->show((int) $customerId),
            'search'     => $this->listing($search, $roleFilter, $page, $perPage),
            'get_orders' => $this->ordersFor((int) $customerId, $statusFilter, $page, $perPage),
            default      => $this->listing('', $roleFilter, $page, $perPage),
        };
    }

    /**
     * One account, with the order facts that need no currency.
     */
    private function show(int $id): mixed
    {
        $customer = $this->directory->find($id);

        if (null === $customer) {
            return $this->errors->notFound('No account with that id.', array('customer_id' => $id));
        }

        $data = $customer->jsonSerialize();

        $orders = $this->orders->query(new OrderQuery(customerId: $id, perPage: 1));

        $data['orders'] = array(
            'count'           => $orders->total,
            'last_order_date' => array() === $orders->items ? null : $orders->items[0]->createdAt->format('Y-m-d'),
        );

        return $this->success(sprintf('Customer %d: %s.', $id, $customer->name), $data);
    }

    /**
     * A page of accounts, optionally matching a search term and a role.
     */
    private function listing(string $search, ?string $role, int $page, int $perPage): mixed
    {
        $collection = $this->directory->query(
            new CustomerQuery(search: $search, role: $role, page: $page, perPage: $perPage)
        );

        return $this->success(
            sprintf('%d account(s) matched, %d on this page.', $collection->total, count($collection->items)),
            $collection->jsonSerialize()
        );
    }

    /**
     * One account's orders, newest first.
     */
    private function ordersFor(int $id, ?OrderStatus $status, int $page, int $perPage): mixed
    {
        $customer = $this->directory->find($id);

        if (null === $customer) {
            return $this->errors->notFound('No account with that id.', array('customer_id' => $id));
        }

        $collection = $this->orders->query(
            new OrderQuery(customerId: $id, status: $status, page: $page, perPage: $perPage)
        );

        return $this->success(
            sprintf(
                '%d order(s) for customer %d, %d on this page.',
                $collection->total,
                $id,
                count($collection->items)
            ),
            array(
                'customer_id' => $id,
                'orders'      => array_map(
                    static fn (Order $order): array => array(
                        'id'           => $order->id,
                        'order_number' => $order->number,
                        'status'       => $order->status->value,
                        'total'        => (float) $order->total->toDecimal(),
                        'currency'     => $order->currency(),
                        'date_created' => $order->createdAt->format('Y-m-d'),
                    ),
                    $collection->items
                ),
                'total'       => $collection->total,
                'page'        => $collection->page,
                'per_page'    => $collection->perPage,
                'total_pages' => $collection->totalPages,
            )
        );
    }
}
