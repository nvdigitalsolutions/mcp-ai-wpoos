<?php

/**
 * List registrations — `mcp_ai_registration`.
 *
 * Port of `WP_MCP_AI_Tool_List_Registrations` (Proposal 164 section 164.146):
 * the same five filters, newest first, the same payload keys, and the same
 * 20/100 page bounds.
 *
 * ## The filters
 *
 * - `country`, `product_id` and `registration_type` are **exact** meta matches.
 * - `status` is addressed by its slug form — the schema documents
 *   `pending_documents` — and resolved to the term name the store writes, so the
 *   documented value is the one that works.
 * - `expiring_within_days` keeps rows whose `expiry_date` is on or before the
 *   cut-off. A registration without an expiry date is not "expiring", so it is
 *   excluded, which is what the WordPress meta clause did.
 * - Everything is ANDed.
 *
 * ## `offset` must land on a page boundary
 *
 * The same rule as `list_reg_products`, for the same reason: the store
 * paginates by page and a query cannot start mid-page. Rather than round the
 * offset down and return a shifted window, the tool accepts the offsets
 * WordPress produced — multiples of `limit` — and names the rule otherwise.
 *
 * `product_name` is resolved per row, because a registration's title is its own
 * and an operator scanning a list needs the product it belongs to. WordPress
 * did the same lookup; a product that no longer exists simply leaves the key
 * off, which is also what WordPress did.
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
use Nvoos\Core\Domain\Entity\ContentQuery;

class ListRegistrationsTool extends RegulatoryTool
{
    public const MAX_PER_PAGE = 100;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_registrations';
    }

    public function getName(): string
    {
        return 'List Registrations';
    }

    public function getDescription(): string
    {
        return 'Lists registrations newest first, filtered by country, status, product, registration type or an expiry window, with limit and offset pagination. Filters are ANDed.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'country'              => array(
                    'type'        => 'string',
                    'description' => 'Exact country name.',
                    'maxLength'   => 100,
                ),
                'status'               => array(
                    'type'        => 'string',
                    'description' => 'Status in slug form, e.g. pending_documents.',
                    'enum'        => CreateRegistrationTool::STATUSES,
                ),
                'product_id'           => array(
                    'type'        => 'integer',
                    'description' => 'Exact product id.',
                    'minimum'     => 1,
                ),
                'expiring_within_days' => array(
                    'type'        => 'integer',
                    'description' => 'Only registrations expiring within this many days.',
                    'minimum'     => 1,
                    'maximum'     => 365,
                ),
                'registration_type'    => array(
                    'type'        => 'string',
                    'description' => 'Registration type.',
                    'enum'        => CreateRegistrationTool::TYPES,
                ),
                'limit'                => array(
                    'type'        => 'integer',
                    'description' => 'Registrations per page. Default 20, maximum 100.',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                ),
                'offset'               => array(
                    'type'        => 'integer',
                    'description' => 'Rows to skip. Must be a multiple of limit; default 0.',
                    'default'     => 0,
                    'minimum'     => 0,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $limit  = min(max($this->intParam($arguments, 'limit', 20), 1), self::MAX_PER_PAGE);
        $offset = max($this->intParam($arguments, 'offset', 0), 0);

        if (0 !== $offset % $limit) {
            return $this->errors->validationFailed(
                'The offset is not on a page boundary.',
                array('offset' => array('Use a multiple of limit, or set offset to 0 and page with limit.'))
            );
        }

        $meta = array();

        $country = trim($this->stringParam($arguments, 'country'));

        if ('' !== $country) {
            $meta[] = array('key' => 'country', 'value' => $country, 'compare' => '=');
        }

        $productId = max(0, $this->intParam($arguments, 'product_id'));

        if ($productId > 0) {
            $meta[] = array('key' => 'product_id', 'value' => $productId, 'compare' => '=');
        }

        $type = trim($this->stringParam($arguments, 'registration_type'));

        if ('' !== $type) {
            $meta[] = array('key' => 'registration_type', 'value' => $type, 'compare' => '=');
        }

        $days = $this->intParam($arguments, 'expiring_within_days');

        if ($days > 0) {
            $meta[] = array(
                'key'     => 'expiry_date',
                'value'   => gmdate('Y-m-d', time() + ( $days * 86400 )),
                'compare' => '<=',
                'type'    => 'DATE',
            );
        }

        $tax    = array();
        $status = trim($this->stringParam($arguments, 'status'));

        if ('' !== $status) {
            $tax[] = array(
                'taxonomy' => self::STATUS_TAX,
                'field'    => 'name',
                'terms'    => $this->statusName($status),
            );
        }

        try {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::REGISTRATION_TYPE),
                    statuses: array('publish'),
                    metaQuery: $meta,
                    taxQuery: $tax,
                    orderBy: 'date',
                    order: 'DESC',
                    page: intdiv($offset, $limit) + 1,
                    perPage: $limit,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('list_registrations_failed', $e->getMessage());
        }

        $registrations = array();

        foreach ($result->items as $item) {
            if (! $item instanceof ContentItem || self::REGISTRATION_TYPE !== $item->type) {
                continue;
            }

            $row = $this->registrationSummaryRow($item, $item->meta, $this->terms($item));

            if ($row['product_id'] > 0) {
                $product = $this->content->find($row['product_id']);

                if ($product instanceof ContentItem && self::PRODUCT_TYPE === $product->type) {
                    $row['product_name'] = $product->title;
                }
            }

            $registrations[] = $row;
        }

        return $this->success(
            sprintf('Found %d registrations matching your criteria.', $result->total),
            array(
                'registrations' => $registrations,
                'total'         => $result->total,
                'returned'      => count($registrations),
                'limit'         => $limit,
                'offset'        => $offset,
                'has_more'      => ( $offset + $limit ) < $result->total,
            )
        );
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * @return array<string, array<int, string>>
     */
    private function terms(ContentItem $item): array
    {
        try {
            return $this->content->getTaxonomyTerms($item->id);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
