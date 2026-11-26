<?php

/**
 * Email-driven lead search over the toolkit contact store.
 *
 * Ported from `WP_MCP_AI_Tool_CRM_Email_Search_Leads`. The tool searches
 * `mcp_crm_contacts` — the store `manage_crm_contact` writes — with the
 * declarative filter family the WordPress tool declares (pipeline stage,
 * inquiry type, source channel, priority, MQL/SQL stage, owner, score range,
 * email domain, date range), sorts by relevance or a field, and caches the
 * filter set.
 *
 * ## A default that finds nothing, twice over
 *
 * `lead_status` defaults to `new`, as WordPress declares it, and the default
 * `orderby` is `lead_score`, which WordPress sorts through a `meta_key` INNER
 * JOIN. The contact manager writes neither key, so the **default call returns
 * zero rows for two independent reasons**. Both are WordPress behaviours,
 * pinned rather than widened: the working invocation is
 * `lead_status: all, orderby: date`, and the readiness note says so.
 *
 * ## Sorting that the core cannot do natively
 *
 * `ContentQuery` sorts on date, title and id only. Two documented consequences:
 *
 * 1. A meta sort (`lead_score`, `company`) and the `relevance` order fetch a
 *    bounded 500-row window and sort in memory, the same cap the WordPress
 *    relevance path uses.
 * 2. WordPress sorts by `meta_key` with an INNER JOIN, so contacts **without**
 *    that key are excluded from the result. The port does the same for the two
 *    meta sorts, rather than treating a missing score as zero.
 *
 * `email_domain` is a post-query filter in WordPress, and `total` / `pages`
 * count rows *before* it — so a page can be shorter than `per_page`. The port
 * keeps that shape for the native-pagination path; on the in-memory path the
 * counts are the filtered ones, as in WordPress' own relevance path.
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

class CrmEmailSearchLeadsTool extends CrmEmailSearchTool
{
    public const CACHE_KEY_PREFIX = 'crm_leads_search_';

    public const LEAD_STATUSES = array('new', 'contacted', 'qualified', 'unqualified', 'nurturing', 'converted', 'all');

    public const INQUIRY_TYPES = array(
        'new_inquiry', 'demo_request', 'pricing_inquiry', 'partnership', 'trial_request',
        'support_request', 'referral', 'consultation_request', 'event_registration',
        'content_download', 'newsletter_signup', 'account_management', 'general', 'all',
    );

    public const SOURCE_CHANNELS = array(
        'web_form', 'email_inbound', 'phone_call', 'chat', 'social_media', 'referral',
        'event', 'campaign', 'import', 'api', 'manual', 'partner', 'organic_search',
        'paid_search', 'all',
    );

    public const LEAD_PRIORITY = array('high', 'medium', 'low', 'all');

    public const MQL_STAGES = array('mql', 'sql', 'all');

    public const CRON_SCHEDULES = array('hourly', 'twicedaily', 'daily');

    public const ORDERBY_OPTIONS = array('relevance', 'lead_score', 'date', 'name', 'company');

    /** The in-memory sort/rank window, as WordPress uses for relevance. */
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
        return 'crm_email_search_leads';
    }

    public function getName(): string
    {
        return 'CRM Email Search: New Leads';
    }

    public function getDescription(): string
    {
        return 'Search CRM contacts for new leads by free-text search with TF-IDF relevance scoring, or by email-based criteria including lead score, email domain, inquiry type, source channel, priority, and date range. Sort results by relevance, lead_score, date, name, or company in ASC or DESC order. Results are cached for efficient throughout-the-day querying.';
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
                'action'           => array(
                    'type'        => 'string',
                    'enum'        => array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule', 'import_from_gmail'),
                    'description' => 'Action to perform. "search" caches results for later retrieval; "get_cached" returns them and falls back to a fresh search when no cache exists; "clear_cache" invalidates them. "schedule", "unschedule" and "import_from_gmail" require host services this runtime does not carry.',
                    'default'     => 'search',
                ),
                'lead_status'      => array(
                    'type'        => 'string',
                    'enum'        => self::LEAD_STATUSES,
                    'description' => 'Pipeline stage filter. "new" = freshly added, "nurturing" = in drip sequence, "converted" = won. Defaults to "new"; pass "all" on a store written only by manage_crm_contact.',
                    'default'     => 'new',
                ),
                'inquiry_type'     => array(
                    'type'        => 'string',
                    'enum'        => self::INQUIRY_TYPES,
                    'description' => 'Email category/inquiry type.',
                    'default'     => 'all',
                ),
                'email_domain'     => array(
                    'type'        => 'string',
                    'description' => 'Filter leads by email domain (e.g. "acmecorp.com"). Supports partial match.',
                ),
                'source'           => array(
                    'type'        => 'string',
                    'enum'        => self::SOURCE_CHANNELS,
                    'description' => 'Lead source channel.',
                    'default'     => 'all',
                ),
                'lead_score_min'   => array(
                    'type'        => 'integer',
                    'description' => 'Minimum lead score (0-100). Industry standard: 0-39 cold, 40-69 warm, 70-100 hot.',
                    'minimum'     => 0,
                    'maximum'     => 100,
                ),
                'lead_score_max'   => array(
                    'type'        => 'integer',
                    'description' => 'Maximum lead score (0-100).',
                    'minimum'     => 0,
                    'maximum'     => 100,
                ),
                'date_from'        => array(
                    'type'        => 'string',
                    'description' => 'Include contacts added on or after this date (YYYY-MM-DD).',
                ),
                'date_to'          => array(
                    'type'        => 'string',
                    'description' => 'Include contacts added on or before this date (YYYY-MM-DD).',
                ),
                'per_page'         => array(
                    'type'        => 'integer',
                    'description' => 'Results per page (1–100).',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 20,
                ),
                'page'             => array(
                    'type'        => 'integer',
                    'description' => 'Page number.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'cache_ttl'        => array(
                    'type'        => 'integer',
                    'description' => 'Cache lifetime in seconds (minimum 60, default 3600).',
                    'minimum'     => 60,
                    'default'     => self::DEFAULT_CACHE_TTL,
                ),
                'schedule'         => array(
                    'type'        => 'string',
                    'enum'        => self::CRON_SCHEDULES,
                    'description' => 'Recurrence for automatic cache refresh. Used with action=schedule, which requires a host cron service.',
                    'default'     => 'hourly',
                ),
                'force_refresh'    => array(
                    'type'        => 'boolean',
                    'description' => 'When true, bypass any cached results and execute a fresh query.',
                    'default'     => false,
                ),
                'contact_owner'    => array(
                    'type'        => 'string',
                    'description' => 'Filter leads by assigned contact owner.',
                ),
                'mql_stage'        => array(
                    'type'        => 'string',
                    'enum'        => self::MQL_STAGES,
                    'description' => 'Lead qualification stage filter: mql, sql, or all.',
                    'default'     => 'all',
                ),
                'priority'         => array(
                    'type'        => 'string',
                    'enum'        => self::LEAD_PRIORITY,
                    'description' => 'Lead priority/urgency filter.',
                    'default'     => 'all',
                ),
                'connection_ids'   => array(
                    'type'        => 'array',
                    'description' => 'Remote Gmail connection IDs. Requires the Gmail client, which this runtime does not carry.',
                    'items'       => array('type' => 'string'),
                ),
                'search'           => array(
                    'type'        => 'string',
                    'description' => 'Free-text search across lead name, company, and email, with relevance scoring when orderby is relevance.',
                ),
                'orderby'          => array(
                    'type'        => 'string',
                    'enum'        => self::ORDERBY_OPTIONS,
                    'description' => 'Sort results by this field.',
                    'default'     => 'lead_score',
                ),
                'order'            => array(
                    'type'        => 'string',
                    'enum'        => array('ASC', 'DESC'),
                    'description' => 'Sort direction.',
                    'default'     => 'DESC',
                ),
                'include_external' => array(
                    'type'        => 'boolean',
                    'description' => 'When true, also searches external Gmail connections. Requires the Gmail client, which this runtime does not carry.',
                    'default'     => false,
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

            case 'import_from_gmail':
                return $this->externalUnavailable();

            default:
                return $this->errors->validationFailed(
                    'Invalid action. Must be one of: search, get_cached, clear_cache, schedule, unschedule, import_from_gmail.',
                    array('action' => array('Use one of the declared actions.')),
                );
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runSearch(array $arguments): mixed
    {
        if (! empty($arguments['include_external'])) {
            return $this->externalUnavailable();
        }

        $filters    = $this->extractFilters($arguments);
        $cacheKey   = $this->cacheKey($filters);
        $ttl        = max(60, $this->intParam($arguments, 'cache_ttl', self::DEFAULT_CACHE_TTL));
        $force      = ! empty($arguments['force_refresh']);

        if (! $force) {
            $cached = $this->cacheGet($cacheKey);

            if (is_array($cached)) {
                $cached['from_cache']    = true;
                $cached['force_refresh'] = false;

                return $this->success(
                    sprintf('Found %d leads (cached).', (int) ( $cached['total'] ?? 0 )),
                    $cached
                );
            }
        }

        $data                   = $this->queryLeads($filters);
        $this->cacheSet($cacheKey, $data, $ttl);

        $data['cached']        = $this->hasCache();
        $data['cache_ttl']     = $ttl;
        $data['cached_at']     = $this->nowString();
        $data['cache_key']     = $cacheKey;
        $data['force_refresh'] = $force;

        return $this->success(sprintf('Found %d leads.', (int) $data['total']), $data);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getCached(array $arguments): mixed
    {
        $filters  = $this->extractFilters($arguments);
        $cacheKey = $this->cacheKey($filters);
        $cached   = $this->cacheGet($cacheKey);

        if (! is_array($cached)) {
            $result = $this->runSearch($arguments);

            if (is_array($result) && is_array($result['data'] ?? null)) {
                $result['data']['auto_fallback']        = true;
                $result['data']['auto_fallback_reason'] = 'No prior cache existed; ran a fresh search instead.';
            }

            return $result;
        }

        $cached['from_cache'] = true;

        return $this->success(
            sprintf('Found %d leads (cached).', (int) ( $cached['total'] ?? 0 )),
            $cached
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function clearCached(array $arguments): mixed
    {
        $this->cacheDelete($this->cacheKey($this->extractFilters($arguments)));

        return $this->success('Lead search cache cleared successfully.', array());
    }

    /**
     * The filter set, sanitised and defaulted the way WordPress does.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function extractFilters(array $arguments): array
    {
        $filters = array(
            'lead_status'   => $this->enumParam($arguments, 'lead_status', self::LEAD_STATUSES, 'new'),
            'inquiry_type'  => $this->enumParam($arguments, 'inquiry_type', self::INQUIRY_TYPES, 'all'),
            'mql_stage'     => $this->enumParam($arguments, 'mql_stage', self::MQL_STAGES, 'all'),
            'contact_owner' => trim($this->stringParam($arguments, 'contact_owner')),
            'email_domain'  => trim($this->stringParam($arguments, 'email_domain')),
            'source'        => $this->enumParam($arguments, 'source', self::SOURCE_CHANNELS, 'all'),
            'priority'      => $this->enumParam($arguments, 'priority', self::LEAD_PRIORITY, 'all'),
            'date_from'     => trim($this->stringParam($arguments, 'date_from')),
            'date_to'       => trim($this->stringParam($arguments, 'date_to')),
            'per_page'      => $this->intParam($arguments, 'per_page', 20),
            'page'          => $this->intParam($arguments, 'page', 1),
            'orderby'       => $this->orderbyParam($this->stringParam($arguments, 'orderby', 'lead_score'), self::ORDERBY_OPTIONS, 'lead_score'),
            'order'         => 'ASC' === strtoupper($this->stringParam($arguments, 'order', 'DESC')) ? 'ASC' : 'DESC',
            'search'        => trim($this->stringParam($arguments, 'search')),
        );

        if (isset($arguments['lead_score_min'])) {
            $filters['lead_score_min'] = min(100, abs($this->intValue($arguments['lead_score_min'])));
        }

        if (isset($arguments['lead_score_max'])) {
            $filters['lead_score_max'] = min(100, abs($this->intValue($arguments['lead_score_max'])));
        }

        return $filters;
    }

    /**
     * The query result, before the response envelope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function queryLeads(array $filters): array
    {
        $perPage = min(max((int) $filters['per_page'], 1), 100);
        $page    = max((int) $filters['page'], 1);
        $orderby = (string) $filters['orderby'];
        $order   = (string) $filters['order'];
        $search  = (string) $filters['search'];
        $meta    = $this->metaClauses($filters);

        $isRelevance = 'relevance' === $orderby && '' !== $search;
        $inMemory    = $isRelevance
            || in_array($orderby, array('lead_score', 'company'), true)
            || '' !== $filters['date_from']
            || '' !== $filters['date_to'];

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

            $leads = array();

            foreach ($result->items as $item) {
                if ($item instanceof ContentItem && self::CONTACT_TYPE === $item->type) {
                    $row = $this->project($item);

                    if ($this->domainMatches($row['email'], (string) $filters['email_domain'])) {
                        $leads[] = $row;
                    }
                }
            }

            return $this->payload($leads, $result->total, $perPage, $page, $result->totalPages, $filters);
        }

        $items = $this->fetchCapped($meta, self::MAX_RANKED);
        $rows  = array();

        foreach ($items as $item) {
            $row = $this->project($item);

            if (! $this->domainMatches($row['email'], (string) $filters['email_domain'])) {
                continue;
            }

            if (! $this->withinDates($item, (string) $filters['date_from'], (string) $filters['date_to'])) {
                continue;
            }

            $rows[] = $row;
        }

        if (in_array($orderby, array('lead_score', 'company'), true)) {
            // WordPress sorts by meta_key with an INNER JOIN: rows without the
            // key are not in the result set at all.
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => null !== $row[ $orderby ] && '' !== (string) $row[ $orderby ]
            ));

            $rows = $this->sortRowsByField($rows, $orderby, $order, 'lead_score' === $orderby);
        } elseif ($isRelevance) {
            $rows = $this->ranker->rank($rows, $search);
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $rows  = array_slice($rows, ( $page - 1 ) * $perPage, $perPage);

        return $this->payload($rows, $total, $perPage, $page, $pages, $filters);
    }

    /**
     * @param array<int, array<string, mixed>> $leads
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function payload(array $leads, int $total, int $perPage, int $page, int $pages, array $filters): array
    {
        return array(
            'leads'    => $leads,
            'total'    => $total,
            'per_page' => $perPage,
            'page'     => $page,
            'pages'    => $pages,
            'filters'  => $filters,
            'scoring'  => array(
                'cold' => '0–39',
                'warm' => '40–69',
                'hot'  => '70–100',
            ),
        );
    }

    /**
     * Filter clauses, all exact matches, ANDed.
     *
     * @param array<string, mixed> $filters
     * @return array<int|string, mixed>
     */
    private function metaClauses(array $filters): array
    {
        $clauses = array('relation' => 'AND');
        $exact   = array(
            'lead_status'  => 'lead_status',
            'inquiry_type' => 'inquiry_type',
            'source'       => 'source',
            'priority'     => 'priority',
            'mql_stage'    => 'mql_stage',
        );

        foreach ($exact as $filter => $key) {
            $value = (string) $filters[ $filter ];

            if ('' !== $value && 'all' !== $value) {
                $clauses[] = array('key' => $key, 'value' => $value);
            }
        }

        if ('' !== $filters['contact_owner']) {
            $clauses[] = array('key' => 'contact_owner', 'value' => (string) $filters['contact_owner']);
        }

        $min = $filters['lead_score_min'] ?? null;
        $max = $filters['lead_score_max'] ?? null;

        if (null !== $min && null !== $max) {
            $clauses[] = array('key' => 'lead_score', 'value' => array((int) $min, (int) $max), 'compare' => 'BETWEEN', 'type' => 'NUMERIC');
        } elseif (null !== $min) {
            $clauses[] = array('key' => 'lead_score', 'value' => (int) $min, 'compare' => '>=', 'type' => 'NUMERIC');
        } elseif (null !== $max) {
            $clauses[] = array('key' => 'lead_score', 'value' => (int) $max, 'compare' => '<=', 'type' => 'NUMERIC');
        }

        return count($clauses) > 1 ? $clauses : array();
    }

    /**
     * Up to `$cap` matching contacts, newest first, in 200-row pages.
     *
     * @param array<int|string, mixed> $meta
     * @return list<ContentItem>
     */
    private function fetchCapped(array $meta, int $cap): array
    {
        $items = array();
        $page  = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    metaQuery: $meta,
                    orderBy: 'date',
                    order: 'DESC',
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
    private function project(ContentItem $item): array
    {
        $meta  = $item->meta;
        $score = $meta['lead_score'] ?? null;
        $score = is_numeric($score) ? (int) $score : null;

        return array(
            'id'            => $item->id,
            'name'          => $item->title,
            'email'         => $this->text($meta['email'] ?? ''),
            'first_name'    => $this->text($meta['first_name'] ?? ''),
            'last_name'     => $this->text($meta['last_name'] ?? ''),
            'company'       => $this->text($meta['company'] ?? ''),
            'lead_status'   => $this->key($meta['lead_status'] ?? ''),
            'inquiry_type'  => $this->key($meta['inquiry_type'] ?? ''),
            'mql_stage'     => $this->key($meta['mql_stage'] ?? ''),
            'priority'      => $this->key($meta['priority'] ?? ''),
            'contact_owner' => $this->text($meta['contact_owner'] ?? ''),
            'source'        => $this->key($meta['source'] ?? ''),
            'lead_score'    => $score,
            'score_label'   => $this->scoreLabel($score),
            'added_date'    => $item->createdAt->format('Y-m-d H:i:s'),
            'edit_url'      => null,
            'origin'        => 'local',
        );
    }

    private function scoreLabel(?int $score): string
    {
        if (null === $score) {
            return 'unscored';
        }

        if ($score >= 70) {
            return 'hot';
        }

        if ($score >= 40) {
            return 'warm';
        }

        return 'cold';
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
     * The `email_domain` post-filter: a partial match after the `@`.
     */
    private function domainMatches(string $email, string $domain): bool
    {
        return '' === $domain || false !== strpos($email, '@' . $domain);
    }
}
