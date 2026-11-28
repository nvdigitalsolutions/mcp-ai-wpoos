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
use Nvoos\Core\Domain\Entity\ContentQuery;
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
     * The documents most countries require.
     *
     * WordPress kept two copies of this table — one in
     * `validate_document_checklist`, one in `generate_submission_pack` — so the
     * checklist and the pack could disagree about what a country requires. The
     * ports read one table.
     */
    public const REQUIRED_DOCUMENTS = array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula');

    /** Country-specific requirement tables, keyed by upper-cased country code. */
    public const COUNTRY_DOCUMENTS = array(
        'LK' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'iso'),
        'AE' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula'),
        'SA' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'cpsr', 'artwork', 'formula'),
        'QA' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula'),
        'KW' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula'),
        'OM' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula'),
        'IN' => array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula', 'stability'),
    );

    /**
     * The document types one country requires.
     *
     * @return list<string>
     */
    protected function requiredDocuments(string $country): array
    {
        $key = strtoupper(trim($country));

        return self::COUNTRY_DOCUMENTS[ $key ] ?? self::REQUIRED_DOCUMENTS;
    }

    /**
     * The comparison form of a document type.
     *
     * WordPress compared stored types against these lowercase slugs with
     * `array_diff`, which is case-sensitive, while the type is stored with the
     * caller's spelling. Section 164.152 records the consequence: a document
     * uploaded as LoA was reported missing while sitting in the store.
     */
    protected function foldDocType(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * The most rows one compliance read collects.
     *
     * WordPress asked for up to 1000 requirements or documents in a single
     * query; this ceiling is the same number, so a country that would have been
     * answered there is answered here.
     */
    protected const MAX_COLLECTED = 1000;

    /** The page size one collected query asks the store for. */
    protected const COLLECT_PAGE = 200;

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
     * Whole calendar days from today to a stored date, or null when it cannot
     * be read.
     *
     * WordPress computed `floor( ( strtotime( $date ) - time() ) / 86400 )`.
     * Two faults followed. An unreadable date became `false - time()`, roughly
     * minus twenty thousand days, so the record was reported as long expired.
     * And the arithmetic mixed a midnight with an instant: a document that
     * expired *yesterday* reported `-2` whenever the clock had passed midday,
     * so the day count drifted through the day and never matched the calendar a
     * compliance officer reads.
     *
     * The port compares two calendar dates in UTC. A record expiring today is
     * `0`, yesterday is `-1`, and the value is stable from midnight to midnight.
     * An unreadable date is null, and the caller decides what that means.
     */
    protected function daysUntil(string $date, ?int $now = null): ?int
    {
        $utc = new \DateTimeZone('UTC');

        $expiry = date_create_immutable($date, $utc);

        if (false === $expiry) {
            return null;
        }

        $today = date_create_immutable(gmdate('Y-m-d', $now ?? time()), $utc);

        if (false === $today) {
            return null;
        }

        return (int) $today->diff($expiry)->format('%r%a');
    }

    /**
     * The inclusive lower bound of a named look-back window.
     *
     * WordPress passed `'after' => '30 days ago'` (or `'1 month ago'`) into
     * `WP_Query`'s date query, which filters `post_date` — the record's creation
     * date, not its submission or approval date. The ports filter the same
     * field, so a caller asking for "last 30 days" gets registrations created in
     * the last 30 days, which is what the WordPress tool actually did.
     *
     * Month-based windows are anchored to the first day of the current month
     * rather than to today, because `-1 month` from the 31st lands in the wrong
     * month in PHP. A window that starts on the 3rd of a month is not a month.
     *
     * @param int $now Reference instant.
     *
     * @return string|null A `Y-m-d` lower bound, or null when the window is
     *                     open (the `all` range) or the name is unknown.
     */
    protected function rangeStart(string $range, int $now): ?string
    {
        $days = array(
            'last_30_days' => 30,
            'last_90_days' => 90,
        );

        if (isset($days[ $range ])) {
            return gmdate('Y-m-d', $now - ( $days[ $range ] * 86400 ));
        }

        $months = array(
            'last_month'   => 1,
            'last_quarter' => 3,
            'last_year'    => 12,
        );

        if (isset($months[ $range ])) {
            $utc   = new \DateTimeZone('UTC');
            $first = date_create_immutable(gmdate('Y-m-01', $now), $utc);

            if (false === $first) {
                return null;
            }

            return $first->modify('-' . $months[ $range ] . ' months')->format('Y-m-d');
        }

        return null;
    }

    /**
     * Whether a record's creation date falls inside an inclusive date window.
     *
     * @param string|null $start Inclusive `Y-m-d` lower bound, or null.
     * @param string|null $end   Inclusive `Y-m-d` upper bound, or null.
     */
    protected function withinRange(ContentItem $item, ?string $start, ?string $end): bool
    {
        $created = $item->createdAt->format('Y-m-d');

        if (null !== $start && $created < $start) {
            return false;
        }

        if (null !== $end && $created > $end) {
            return false;
        }

        return true;
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

    /**
     * Every item a query selects, page by page, up to a ceiling.
     *
     * WordPress asked for up to 1000 rows in one call. This runtime caps a
     * single page at 200, so the port pages rather than truncating: a country
     * with more than 200 requirements is answered completely up to the same
     * ceiling WordPress had, and the ceiling is the only limit either runtime
     * imposes.
     *
     * A store that cannot answer is treated as answering nothing rather than
     * failing the call, which is how the sibling readers already behave.
     *
     * @param list<string>             $types
     * @param array<int|string, mixed> $metaQuery
     *
     * @return list<ContentItem>
     */
    protected function collectContent(array $types, array $metaQuery = array()): array
    {
        $items = array();
        $page  = 1;

        while (count($items) < self::MAX_COLLECTED) {
            try {
                $result = $this->content->query(
                    new ContentQuery(
                        types: $types,
                        statuses: array('publish'),
                        metaQuery: $metaQuery,
                        orderBy: 'id',
                        order: 'ASC',
                        page: $page,
                        perPage: self::COLLECT_PAGE,
                    )
                );
            } catch (\Throwable $e) {
                break;
            }

            $fetched = 0;

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem) {
                    continue;
                }

                if (array() !== $types && ! in_array($item->type, $types, true)) {
                    continue;
                }

                $items[] = $item;
                ++$fetched;

                if (count($items) >= self::MAX_COLLECTED) {
                    break;
                }
            }

            if ($fetched < self::COLLECT_PAGE) {
                break;
            }

            ++$page;
        }

        return $items;
    }
}
