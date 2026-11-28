<?php

/**
 * Search regulatory products — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Search_Reg_Products` (Proposal 164 section 164.145).
 * Where `list_reg_products` browses a catalogue, this one matches inside it:
 * seven filters, every one of them ANDed, and a row trimmed for scanning rather
 * than reading.
 *
 * ## Match semantics, one by one
 *
 * - `search` is free text across title and content, as `WP_Query` applies `s`.
 * - `manufacturer` and `ingredients` are **substring** matches (`LIKE`), which
 *   is how WordPress wrote them.
 * - `origin_country` and `hs_code` are **exact**: a code is an identifier, not
 *   a phrase.
 * - `category` and `brand` match taxonomy terms by name.
 *
 * ## One correction: the category vocabulary
 *
 * WordPress matched the category by term **name** here while `create` stored a
 * lower-cased **slug** and `list` filtered by slug. The default term is named
 * `Skincare` and the slug is `skincare`, so a search for "skincare" — the value
 * the schema itself documents — matched nothing. The port uses the same slug
 * vocabulary as create and list, so one spelling finds the record wherever it
 * was written.
 *
 * ## The row is deliberately short
 *
 * The description is trimmed to 30 words with an ellipsis. WordPress did the
 * same through `wp_trim_words`, but emitted the HTML entity `&hellip;` into a
 * JSON envelope; the port emits the character, which is the same thing without
 * the escaping artefact. The remaining fields are the identity and origin ones
 * a search result needs to be actionable; the full row comes from
 * `get_reg_product`.
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

class SearchRegProductsTool extends RegulatoryTool
{
    public const MAX_PER_PAGE = 100;

    /** How many words of a description the search row carries. */
    private const DESCRIPTION_WORDS = 30;

    /** Filters matched as substrings, mapped to their meta keys. */
    private const LIKE_FILTERS = array(
        'manufacturer' => 'manufacturer',
        'ingredients'  => 'inci_ingredients',
    );

    /** Filters matched exactly, mapped to their meta keys. */
    private const EXACT_FILTERS = array(
        'origin_country' => 'origin_country',
        'hs_code'        => 'hs_code',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'search_reg_products';
    }

    public function getName(): string
    {
        return 'Search Regulatory Products';
    }

    public function getDescription(): string
    {
        return 'Searches regulatory products by free text, brand, category, manufacturer, origin country, HS code or INCI ingredients, with page and per_page pagination. All filters are ANDed.';
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
                'search'         => array(
                    'type'        => 'string',
                    'description' => 'Search term matched against product name and description.',
                    'maxLength'   => 200,
                ),
                'brand'          => array(
                    'type'        => 'string',
                    'description' => 'Filter by brand name.',
                    'maxLength'   => 100,
                ),
                'category'       => array(
                    'type'        => 'string',
                    'description' => 'Filter by category.',
                    'enum'        => self::CATEGORIES,
                ),
                'manufacturer'   => array(
                    'type'        => 'string',
                    'description' => 'Manufacturer substring to match.',
                    'maxLength'   => 200,
                ),
                'origin_country' => array(
                    'type'        => 'string',
                    'description' => 'Exact country of origin.',
                    'maxLength'   => 100,
                ),
                'hs_code'        => array(
                    'type'        => 'string',
                    'description' => 'Exact HS code.',
                    'maxLength'   => 50,
                ),
                'ingredients'    => array(
                    'type'        => 'string',
                    'description' => 'Substring to match inside the INCI ingredient list.',
                    'maxLength'   => 200,
                ),
                'page'           => array(
                    'type'        => 'integer',
                    'description' => 'Page number, 1-based. Default 1.',
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'       => array(
                    'type'        => 'integer',
                    'description' => 'Results per page. Default 20, maximum 100.',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $page    = max($this->intParam($arguments, 'page', 1), 1);
        $perPage = min(max($this->intParam($arguments, 'per_page', 20), 1), self::MAX_PER_PAGE);
        $search  = trim($this->stringParam($arguments, 'search'));

        $meta = array();

        foreach (self::LIKE_FILTERS as $argument => $key) {
            $value = trim($this->stringParam($arguments, $argument));

            if ('' !== $value) {
                $meta[] = array('key' => $key, 'value' => $value, 'compare' => 'LIKE');
            }
        }

        foreach (self::EXACT_FILTERS as $argument => $key) {
            $value = trim($this->stringParam($arguments, $argument));

            if ('' !== $value) {
                $meta[] = array('key' => $key, 'value' => $value, 'compare' => '=');
            }
        }

        $tax = array();
        $category = strtolower(trim($this->stringParam($arguments, 'category')));

        if ('' !== $category) {
            $tax[] = array('taxonomy' => self::CATEGORY_TAX, 'field' => 'slug', 'terms' => $category);
        }

        $brand = trim($this->stringParam($arguments, 'brand'));

        if ('' !== $brand) {
            $tax[] = array('taxonomy' => self::BRAND_TAX, 'field' => 'name', 'terms' => $brand);
        }

        try {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::PRODUCT_TYPE),
                    statuses: array('publish'),
                    search: '' !== $search ? $search : null,
                    metaQuery: $meta,
                    taxQuery: $tax,
                    orderBy: 'date',
                    order: 'DESC',
                    page: $page,
                    perPage: $perPage,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('search_reg_products_failed', $e->getMessage());
        }

        $products = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::PRODUCT_TYPE === $item->type) {
                $products[] = $this->searchRow($item);
            }
        }

        return $this->success(
            sprintf('Found %d products matching your criteria.', $result->total),
            array(
                'products'    => $products,
                'total'       => $result->total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $result->totalPages,
            )
        );
    }

    /**
     * The short row a search result carries.
     *
     * @return array<string, mixed>
     */
    private function searchRow(ContentItem $item): array
    {
        $meta = $item->meta;

        $row = array(
            'id'                 => $item->id,
            'name'               => $item->title,
            'description'        => $this->trimWords($item->content, self::DESCRIPTION_WORDS),
            'brand'              => $this->text($meta['brand'] ?? null),
            'manufacturer'       => $this->text($meta['manufacturer'] ?? null),
            'origin_country'     => $this->text($meta['origin_country'] ?? null),
            'hs_code'            => $this->text($meta['hs_code'] ?? null),
            'supplier_reference' => $this->text($meta['supplier_reference'] ?? null),
        );

        $categories = $this->termNames($this->terms($item), self::CATEGORY_TAX);

        if (array() !== $categories) {
            $row['category'] = $categories[0];
        }

        $brands = $this->termNames($this->terms($item), self::BRAND_TAX);

        if (array() !== $brands) {
            $row['brand'] = $brands[0];
        }

        return $row;
    }

    /**
     * The first N words of a value, with an ellipsis when it was longer.
     *
     * A stand-in for `wp_trim_words()` that emits a character rather than the
     * `&hellip;` entity, because this string goes into a JSON envelope.
     */
    private function trimWords(string $html, int $words): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        if ('' === $text) {
            return '';
        }

        $parts = explode(' ', $text);

        if (count($parts) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($parts, 0, $words)) . '…';
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
