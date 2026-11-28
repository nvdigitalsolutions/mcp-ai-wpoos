<?php

/**
 * Shared ground for the regulatory-registration toolkit ports.
 *
 * The toolkit stores its entities as content items with unprefixed meta keys,
 * which is unusual enough to state plainly: a product's brand is the meta key
 * `brand`, not `_reg_product_brand`. The port keeps those names because an
 * export from WordPress has to read here and vice versa, and because the
 * toolkit's own report tools address them by name.
 *
 * ## Taxonomies are modelled, not flattened
 *
 * WordPress keeps a product's category in `mcp_ai_reg_category` and its brand in
 * `mcp_ai_reg_brand` *as well as* in the `brand` meta key. `ContentStoreInterface`
 * carries taxonomy input and term reads, so the port writes both: the term is
 * what a filter queries, and the meta is what a product row reports. A host that
 * cannot store terms still yields a usable row because `productRow()` falls back
 * to the meta value when the term list is empty.
 *
 * ## Two projections, on purpose
 *
 * `get_reg_product` returns every field, including the INCI ingredient list and
 * the allergen list. `list_reg_products` does not: those two fields are
 * kilobytes each, and a page of twenty rows should not carry forty ingredient
 * lists. WordPress drew the same line, and the port keeps it rather than
 * "improving" the list into a slower, heavier response.
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
use Nvoos\Core\Tool\AbstractTool;

abstract class RegulatoryTool extends AbstractTool
{
    /** The content type a regulatory product is stored as. */
    public const PRODUCT_TYPE = 'mcp_ai_reg_product';

    /** The content type a registration is stored as. */
    public const REGISTRATION_TYPE = 'mcp_ai_registration';

    /** The content type a registration document is stored as. */
    public const DOCUMENT_TYPE = 'mcp_ai_reg_document';

    /** The content type a country profile is stored as. */
    public const COUNTRY_TYPE = 'mcp_ai_reg_country';

    /** The content type a regulatory requirement is stored as. */
    public const REQUIREMENT_TYPE = 'mcp_ai_requirement';

    /** The product category taxonomy. */
    public const CATEGORY_TAX = 'mcp_ai_reg_category';

    /** The product brand taxonomy. */
    public const BRAND_TAX = 'mcp_ai_reg_brand';

    /** The registration status taxonomy. */
    public const STATUS_TAX = 'mcp_ai_reg_status';

    /** The document type taxonomy. */
    public const DOC_TYPE_TAX = 'mcp_ai_doc_type';

    /**
     * Product arguments and the meta keys they are stored under.
     *
     * Ordered as WordPress writes them, so a row built here reads in the same
     * order as one built there.
     *
     * @var array<string, string>
     */
    public const PRODUCT_FIELDS = array(
        'brand'              => 'brand',
        'supplier_reference' => 'supplier_reference',
        'item_group'         => 'item_group',
        'origin_country'     => 'origin_country',
        'manufacturer'       => 'manufacturer',
        'inci_ingredients'   => 'inci_ingredients',
        'allergens'          => 'allergens',
        'hs_code'            => 'hs_code',
        'barcode'            => 'barcode',
        'pack_size'          => 'pack_size',
        'variant'            => 'variant',
    );

    /** The product categories the toolkit accepts. */
    public const CATEGORIES = array('skincare', 'haircare', 'makeup', 'perfumes', 'cosmetics');

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * The full product row `get_reg_product` returns.
     *
     * The brand prefers the taxonomy term name when one is attached, which is
     * what WordPress did: the term is the canonical spelling after an operator
     * edits it, while the meta copy can be stale.
     *
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     *
     * @return array<string, mixed>
     */
    protected function productRow(ContentItem $item, array $meta = array(), array $terms = array()): array
    {
        $meta      = array() !== $meta ? $meta : $item->meta;
        $metaBrand = $this->text($meta['brand'] ?? null);

        $categories = $this->termNames($terms, self::CATEGORY_TAX);
        $brands     = $this->termNames($terms, self::BRAND_TAX);

        $row = array(
            'id'                 => $item->id,
            'name'               => $item->title,
            'description'        => $item->content,
            'brand'              => array() !== $brands ? $brands[0] : $metaBrand,
            'supplier_reference' => $this->text($meta['supplier_reference'] ?? null),
            'item_group'         => $this->text($meta['item_group'] ?? null),
            'origin_country'     => $this->text($meta['origin_country'] ?? null),
            'manufacturer'       => $this->text($meta['manufacturer'] ?? null),
            'inci_ingredients'   => $this->text($meta['inci_ingredients'] ?? null),
            'allergens'          => $this->text($meta['allergens'] ?? null),
            'hs_code'            => $this->text($meta['hs_code'] ?? null),
            'barcode'            => $this->text($meta['barcode'] ?? null),
            'pack_size'          => $this->text($meta['pack_size'] ?? null),
            'variant'            => $this->text($meta['variant'] ?? null),
            'created_date'       => $item->createdAt->format('Y-m-d H:i:s'),
            'modified_date'      => $item->updatedAt->format('Y-m-d H:i:s'),
        );

        if (array() !== $categories) {
            $row['category']   = $categories[0];
            $row['categories'] = $categories;
        }

        return $row;
    }

    /**
     * The lighter product row `list_reg_products` returns.
     *
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     *
     * @return array<string, mixed>
     */
    protected function productSummaryRow(ContentItem $item, array $meta = array(), array $terms = array()): array
    {
        $meta      = array() !== $meta ? $meta : $item->meta;
        $metaBrand = $this->text($meta['brand'] ?? null);

        $categories = $this->termNames($terms, self::CATEGORY_TAX);
        $brands     = $this->termNames($terms, self::BRAND_TAX);

        $row = array(
            'id'                 => $item->id,
            'name'               => $item->title,
            'description'        => $item->content,
            'brand'              => array() !== $brands ? $brands[0] : $metaBrand,
            'supplier_reference' => $this->text($meta['supplier_reference'] ?? null),
            'item_group'         => $this->text($meta['item_group'] ?? null),
            'origin_country'     => $this->text($meta['origin_country'] ?? null),
            'manufacturer'       => $this->text($meta['manufacturer'] ?? null),
            'hs_code'            => $this->text($meta['hs_code'] ?? null),
            'barcode'            => $this->text($meta['barcode'] ?? null),
            'pack_size'          => $this->text($meta['pack_size'] ?? null),
            'variant'            => $this->text($meta['variant'] ?? null),
        );

        if (array() !== $categories) {
            $row['category'] = $categories[0];
        }

        return $row;
    }

    /**
     * The term names attached to an item under one taxonomy.
     *
     * @param array<string, array<int, string>> $terms
     *
     * @return list<string>
     */
    protected function termNames(array $terms, string $taxonomy): array
    {
        $names = $terms[ $taxonomy ] ?? array();

        if (! is_array($names)) {
            return array();
        }

        $clean = array();

        foreach ($names as $name) {
            $value = $this->text($name);

            if ('' !== $value) {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    protected function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Today, in the form the toolkit stores dates.
     *
     * WordPress used `current_time( 'Y-m-d' )`, the site timezone. This runtime
     * has no site timezone, so the value is UTC; a host that needs local dates
     * passes them explicitly, which is why the tools accept the date argument.
     */
    protected function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * The current instant, in the form WordPress wrote `renewal_date`.
     */
    protected function nowStamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * The term name a status slug is stored under.
     *
     * WordPress created the term from the slug with `ucwords( $status, '_' )`
     * and then turned the underscores into spaces, so `pending_documents`
     * became `Pending Documents`. The default terms are seeded with those names,
     * which is why the name — not the slug — is the canonical spelling in the
     * store.
     */
    protected function statusName(string $slug): string
    {
        $words = str_replace('_', ' ', ucwords(strtolower(trim($slug)), '_'));

        return trim((string) preg_replace('/\s+/', ' ', $words));
    }

    /**
     * The canonical status key, from a slug, a display name or a hyphen form.
     *
     * `pending_documents`, `pending-documents` and `Pending Documents` all
     * resolve to `pending_documents`, which is the key the status list is
     * written in and the input `statusName()` expects. One vocabulary means a
     * caller cannot create a second term by spelling it differently.
     */
    protected function statusKey(string $value): string
    {
        $key = strtolower(trim($value));
        $key = (string) preg_replace('/[\s\-]+/', '_', $key);

        return trim($key, '_');
    }

    /**
     * The full registration row `get_registration` returns.
     *
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     *
     * @return array<string, mixed>
     */
    protected function registrationRow(ContentItem $item, array $meta = array(), array $terms = array()): array
    {
        $meta = array() !== $meta ? $meta : $item->meta;

        $row = array(
            'id'                => $item->id,
            'title'             => $item->title,
            'notes'             => $item->content,
            'product_id'        => $this->intValue($meta['product_id'] ?? null),
            'country'           => $this->text($meta['country'] ?? null),
            'authority'         => $this->text($meta['authority'] ?? null),
            'registration_type' => $this->text($meta['registration_type'] ?? null),
            'cos_number'        => $this->text($meta['cos_number'] ?? null),
            'submission_date'   => $this->text($meta['submission_date'] ?? null),
            'approval_date'     => $this->text($meta['approval_date'] ?? null),
            'expiry_date'       => $this->text($meta['expiry_date'] ?? null),
            'created_date'      => $item->createdAt->format('Y-m-d H:i:s'),
            'modified_date'     => $item->updatedAt->format('Y-m-d H:i:s'),
        );

        $statuses = $this->termNames($terms, self::STATUS_TAX);

        if (array() !== $statuses) {
            $row['status'] = $statuses[0];
        }

        return $row;
    }

    /**
     * The lighter registration row `list_registrations` returns.
     *
     * It carries no notes and no modified date, and gains `product_name` when
     * the product still exists — the same trade the product listing makes.
     *
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     *
     * @return array<string, mixed>
     */
    protected function registrationSummaryRow(ContentItem $item, array $meta = array(), array $terms = array()): array
    {
        $meta = array() !== $meta ? $meta : $item->meta;

        $row = array(
            'id'                => $item->id,
            'title'             => $item->title,
            'product_id'        => $this->intValue($meta['product_id'] ?? null),
            'country'           => $this->text($meta['country'] ?? null),
            'authority'         => $this->text($meta['authority'] ?? null),
            'registration_type' => $this->text($meta['registration_type'] ?? null),
            'cos_number'        => $this->text($meta['cos_number'] ?? null),
            'submission_date'   => $this->text($meta['submission_date'] ?? null),
            'approval_date'     => $this->text($meta['approval_date'] ?? null),
            'expiry_date'       => $this->text($meta['expiry_date'] ?? null),
            'created_date'      => $item->createdAt->format('Y-m-d H:i:s'),
        );

        $statuses = $this->termNames($terms, self::STATUS_TAX);

        if (array() !== $statuses) {
            $row['status'] = $statuses[0];
        }

        return $row;
    }

    /**
     * An id from untyped meta or arguments, or 0.
     *
     * The scalar check matters: `(int) array()` is 1, so a malformed meta value
     * would otherwise point at record 1.
     */
    protected function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
