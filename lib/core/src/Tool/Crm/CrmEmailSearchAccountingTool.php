<?php

/**
 * Email-driven accounting and service search over the toolkit contact store.
 *
 * Ported from `WP_MCP_AI_Tool_CRM_Email_Search_Accounting`. It searches
 * `mcp_crm_contacts` on the accounting meta family (transaction type, billing
 * status, service category, invoice amount / due date / currency / number),
 * supports a fiscal-year/quarter window, computes days overdue, and returns a
 * per-page financial summary.
 *
 * ## The accounting family has no writers
 *
 * A scoped grep across `addons/pro/includes/tools/crm/` finds **zero writers**
 * of `accounting_transaction_type`, `billing_status`, `service_category`,
 * `invoice_amount`, `invoice_due_date`, `invoice_currency` or
 * `invoice_number`. `manage_crm_contact` does not declare them.
 *
 * Unlike the lead search, this tool's **defaults are benign**: every accounting
 * filter defaults to `all` and the sort defaults to `date`, so a default call
 * returns contacts with empty accounting fields rather than nothing. Passing a
 * non-`all` transaction/billing/service filter on a manager-written store
 * returns zero rows — the filters are only useful on data an importer or a
 * host application supplied.
 *
 * ## "Fiscal" quarters are calendar quarters
 *
 * The schema says `Q1 = Jan-Mar, …`, and `fiscal_quarter_dates()` hardcodes
 * those boundaries. A fiscal quarter is defined by the company's fiscal-year
 * start, so for a July–June business this labels Q1 as January–March. Pinned
 * as-is; the docblock of the port says so too.
 *
 * ## Three more pinned behaviours
 *
 * 1. **`days_overdue` sorts the page, not the set.** WordPress paginates in SQL
 *    and only then `usort`s the returned page, so page 2 is sorted but page 1
 *    is not ordered before it. The port keeps that shape on both paths.
 * 2. **An unparsable due date is not "overdue since 1970".** WordPress coerces
 *    `strtotime()`'s `false` to `0` and reports ~20,000 overdue days. The port
 *    returns `null` instead — a documented deviation, because a bad date is not
 *    an overdue invoice.
 * 3. **`get_cached` is an error on a miss.** Unlike the lead search, which
 *    auto-falls back, this tool returns `not_found` with an empty
 *    `records` / `total` payload. Faithful, and a test pins it.
 *
 * The default currency is the CRM toolkit setting through `crmSettings()`; the
 * WordPress filter (`wp_mcp_ai_crm_default_currency`) has no core equivalent.
 * Audit metadata reports "No accounting integration detected" because the core
 * cannot know which accounting platform a host has installed.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class CrmEmailSearchAccountingTool extends CrmEmailSearchTool
{
    public const CACHE_KEY_PREFIX = 'crm_accounting_search_';

    public const TRANSACTION_TYPES = array('invoice', 'payment', 'quote', 'reminder', 'dispute', 'statement', 'contract', 'all');

    public const BILLING_STATUSES = array('pending', 'overdue', 'paid', 'disputed', 'voided', 'refunded', 'all');

    public const SERVICE_CATEGORIES = array('accounting', 'bookkeeping', 'tax', 'payroll', 'audit', 'advisory', 'consulting', 'all');

    public const CRON_SCHEDULES = array('hourly', 'twicedaily', 'daily');

    public const FISCAL_QUARTERS = array('Q1', 'Q2', 'Q3', 'Q4', 'all');

    public const ORDERBY_OPTIONS = array('relevance', 'invoice_amount', 'days_overdue', 'date', 'name', 'company');

    /** Statuses that close a record, so no overdue count applies. */
    public const CLOSED_STATUSES = array('paid', 'voided', 'refunded');

    /** The in-memory sort/rank window, as WordPress uses. */
    public const MAX_RANKED = 500;

    /** Rows per store page while filling that window. */
    private const PAGE_CHUNK = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
        ?CacheStoreInterface $cache = null,
    ) {
        parent::__construct($e, $content, $settings, $cache);
    }

    public function getSlug(): string
    {
        return 'crm_email_search_accounting';
    }

    public function getName(): string
    {
        return 'CRM Email Search: Accounting & Service Tracking';
    }

    public function getDescription(): string
    {
        return 'Search CRM contacts for accounting and service-tracking emails with TF-IDF free-text relevance scoring. Supports transaction types, billing-status filtering, invoice-amount ranges, service categories, compliance audit metadata, fiscal-year/quarter windows, and sort by relevance, invoice_amount, days_overdue, date, name, or company. Results are cached for efficient throughout-the-day querying.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    protected function cachePrefix(): string
    {
        return self::CACHE_KEY_PREFIX;
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'action'             => array(
                    'type'        => 'string',
                    'enum'        => array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule'),
                    'description' => 'search executes and caches; get_cached returns cached results or a not-found error; clear_cache invalidates; schedule/unschedule require a host cron service this runtime does not carry.',
                    'default'     => 'search',
                ),
                'transaction_type'   => array(
                    'type'        => 'string',
                    'enum'        => self::TRANSACTION_TYPES,
                    'description' => 'Accounting transaction type: invoice, payment, quote, reminder, dispute, statement, contract.',
                    'default'     => 'all',
                ),
                'billing_status'     => array(
                    'type'        => 'string',
                    'enum'        => self::BILLING_STATUSES,
                    'description' => 'Billing/payment status: pending, overdue, paid, disputed, voided, refunded.',
                    'default'     => 'all',
                ),
                'service_category'   => array(
                    'type'        => 'string',
                    'enum'        => self::SERVICE_CATEGORIES,
                    'description' => 'Professional service category.',
                    'default'     => 'all',
                ),
                'invoice_amount_min' => array(
                    'type'        => 'number',
                    'description' => 'Minimum transaction amount (inclusive).',
                    'minimum'     => 0,
                ),
                'invoice_amount_max' => array(
                    'type'        => 'number',
                    'description' => 'Maximum transaction amount (inclusive).',
                    'minimum'     => 0,
                ),
                'days_overdue_min'   => array(
                    'type'        => 'integer',
                    'description' => 'Only return records overdue by at least this many days.',
                    'minimum'     => 0,
                ),
                'email_domain'       => array(
                    'type'        => 'string',
                    'description' => 'Filter by contact email domain (e.g. "clientcorp.com").',
                ),
                'date_from'          => array(
                    'type'        => 'string',
                    'description' => 'Include transactions issued on or after this date (YYYY-MM-DD).',
                ),
                'date_to'            => array(
                    'type'        => 'string',
                    'description' => 'Include transactions issued on or before this date (YYYY-MM-DD).',
                ),
                'include_audit_meta' => array(
                    'type'        => 'boolean',
                    'description' => 'Include compliance audit metadata in each result.',
                    'default'     => false,
                ),
                'per_page'           => array(
                    'type'        => 'integer',
                    'description' => 'Results per page (1–100).',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 20,
                ),
                'page'               => array(
                    'type'        => 'integer',
                    'description' => 'Page number.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'cache_ttl'          => array(
                    'type'        => 'integer',
                    'description' => 'Cache lifetime in seconds (minimum 60, default 3600).',
                    'minimum'     => 60,
                    'default'     => self::DEFAULT_CACHE_TTL,
                ),
                'schedule'           => array(
                    'type'        => 'string',
                    'enum'        => self::CRON_SCHEDULES,
                    'description' => 'Recurrence for automatic refresh. Used with action=schedule, which requires a host cron service.',
                    'default'     => 'hourly',
                ),
                'force_refresh'      => array(
                    'type'        => 'boolean',
                    'description' => 'When true, bypass any cached results and execute a fresh query.',
                    'default'     => false,
                ),
                'fiscal_year'        => array(
                    'type'        => 'integer',
                    'description' => 'Filter by fiscal year (e.g. 2025). Filters by the contact record date.',
                    'minimum'     => 2000,
                    'maximum'     => 2100,
                ),
                'fiscal_quarter'     => array(
                    'type'        => 'string',
                    'enum'        => self::FISCAL_QUARTERS,
                    'description' => 'Filter by quarter within the fiscal_year. Implemented as calendar quarters: Q1 = Jan-Mar, Q2 = Apr-Jun, Q3 = Jul-Sep, Q4 = Oct-Dec.',
                    'default'     => 'all',
                ),
                'currency_code'      => array(
                    'type'        => 'string',
                    'description' => 'Filter by ISO 4217 currency code (e.g. "USD", "EUR").',
                ),
                'search'             => array(
                    'type'        => 'string',
                    'description' => 'Free-text search across contact name, company, and email, ranked when orderby is relevance.',
                ),
                'orderby'            => array(
                    'type'        => 'string',
                    'enum'        => self::ORDERBY_OPTIONS,
                    'description' => 'Sort field. days_overdue orders the returned page, as WordPress does.',
                    'default'     => 'date',
                ),
                'order'              => array(
                    'type'        => 'string',
                    'enum'        => array('ASC', 'DESC'),
                    'description' => 'Sort direction.',
                    'default'     => 'DESC',
                ),
            ),
            'required'             => array('action'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $action = $this->key($this->stringParam($arguments, 'action', 'search'));

        switch ($action) {
            case 'search':
                return $this->runSearch($arguments);

            case 'get_cached':
                return $this->getCached($arguments);

            case 'clear_cache':
                return $this->clearCached($arguments);

            case 'schedule':
            case 'unschedule':
                return $this->scheduleUnavailable();

            default:
                return $this->errors->validationFailed(
                    'Invalid action. Must be one of: search, get_cached, clear_cache, schedule, unschedule.',
                    array('action' => array('Use one of the declared actions.')),
                );
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runSearch(array $arguments): mixed
    {
        $filters  = $this->extractFilters($arguments);
        $cacheKey = $this->cacheKey($filters);
        $ttl      = max(60, $this->intParam($arguments, 'cache_ttl', self::DEFAULT_CACHE_TTL));
        $force    = ! empty($arguments['force_refresh']);

        if (! $force) {
            $cached = $this->cacheGet($cacheKey);

            if (is_array($cached)) {
                $cached['from_cache']    = true;
                $cached['force_refresh'] = false;

                return $this->success(
                    sprintf('Found %d accounting records (cached).', (int) ( $cached['total'] ?? 0 )),
                    $cached
                );
            }
        }

        $data = $this->queryAccounting($filters);
        $this->cacheSet($cacheKey, $data, $ttl);

        $data['cached']        = $this->hasCache();
        $data['cache_ttl']     = $ttl;
        $data['cached_at']     = $this->nowString();
        $data['cache_key']     = $cacheKey;
        $data['force_refresh'] = $force;

        return $this->success(sprintf('Found %d accounting records.', (int) $data['total']), $data);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getCached(array $arguments): mixed
    {
        $cached = $this->cacheGet($this->cacheKey($this->extractFilters($arguments)));

        if (! is_array($cached)) {
            // Pinned: this tool does not auto-fall back, unlike the lead search.
            return $this->errors->notFound(
                'No cached results found. Run action=search first to populate the cache.',
                array('records' => array(), 'total' => 0)
            );
        }

        $cached['from_cache'] = true;

        return $this->success(
            sprintf('Found %d accounting records (cached).', (int) ( $cached['total'] ?? 0 )),
            $cached
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function clearCached(array $arguments): mixed
    {
        $this->cacheDelete($this->cacheKey($this->extractFilters($arguments)));

        return $this->success('Accounting search cache cleared successfully.', array());
    }

    /**
     * The filter set, sanitised and defaulted the way WordPress does.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function extractFilters(array $arguments): array
    {
        $quarter = strtoupper($this->text($this->stringParam($arguments, 'fiscal_quarter', 'all')));

        if (! in_array($quarter, self::FISCAL_QUARTERS, true)) {
            $quarter = 'all';
        }

        $order = strtoupper($this->text($this->stringParam($arguments, 'order', 'DESC')));

        $filters = array(
            'transaction_type'   => $this->enumParam($arguments, 'transaction_type', self::TRANSACTION_TYPES, 'all'),
            'billing_status'     => $this->enumParam($arguments, 'billing_status', self::BILLING_STATUSES, 'all'),
            'service_category'   => $this->enumParam($arguments, 'service_category', self::SERVICE_CATEGORIES, 'all'),
            'fiscal_year'        => isset($arguments['fiscal_year']) ? abs($this->intValue($arguments['fiscal_year'])) : 0,
            'fiscal_quarter'     => $quarter,
            'currency_code'      => strtoupper($this->text($this->stringParam($arguments, 'currency_code'))),
            'email_domain'       => $this->text($this->stringParam($arguments, 'email_domain')),
            'date_from'          => $this->text($this->stringParam($arguments, 'date_from')),
            'date_to'            => $this->text($this->stringParam($arguments, 'date_to')),
            'include_audit_meta' => isset($arguments['include_audit_meta']) && (bool) $arguments['include_audit_meta'],
            'per_page'           => $this->intParam($arguments, 'per_page', 20),
            'page'               => $this->intParam($arguments, 'page', 1),
            'search'             => $this->text($this->stringParam($arguments, 'search')),
            'orderby'            => $this->orderbyParam($this->stringParam($arguments, 'orderby', 'date'), self::ORDERBY_OPTIONS, 'date'),
            'order'              => in_array($order, array('ASC', 'DESC'), true) ? $order : 'DESC',
        );

        if (isset($arguments['invoice_amount_min'])) {
            $filters['invoice_amount_min'] = max(0.0, $this->floatParam($arguments, 'invoice_amount_min'));
        }

        if (isset($arguments['invoice_amount_max'])) {
            $filters['invoice_amount_max'] = max(0.0, $this->floatParam($arguments, 'invoice_amount_max'));
        }

        if (isset($arguments['days_overdue_min'])) {
            $filters['days_overdue_min'] = abs($this->intValue($arguments['days_overdue_min']));
        }

        return $filters;
    }

    /**
     * The query result, before the response envelope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function queryAccounting(array $filters): array
    {
        $perPage = min(max((int) $filters['per_page'], 1), 100);
        $page    = max((int) $filters['page'], 1);
        $orderby = (string) $filters['orderby'];
        $order   = (string) $filters['order'];
        $search  = (string) $filters['search'];
        $audit   = (bool) $filters['include_audit_meta'];
        $meta    = $this->metaClauses($filters);

        $isRelevance = 'relevance' === $orderby && '' !== $search;

        list($from, $to) = $this->dateWindow($filters);
        $hasDate         = '' !== $from || '' !== $to;

        $inMemory = $isRelevance || in_array($orderby, array('invoice_amount', 'company'), true) || $hasDate;

        if (! $inMemory) {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    metaQuery: $meta,
                    orderBy: 'name' === $orderby ? 'title' : 'date',
                    order: $order,
                    page: $page,
                    perPage: $perPage,
                )
            );

            $records = array();

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
                    continue;
                }

                $row = $this->project($item, $audit);

                if ($this->rowPasses($row, $filters)) {
                    $records[] = $row;
                }
            }

            // Pinned: WordPress sorts the returned page only.
            if ('days_overdue' === $orderby) {
                $records = $this->sortByDaysOverdue($records, $order);
            }

            return $this->payload($records, $result->total, $perPage, $page, $result->totalPages, $filters);
        }

        $items   = $this->fetchCapped($meta, $orderby, $order, self::MAX_RANKED);
        $records = array();

        foreach ($items as $item) {
            if (! $this->withinDates($item, $from, $to)) {
                continue;
            }

            $row = $this->project($item, $audit);

            if ($this->rowPasses($row, $filters)) {
                $records[] = $row;
            }
        }

        if (in_array($orderby, array('invoice_amount', 'company'), true)) {
            // WordPress sorts by meta_key with an INNER JOIN: rows without the
            // key are not in the result set at all.
            $records = array_values(array_filter(
                $records,
                static fn (array $row): bool => null !== $row[ $orderby ] && '' !== (string) $row[ $orderby ]
            ));

            $records = $this->sortRowsByField($records, $orderby, $order, 'invoice_amount' === $orderby);
        } elseif ($isRelevance) {
            $records = $this->ranker->rank($records, $search);
        }

        $total       = count($records);
        $pages       = max(1, (int) ceil($total / max(1, $perPage)));
        $pageRecords = array_slice($records, ( $page - 1 ) * $perPage, $perPage);

        if ('days_overdue' === $orderby) {
            $pageRecords = $this->sortByDaysOverdue($pageRecords, $order);
        }

        return $this->payload($pageRecords, $total, $perPage, $page, $pages, $filters);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function payload(array $records, int $total, int $perPage, int $page, int $pages, array $filters): array
    {
        return array(
            'records'  => $records,
            'total'    => $total,
            'per_page' => $perPage,
            'page'     => $page,
            'pages'    => max(1, $pages),
            'filters'  => $filters,
            'summary'  => $this->buildSummary($records),
        );
    }

    /**
     * Filter clauses, all exact matches, ANDed.
     *
     * Note the store key: the argument is `transaction_type`, the stored key is
     * `accounting_transaction_type`.
     *
     * @param array<string, mixed> $filters
     * @return array<int|string, mixed>
     */
    private function metaClauses(array $filters): array
    {
        $clauses = array('relation' => 'AND');
        $exact   = array(
            'transaction_type' => 'accounting_transaction_type',
            'billing_status'   => 'billing_status',
            'service_category' => 'service_category',
        );

        foreach ($exact as $filter => $key) {
            $value = (string) $filters[ $filter ];

            if ('' !== $value && 'all' !== $value) {
                $clauses[] = array('key' => $key, 'value' => $value);
            }
        }

        $min = $filters['invoice_amount_min'] ?? null;
        $max = $filters['invoice_amount_max'] ?? null;

        if (null !== $min && null !== $max) {
            $clauses[] = array('key' => 'invoice_amount', 'value' => array((float) $min, (float) $max), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(10,2)');
        } elseif (null !== $min) {
            $clauses[] = array('key' => 'invoice_amount', 'value' => (float) $min, 'compare' => '>=', 'type' => 'DECIMAL(10,2)');
        } elseif (null !== $max) {
            $clauses[] = array('key' => 'invoice_amount', 'value' => (float) $max, 'compare' => '<=', 'type' => 'DECIMAL(10,2)');
        }

        return count($clauses) > 1 ? $clauses : array();
    }

    /**
     * Up to `$cap` matching contacts, in 200-row pages.
     *
     * @param array<int|string, mixed> $meta
     * @return list<ContentItem>
     */
    private function fetchCapped(array $meta, string $orderby, string $order, int $cap): array
    {
        $nativeBy    = 'name' === $orderby ? 'title' : 'date';
        $nativeOrder = in_array($orderby, array('date', 'name'), true) ? $order : 'DESC';

        $items = array();
        $page  = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    metaQuery: $meta,
                    orderBy: $nativeBy,
                    order: $nativeOrder,
                    page: $page,
                    perPage: self::PAGE_CHUNK,
                )
            );

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
                    continue;
                }

                $items[] = $item;

                if (count($items) >= $cap) {
                    break 2;
                }
            }

            $exhausted = count($result->items) < self::PAGE_CHUNK || $page >= $result->totalPages;
            ++$page;
        } while (! $exhausted);

        return array_slice($items, 0, $cap);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(ContentItem $item, bool $includeAudit): array
    {
        $meta        = $item->meta;
        $billing     = $this->key($meta['billing_status'] ?? '');
        $dueDate     = $this->text($meta['invoice_due_date'] ?? '');
        $rawCurrency = strtoupper($this->text($meta['invoice_currency'] ?? ''));
        $currency    = '' !== $rawCurrency ? $rawCurrency : $this->defaultCurrency();
        $rawAmount   = $meta['invoice_amount'] ?? null;
        $amount      = is_numeric($rawAmount) ? (float) $rawAmount : null;

        $row = array(
            'id'               => $item->id,
            'name'             => $item->title,
            'email'            => $this->text($meta['email'] ?? ''),
            'first_name'       => $this->text($meta['first_name'] ?? ''),
            'last_name'        => $this->text($meta['last_name'] ?? ''),
            'company'          => $this->text($meta['company'] ?? ''),
            'transaction_type' => $this->key($meta['accounting_transaction_type'] ?? ''),
            'billing_status'   => $billing,
            'service_category' => $this->key($meta['service_category'] ?? ''),
            'invoice_number'   => $this->text($meta['invoice_number'] ?? ''),
            'invoice_amount'   => $amount,
            'invoice_due_date' => $dueDate,
            'days_overdue'     => $this->daysOverdue($billing, $dueDate),
            'currency'         => $currency,
            'added_date'       => $item->createdAt->format('Y-m-d H:i:s'),
            'edit_url'         => null,
        );

        if ($includeAudit) {
            $row['audit_meta'] = $this->auditMeta($item, $billing);
        }

        return $row;
    }

    /**
     * The post-query filters, in WordPress' order.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function rowPasses(array $row, array $filters): bool
    {
        $domain = (string) $filters['email_domain'];

        if ('' !== $domain && false === strpos((string) $row['email'], '@' . $domain)) {
            return false;
        }

        $currency = (string) $filters['currency_code'];

        if ('' !== $currency && $currency !== $row['currency']) {
            return false;
        }

        if (isset($filters['days_overdue_min'])) {
            $days = $row['days_overdue'];

            if (null === $days || $days < (int) $filters['days_overdue_min']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Days overdue, or null when the record is closed or not yet due.
     *
     * Deviation from WordPress: an unparsable due date yields null. WordPress
     * coerces `strtotime()`'s false to 0 and reports ~20,000 days.
     */
    private function daysOverdue(string $billingStatus, string $dueDate): ?int
    {
        if (in_array($billingStatus, self::CLOSED_STATUSES, true)) {
            return null;
        }

        if ('' === $dueDate) {
            return null;
        }

        $due = strtotime($dueDate);

        if (false === $due) {
            return null;
        }

        $seconds = $this->now() - $due;

        return $seconds > 0 ? (int) floor($seconds / 86400) : null;
    }

    /**
     * The fiscal window, as WordPress derives it (calendar quarters).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: string}
     */
    private function dateWindow(array $filters): array
    {
        $year    = (int) $filters['fiscal_year'];
        $quarter = (string) $filters['fiscal_quarter'];

        if ($year > 0 && 'all' !== $quarter) {
            return $this->quarterDates($year, $quarter);
        }

        if ($year > 0) {
            return array($year . '-01-01', $year . '-12-31');
        }

        return array((string) $filters['date_from'], (string) $filters['date_to']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function quarterDates(int $year, string $quarter): array
    {
        $map = array(
            'Q1' => array($year . '-01-01', $year . '-03-31'),
            'Q2' => array($year . '-04-01', $year . '-06-30'),
            'Q3' => array($year . '-07-01', $year . '-09-30'),
            'Q4' => array($year . '-10-01', $year . '-12-31'),
        );

        return $map[ $quarter ] ?? array($year . '-01-01', $year . '-12-31');
    }

    /**
     * WordPress' `date_query` at day granularity.
     */
    private function withinDates(ContentItem $item, string $from, string $to): bool
    {
        $day = $item->createdAt->format('Y-m-d');

        if ('' !== $from && $day < $from) {
            return false;
        }

        if ('' !== $to && $day > $to) {
            return false;
        }

        return true;
    }

    /**
     * Sort on the computed overdue days; null sorts last in both directions.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     */
    private function sortByDaysOverdue(array $records, string $order): array
    {
        usort(
            $records,
            static function (array $a, array $b) use ($order): int {
                $aVal = null !== $a['days_overdue'] ? (int) $a['days_overdue'] : PHP_INT_MAX;
                $bVal = null !== $b['days_overdue'] ? (int) $b['days_overdue'] : PHP_INT_MAX;

                return 'DESC' === $order ? $bVal - $aVal : $aVal - $bVal;
            }
        );

        return $records;
    }

    /**
     * Compliance metadata, as WordPress builds it.
     *
     * @return array<string, mixed>
     */
    private function auditMeta(ContentItem $item, string $billingStatus): array
    {
        $years = in_array($billingStatus, self::CLOSED_STATUSES, true) ? 7 : 3;

        return array(
            'data_retention_years'      => $years,
            'data_retention_flag'       => sprintf('Retain for %d years per accounting regulations', $years),
            'audit_trail_available'     => ! empty($item->meta['audit_trail']),
            'accounting_platform_hints' => array('No accounting integration detected'),
            'compliance_notes'          => 'Billing/accounting emails must be stored separately from marketing correspondence per industry data-governance standards.',
        );
    }

    /**
     * Financial totals over the returned page.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function buildSummary(array $records): array
    {
        $totals = array(
            'total_records'   => count($records),
            'total_amount'    => 0.0,
            'overdue_count'   => 0,
            'overdue_amount'  => 0.0,
            'pending_count'   => 0,
            'pending_amount'  => 0.0,
            'paid_count'      => 0,
            'paid_amount'     => 0.0,
            'disputed_count'  => 0,
            'disputed_amount' => 0.0,
        );

        foreach ($records as $record) {
            $amount = is_numeric($record['invoice_amount']) ? (float) $record['invoice_amount'] : 0.0;
            $status = (string) $record['billing_status'];

            $totals['total_amount'] += $amount;

            if (in_array($status, array('overdue', 'pending', 'paid', 'disputed'), true)) {
                ++$totals[ $status . '_count' ];
                $totals[ $status . '_amount' ] += $amount;
            }
        }

        foreach (array('total_amount', 'overdue_amount', 'pending_amount', 'paid_amount', 'disputed_amount') as $key) {
            $totals[ $key ] = round($totals[ $key ], 2);
        }

        return $totals;
    }

    /**
     * The default currency: the CRM toolkit setting, USD when unset.
     */
    private function defaultCurrency(): string
    {
        $currency = strtoupper($this->text($this->crmSettings()['default_currency'] ?? 'USD'));

        return '' !== $currency ? $currency : 'USD';
    }
}
