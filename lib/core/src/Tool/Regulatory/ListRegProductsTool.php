<?php

/**
 * List regulatory products — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_List_Reg_Products` (Proposal 164 section 164.144):
 * the same four filters, the same default and maximum page size, the same
 * payload keys, and alphabetical by name.
 *
 * ## The filters keep WordPress's semantics
 *
 * - `category` matches a `mcp_ai_reg_category` term **slug**, lower-cased
 *   before the lookup, because that is what the WordPress query did.
 * - `brand` matches a `mcp_ai_reg_brand` term **name**.
 * - `origin_country` is an **exact** meta match.
 * - `search` is free text, which `WP_Query` applies across title and content;
 *   the store contract says the same, so it is passed through.
 * - Everything is ANDed.
 *
 * ## `offset` must land on a page boundary
 *
 * WordPress paginated with `limit` + `offset`. The store contract paginates
 * with `page` + `perPage`, and a query cannot start mid-page. Rather than round
 * an arbitrary offset down and silently return the wrong window, the tool
 * accepts the offsets WordPress itself produced — a multiple of `limit` — and
 * refuses the rest with a message that names the rule. A caller that wants row
 * 5 of a 20-row page is asking for a window this contract cannot address, and
 * an error is a better answer than a shifted page.
 *
 * `total` is the whole matching set, not the page, and `has_more` is computed
 * from it so a caller can page without a second count.
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

class ListRegProductsTool extends RegulatoryTool
{
    public const MAX_PER_PAGE = 100;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_reg_products';
    }

    public function getName(): string
    {
        return 'List Regulatory Products';
    }

    public function getDescription(): string
    {
        return 'Lists regulatory products alphabetically, filtered by category, brand, origin country or search term, with limit and offset pagination. Filters are ANDed.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress gates this read on `read`, not `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'category'       => array(
                    'type'        => 'string',
                    'description' => 'Filter by category.',
                    'enum'        => self::CATEGORIES,
                ),
                'brand'          => array(
                    'type'        => 'string',
                    'description' => 'Filter by brand name.',
                    'maxLength'   => 100,
                ),
                'origin_country' => array(
                    'type'        => 'string',
                    'description' => 'Filter by country of origin.',
                    'maxLength'   => 100,
                ),
                'search'         => array(
                    'type'        => 'string',
                    'description' => 'Search term matched against product name and description.',
                    'maxLength'   => 200,
                ),
                'limit'          => array(
                    'type'        => 'integer',
                    'description' => 'Products per page. Default 20, maximum 100.',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                ),
                'offset'         => array(
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

        $search = trim($this->stringParam($arguments, 'search'));
        $tax    = array();

        $category = strtolower(trim($this->stringParam($arguments, 'category')));

        if ('' !== $category) {
            $tax[] = array('taxonomy' => self::CATEGORY_TAX, 'field' => 'slug', 'terms' => $category);
        }

        $brand = trim($this->stringParam($arguments, 'brand'));

        if ('' !== $brand) {
            $tax[] = array('taxonomy' => self::BRAND_TAX, 'field' => 'name', 'terms' => $brand);
        }

        $meta = array();
        $originCountry = trim($this->stringParam($arguments, 'origin_country'));

        if ('' !== $originCountry) {
            $meta[] = array('key' => 'origin_country', 'value' => $originCountry, 'compare' => '=');
        }

        try {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::PRODUCT_TYPE),
                    statuses: array('publish'),
                    search: '' !== $search ? $search : null,
                    metaQuery: $meta,
                    taxQuery: $tax,
                    orderBy: 'title',
                    order: 'ASC',
                    page: intdiv($offset, $limit) + 1,
                    perPage: $limit,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('list_reg_products_failed', $e->getMessage());
        }

        $products = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::PRODUCT_TYPE === $item->type) {
                $products[] = $this->productSummaryRow($item, $item->meta, $this->terms($item));
            }
        }

        return $this->success(
            sprintf('Found %d products matching your criteria.', $result->total),
            array(
                'products' => $products,
                'total'    => $result->total,
                'returned' => count($products),
                'limit'    => $limit,
                'offset'   => $offset,
                'has_more' => ( $offset + $limit ) < $result->total,
            )
        );
    }

    /**
     * The terms attached to one item, read defensively.
     *
     * A store that does not implement term reads is a store where the meta
     * `brand` fallback is the whole answer, so a failure here is not an error
     * for this tool.
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
