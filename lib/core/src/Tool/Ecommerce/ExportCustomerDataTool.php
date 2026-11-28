<?php

/**
 * One customer's record and order history, as a single document.
 *
 * Port of `WP_MCP_AI_Tool_Export_Customer_Data` (Proposal 164 section 164.185).
 *
 * ## What the export is for, and what it is not
 *
 * The reference produces a subject-access document: one account, its contact
 * record, its order history, and optionally addresses and notes, written to a
 * file and optionally uploaded to the media library. This runtime has two of
 * those halves — the directory answers the account, the order store answers what
 * it bought — and no address model, no note model, no file writer and no media
 * library. So the document is returned rather than written, and the three
 * arguments that would need the missing halves are **refused by name** rather
 * than accepted and half-honoured.
 *
 * ## Three arguments the reference accepted and did not honour
 *
 * - `include_addresses` produced billing and shipping blocks assembled from
 *   `WC_Customer` getters. This runtime stores no address on the account or the
 *   order, which is also why `segment_customers` refuses its geographic type.
 * - `include_notes` ran `get_comments()` with `user_id => customer_id` and
 *   `type => 'order_note'`. An order note's `user_id` is its **author** — the
 *   shop manager or zero for a system note — not the customer whose order it
 *   sits on, so the query it built could not return the notes it described.
 * - `format: csv` wrote only `customer_info` and `orders`; addresses and notes
 *   never reached the CSV at all. And `format` was never checked against its own
 *   enum, so any other string fell through to the CSV branch while the response
 *   reported the string the caller sent.
 *
 * ## The id lookup had no existence check
 *
 * `customer_id` went straight into `new WC_Customer( absint( $id ) )`, and the
 * only guard afterwards was `! $customer->get_id()`. The constructor stores the
 * id it is given, so that guard tests the caller's argument rather than whether
 * an account exists. Here the id is resolved through
 * `CustomerDirectoryInterface::find()`, which returns null for an unknown id,
 * and the email through `findByEmail()`, which is the reference's
 * `get_user_by( 'email' )` without the round trip through a user object.
 *
 * ## An unbounded read, now bounded and reported
 *
 * `include_orders` called `wc_get_orders( array( 'limit' => -1 ) )`. A customer
 * with a long history produced an unbounded document. This reads pages of
 * {@see self::PAGE_SIZE} up to {@see self::MAX_ORDERS} and reports
 * `orders_total`, `orders_included` and `orders_truncated`, so a clipped export
 * says so instead of looking complete.
 *
 * ## Fields the host does not have
 *
 * Each order row is the runtime's own order document: no `payment_method`
 * (payments are not modelled) and no `order_number` beyond `number`. The
 * reference's `first_name`, `last_name`, `username` and `display_name` are one
 * `name` here, for the same reason `woo_customers` reports one.
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
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Tool\AbstractTool;

final class ExportCustomerDataTool extends AbstractTool
{
    /** Orders per store page while the history is collected. */
    public const PAGE_SIZE = 100;

    /** The most orders one export carries. */
    public const MAX_ORDERS = 1000;

    /** The arguments this tool reads. */
    public const ARGUMENTS = array(
        'customer_id',
        'email',
        'include_orders',
        'include_addresses',
        'include_notes',
        'format',
        'upload',
    );

    /** The refusal reason for each argument that asks for a missing model. */
    public const REFUSED_ARGUMENTS = array(
        'include_addresses' => 'This runtime keeps no address on the account or the order, and the reference CSV omitted the blocks it built. Prerequisite: a billing and shipping address model.',
        'include_notes'     => 'This runtime keeps no order-note model, and the reference query filtered on the note author rather than the customer orders. Prerequisite: an order-note model.',
        'format'            => 'This tool returns a document and writes no file, so only json is produced. Convert at the caller if CSV is required.',
        'upload'            => 'This runtime has no media library, so there is nowhere to upload a file. The document is returned instead.',
    );

    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly CustomerDirectoryInterface $directory,
        protected readonly OrderStoreInterface $orders,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'export_customer_data';
    }

    public function getName(): string
    {
        return 'Export Customer Data';
    }

    public function getDescription(): string
    {
        return 'Returns one customer account and, by default, its order history as a single document, resolved by id or email. This runtime keeps no address or note model and no media library, so those arguments are refused by name rather than half-honoured.';
    }

    public function getRequiredCapability(): string
    {
        return 'manage_woocommerce';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'customer_id'       => array(
                    'type'        => 'integer',
                    'description' => 'The account to export. Provide this or email.',
                    'minimum'     => 1,
                ),
                'email'             => array(
                    'type'        => 'string',
                    'description' => 'The account email to export. Provide this or customer_id.',
                ),
                'include_orders'    => array(
                    'type'        => 'boolean',
                    'description' => 'Include the order history. Default true.',
                    'default'     => true,
                ),
                'include_addresses' => array(
                    'type'        => 'boolean',
                    'description' => 'Not available: this runtime keeps no address model. Only false is accepted.',
                    'default'     => false,
                ),
                'include_notes'     => array(
                    'type'        => 'boolean',
                    'description' => 'Not available: this runtime keeps no order-note model. Only false is accepted.',
                    'default'     => false,
                ),
                'format'            => array(
                    'type'        => 'string',
                    'description' => 'Only json: the document is returned, not written to a file.',
                    'enum'        => array('json', 'csv'),
                    'default'     => 'json',
                ),
                'upload'            => array(
                    'type'        => 'boolean',
                    'description' => 'Not available: there is no media library. Only false is accepted.',
                    'default'     => false,
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $fields = array();

        foreach (array_keys($arguments) as $key) {
            if (! in_array($key, self::ARGUMENTS, true)) {
                $fields[ $key ] = array('Unknown field.');
            }
        }

        $customerId = null;
        $email = strtolower(trim($this->stringParam($arguments, 'email')));

        if (isset($arguments['customer_id']) && '' !== $arguments['customer_id']) {
            $customerId = $this->intParam($arguments, 'customer_id');

            if ($customerId < 1) {
                $fields['customer_id'] = array('A positive account id.');
            }
        }

        if (null !== $customerId && '' !== $email) {
            $fields['email'] = array('Provide customer_id or email, not both.');
        } elseif (null === $customerId && '' === $email) {
            $fields['customer_id'] = array('Provide customer_id or email.');
        } elseif ('' !== $email && false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = array('A single valid email address.');
        }

        // The three arguments that need a model this runtime does not keep, and
        // the two that need a file step it does not have.
        if ($this->boolParam($arguments, 'include_addresses')) {
            $fields['include_addresses'] = array(self::REFUSED_ARGUMENTS['include_addresses']);
        }

        if ($this->boolParam($arguments, 'include_notes')) {
            $fields['include_notes'] = array(self::REFUSED_ARGUMENTS['include_notes']);
        }

        if ('json' !== strtolower($this->stringParam($arguments, 'format', 'json'))) {
            $fields['format'] = array(self::REFUSED_ARGUMENTS['format']);
        }

        if ($this->boolParam($arguments, 'upload')) {
            $fields['upload'] = array(self::REFUSED_ARGUMENTS['upload']);
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The export request cannot be read.', $fields);
        }

        $customer = null === $customerId
            ? $this->directory->findByEmail($email)
            : $this->directory->find($customerId);

        if (null === $customer) {
            return $this->errors->notFound(
                null === $customerId
                    ? 'No account uses that email.'
                    : 'No account has that id.'
            );
        }

        $document = array(
            'customer'    => $customer->jsonSerialize(),
            'exported_at' => (new \DateTimeImmutable('now'))->format(\DATE_ATOM),
        );

        $message = sprintf('Exported the record for account %d.', $customer->id);

        if ($this->boolParam($arguments, 'include_orders', true)) {
            $history = $this->history($customer->id);

            $document['orders'] = $history['orders'];
            $document['orders_total'] = $history['total'];
            $document['orders_included'] = count($history['orders']);
            $document['orders_truncated'] = $history['total'] > count($history['orders']);

            $message = sprintf(
                'Exported the record for account %d with %d of %d order(s).',
                $customer->id,
                count($history['orders']),
                $history['total']
            );
        }

        return $this->success($message, $document);
    }

    /**
     * Collect one account's order history, bounded by {@see self::MAX_ORDERS}.
     *
     * @param int $customerId The account.
     *
     * @return array{orders: list<array<string, mixed>>, total: int}
     */
    private function history(int $customerId): array
    {
        $orders = array();
        $total = 0;
        $page = 1;
        $maxPages = (int) ceil(self::MAX_ORDERS / self::PAGE_SIZE);

        while ($page <= $maxPages) {
            $collection = $this->orders->query(
                new OrderQuery(
                    customerId: $customerId,
                    page: $page,
                    perPage: self::PAGE_SIZE,
                )
            );

            if (1 === $page) {
                $total = $collection->total;
            }

            foreach ($collection->items as $order) {
                $orders[] = $order->jsonSerialize();
            }

            if (! $collection->hasMorePages()) {
                break;
            }

            ++$page;
        }

        return array(
            'orders' => array_slice($orders, 0, self::MAX_ORDERS),
            'total'  => $total,
        );
    }
}
