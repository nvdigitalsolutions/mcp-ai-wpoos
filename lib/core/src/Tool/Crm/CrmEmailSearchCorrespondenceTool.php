<?php

/**
 * Email-driven correspondence search over the toolkit contact store.
 *
 * Ported from `WP_MCP_AI_Tool_CRM_Email_Search_Correspondence`. It searches
 * `mcp_crm_contacts` on the correspondence family (`last_contacted`,
 * `follow_up_date`, `contact_count`, `correspondence_category`, `channel`,
 * `contact_status`, `tags`), supports follow-up SLA modes, and returns
 * per-contact analytics and a routing suggestion.
 *
 * ## Defaults that interact badly, pinned
 *
 * The defaults are `correspondence_type = needs_followup`, `orderby =
 * last_contacted`, `order = ASC`. Those two defaults disagree about the
 * never-contacted cohort:
 *
 * - `needs_followup` explicitly includes `last_contacted` **NOT EXISTS**.
 * - `orderby = last_contacted` is a `meta_key` sort, and WordPress implements
 *   that with an INNER JOIN, which drops exactly those rows.
 *
 * So the default call returns only contacts that *have* a `last_contacted`
 * older than the cutoff: the never-contacted half of `needs_followup` is
 * invisible until the caller sorts by `date` or `name`. The same collision
 * makes `correspondence_type = never_contacted` with the default sort return
 * zero rows. Both are WordPress behaviour and both are pinned.
 *
 * ## Unparsable dates
 *
 * WordPress coerces `strtotime()`'s `false` to `0`, which reports a huge
 * days-since value and treats a malformed date as an SLA breach. The port
 * returns `null` for analytics and never reports a malformed date as a breach.
 * Same deviation as §164.68's due dates.
 *
 * ## Other pinned behaviours
 *
 * - `overdue` compares `follow_up_date < now`; `needs_followup` compares
 *   `last_contacted < cutoff` (or absent), and `days_since_contact` only feeds
 *   those two cutoffs and the SLA filter.
 * - `get_cached` is a `not_found` error on a miss, as in the accounting tool,
 *   with a `contacts` / `total` payload.
 * - The total on the native path is the store's pre-post-filter count, so a
 *   page can be shorter than `per_page` after the domain/tag/SLA filters — the
 *   same shape WordPress returns.
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

class CrmEmailSearchCorrespondenceTool extends CrmEmailSearchTool
{
    public const CACHE_KEY_PREFIX = 'crm_correspondence_search_';

    public const CATEGORIES = array('support', 'general', 'sales', 'escalated', 'all');

    public const CORRESPONDENCE_TYPES = array('needs_followup', 'recently_contacted', 'never_contacted', 'overdue', 'all');

    public const CRON_SCHEDULES = array('hourly', 'twicedaily', 'daily');

    public const CHANNELS = array('email', 'phone', 'chat', 'social', 'all');

    public const ORDERBY_OPTIONS = array('relevance', 'last_contacted', 'date', 'name', 'company');

    /** The in-memory sort/rank window, as WordPress uses. */
    public const MAX_RANKED = 500;

    /** Rows per store page while filling that window. */
    private const PAGE_CHUNK = 200;

    /** The routing suggestion per category, as WordPress returns it. */
    private const ROUTING = array(
        'support'   => 'Route to Customer Support queue',
        'escalated' => 'Escalate to Senior Support / Account Manager immediately',
        'sales'     => 'Route to Sales team for follow-up',
        'general'   => 'Assign to account owner for response',
    );

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
        return 'crm_email_search_correspondence';
    }

    public function getName(): string
    {
        return 'CRM Email Search: Customer Correspondence';
    }

    public function getDescription(): string
    {
        return 'Search CRM contacts for customer correspondence activity. Supports email categories (support, general, sales, escalated), follow-up status filtering, SLA-breach detection, per-contact response-time analytics and routing suggestions, and free-text relevance search across name, company, and email. Results are cached for efficient throughout-the-day querying.';
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
                'action'              => array(
                    'type'        => 'string',
                    'enum'        => array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule'),
                    'description' => 'search executes and caches; get_cached returns cached results or a not-found error; clear_cache invalidates; schedule/unschedule require a host cron service this runtime does not carry.',
                    'default'     => 'search',
                ),
                'correspondence_type' => array(
                    'type'        => 'string',
                    'enum'        => self::CORRESPONDENCE_TYPES,
                    'description' => 'needs_followup = last contact older than days_since_contact or none; recently_contacted = within the window; never_contacted = no last_contacted; overdue = past follow_up_date.',
                    'default'     => 'needs_followup',
                ),
                'category'            => array(
                    'type'        => 'string',
                    'enum'        => self::CATEGORIES,
                    'description' => 'Correspondence category: support, general, sales, escalated.',
                    'default'     => 'all',
                ),
                'days_since_contact'  => array(
                    'type'        => 'integer',
                    'description' => 'Threshold in days for needs_followup (minimum) and recently_contacted (maximum).',
                    'minimum'     => 1,
                    'default'     => 7,
                ),
                'contact_status'      => array(
                    'type'        => 'string',
                    'description' => 'Filter by CRM contact status (e.g. "active", "prospect", "customer").',
                ),
                'tags'                => array(
                    'type'        => 'array',
                    'description' => 'Return only contacts that possess ALL of these tags.',
                    'items'       => array('type' => 'string'),
                ),
                'email_domain'        => array(
                    'type'        => 'string',
                    'description' => 'Filter by contact email domain (e.g. "example.com").',
                ),
                'include_analytics'   => array(
                    'type'        => 'boolean',
                    'description' => 'Include per-contact response-time analytics and routing suggestions.',
                    'default'     => true,
                ),
                'per_page'            => array(
                    'type'        => 'integer',
                    'description' => 'Results per page (1–100).',
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'default'     => 20,
                ),
                'page'                => array(
                    'type'        => 'integer',
                    'description' => 'Page number.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'cache_ttl'           => array(
                    'type'        => 'integer',
                    'description' => 'Cache lifetime in seconds (minimum 60, default 3600).',
                    'minimum'     => 60,
                    'default'     => self::DEFAULT_CACHE_TTL,
                ),
                'schedule'            => array(
                    'type'        => 'string',
                    'enum'        => self::CRON_SCHEDULES,
                    'description' => 'Recurrence for automatic refresh. Used with action=schedule, which requires a host cron service.',
                    'default'     => 'hourly',
                ),
                'force_refresh'       => array(
                    'type'        => 'boolean',
                    'description' => 'When true, bypass any cached results and execute a fresh query.',
                    'default'     => false,
                ),
                'channel'             => array(
                    'type'        => 'string',
                    'enum'        => self::CHANNELS,
                    'description' => 'Communication channel filter: email, phone, chat, social, all.',
                    'default'     => 'all',
                ),
                'sla_breach_only'     => array(
                    'type'        => 'boolean',
                    'description' => 'Return only contacts past the days_since_contact SLA threshold.',
                    'default'     => false,
                ),
                'search'              => array(
                    'type'        => 'string',
                    'description' => 'Free-text search across contact name, company, and email, ranked when orderby is relevance.',
                ),
                'orderby'             => array(
                    'type'        => 'string',
                    'enum'        => self::ORDERBY_OPTIONS,
                    'description' => 'Sort field. last_contacted and company are meta sorts that exclude rows without the key, as WordPress does.',
                    'default'     => 'last_contacted',
                ),
                'order'               => array(
                    'type'        => 'string',
                    'enum'        => array('ASC', 'DESC'),
                    'description' => 'Sort direction.',
                    'default'     => 'ASC',
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
                    sprintf('Found %d contacts (cached).', (int) ( $cached['total'] ?? 0 )),
                    $cached
                );
            }
        }

        $data = $this->queryCorrespondence($filters);
        $this->cacheSet($cacheKey, $data, $ttl);

        $data['cached']        = $this->hasCache();
        $data['cache_ttl']     = $ttl;
        $data['cached_at']     = $this->nowString();
        $data['cache_key']     = $cacheKey;
        $data['force_refresh'] = $force;

        return $this->success(sprintf('Found %d contacts.', (int) $data['total']), $data);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getCached(array $arguments): mixed
    {
        $cached = $this->cacheGet($this->cacheKey($this->extractFilters($arguments)));

        if (! is_array($cached)) {
            return $this->errors->notFound(
                'No cached results found. Run action=search first to populate the cache.',
                array('contacts' => array(), 'total' => 0)
            );
        }

        $cached['from_cache'] = true;

        return $this->success(
            sprintf('Found %d contacts (cached).', (int) ( $cached['total'] ?? 0 )),
            $cached
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function clearCached(array $arguments): mixed
    {
        $this->cacheDelete($this->cacheKey($this->extractFilters($arguments)));

        return $this->success('Correspondence search cache cleared successfully.', array());
    }

    /**
     * The filter set, sanitised and defaulted the way WordPress does.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function extractFilters(array $arguments): array
    {
        $tags = array();

        if (isset($arguments['tags']) && is_array($arguments['tags'])) {
            foreach ($arguments['tags'] as $tag) {
                if (is_scalar($tag)) {
                    $tags[] = trim((string) $tag);
                }
            }
        }

        $order = strtoupper($this->text($this->stringParam($arguments, 'order', 'ASC')));

        return array(
            'correspondence_type' => $this->enumParam($arguments, 'correspondence_type', self::CORRESPONDENCE_TYPES, 'needs_followup'),
            'category'            => $this->enumParam($arguments, 'category', self::CATEGORIES, 'all'),
            'channel'             => $this->enumParam($arguments, 'channel', self::CHANNELS, 'all'),
            'sla_breach_only'     => isset($arguments['sla_breach_only']) && (bool) $arguments['sla_breach_only'],
            'days_since_contact'  => max(1, abs($this->intValue($arguments['days_since_contact'] ?? 7))),
            'contact_status'      => $this->text($this->stringParam($arguments, 'contact_status')),
            'tags'                => $tags,
            'email_domain'        => $this->text($this->stringParam($arguments, 'email_domain')),
            'include_analytics'   => isset($arguments['include_analytics']) ? (bool) $arguments['include_analytics'] : true,
            'per_page'            => $this->intParam($arguments, 'per_page', 20),
            'page'                => $this->intParam($arguments, 'page', 1),
            'orderby'             => $this->orderbyParam($this->stringParam($arguments, 'orderby', 'last_contacted'), self::ORDERBY_OPTIONS, 'last_contacted'),
            'order'               => in_array($order, array('ASC', 'DESC'), true) ? $order : 'ASC',
            'search'              => trim($this->stringParam($arguments, 'search')),
        );
    }

    /**
     * The query result, before the response envelope.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function queryCorrespondence(array $filters): array
    {
        $perPage        = min(max((int) $filters['per_page'], 1), 100);
        $page           = max((int) $filters['page'], 1);
        $daysSince      = max(1, (int) $filters['days_since_contact']);
        $includeAnalytics = (bool) $filters['include_analytics'];
        $orderby        = (string) $filters['orderby'];
        $order          = (string) $filters['order'];
        $search         = (string) $filters['search'];

        $isRelevance = 'relevance' === $orderby && '' !== $search;
        $meta        = $this->metaClauses($filters, $daysSince);

        $inMemory = $isRelevance || in_array($orderby, array('last_contacted', 'company'), true);

        if (! $inMemory) {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    search: '' !== $search ? $search : null,
                    metaQuery: $meta,
                    orderBy: 'name' === $orderby ? 'title' : 'date',
                    order: $order,
                    page: $page,
                    perPage: $perPage,
                )
            );

            $contacts = array();

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || self::CONTACT_TYPE !== $item->type) {
                    continue;
                }

                $row = $this->project($item, $includeAnalytics);

                if ($this->rowPasses($row, $filters, $daysSince)) {
                    $contacts[] = $row;
                }
            }

            return $this->payload($contacts, $result->total, $perPage, $page, $result->totalPages, $filters);
        }

        $items    = $this->fetchCapped($meta, $search, self::MAX_RANKED);
        $contacts = array();

        foreach ($items as $item) {
            $row = $this->project($item, $includeAnalytics);

            if ($this->rowPasses($row, $filters, $daysSince)) {
                $contacts[] = $row;
            }
        }

        if (in_array($orderby, array('last_contacted', 'company'), true)) {
            // A meta_key sort is an INNER JOIN in WordPress: rows without the
            // key are not in the result at all.
            $contacts = array_values(array_filter(
                $contacts,
                static fn (array $row): bool => '' !== (string) $row[ $orderby ]
            ));

            $contacts = $this->sortRowsByField($contacts, $orderby, $order);
        } elseif ($isRelevance) {
            $contacts = $this->ranker->rank($contacts, $search);
        }

        $total = count($contacts);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return $this->payload(
            array_slice($contacts, ( $page - 1 ) * $perPage, $perPage),
            $total,
            $perPage,
            $page,
            $pages,
            $filters
        );
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function payload(array $contacts, int $total, int $perPage, int $page, int $pages, array $filters): array
    {
        return array(
            'contacts' => $contacts,
            'total'    => $total,
            'per_page' => $perPage,
            'page'     => $page,
            'pages'    => max(1, $pages),
            'filters'  => $filters,
        );
    }

    /**
     * Filter clauses, ANDed, with the follow-up mode as a nested group.
     *
     * @param array<string, mixed> $filters
     * @return array<int|string, mixed>
     */
    private function metaClauses(array $filters, int $daysSince): array
    {
        $clauses = array('relation' => 'AND');
        $cutoff  = gmdate('Y-m-d H:i:s', $this->now() - ( $daysSince * 86400 ));

        switch ((string) $filters['correspondence_type']) {
            case 'needs_followup':
                $clauses[] = array(
                    'relation' => 'OR',
                    array('key' => 'last_contacted', 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME'),
                    array('key' => 'last_contacted', 'compare' => 'NOT EXISTS'),
                );
                break;

            case 'recently_contacted':
                $clauses[] = array('key' => 'last_contacted', 'value' => $cutoff, 'compare' => '>=', 'type' => 'DATETIME');
                break;

            case 'never_contacted':
                $clauses[] = array('key' => 'last_contacted', 'compare' => 'NOT EXISTS');
                break;

            case 'overdue':
                $clauses[] = array('key' => 'follow_up_date', 'value' => $this->nowString(), 'compare' => '<', 'type' => 'DATETIME');
                break;

            default:
                break;
        }

        $search = (string) $filters['search'];

        if ('' !== $search) {
            $clauses[] = array(
                'relation' => 'OR',
                array('key' => 'company', 'value' => $search, 'compare' => 'LIKE'),
                array('key' => 'email', 'value' => $search, 'compare' => 'LIKE'),
            );
        }

        $category = (string) $filters['category'];

        if ('' !== $category && 'all' !== $category) {
            $clauses[] = array('key' => 'correspondence_category', 'value' => $category);
        }

        if ('' !== $filters['contact_status']) {
            $clauses[] = array('key' => 'contact_status', 'value' => (string) $filters['contact_status']);
        }

        $channel = (string) $filters['channel'];

        if ('' !== $channel && 'all' !== $channel) {
            $clauses[] = array('key' => 'channel', 'value' => $channel);
        }

        return count($clauses) > 1 ? $clauses : array();
    }

    /**
     * Up to `$cap` matching contacts, in 200-row pages, date descending.
     *
     * @param array<int|string, mixed> $meta
     * @return list<ContentItem>
     */
    private function fetchCapped(array $meta, string $search, int $cap): array
    {
        $items = array();
        $page  = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::CONTACT_TYPE),
                    statuses: array('publish'),
                    search: '' !== $search ? $search : null,
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
    private function project(ContentItem $item, bool $includeAnalytics): array
    {
        $meta     = $item->meta;
        $last     = $this->text($meta['last_contacted'] ?? '');
        $followUp = $this->text($meta['follow_up_date'] ?? '');
        $channel  = $this->key($meta['channel'] ?? '');
        $category = $this->key($meta['correspondence_category'] ?? '');
        $tags     = $meta['tags'] ?? array();

        $row = array(
            'id'             => $item->id,
            'name'           => $item->title,
            'email'          => $this->text($meta['email'] ?? ''),
            'first_name'     => $this->text($meta['first_name'] ?? ''),
            'last_name'      => $this->text($meta['last_name'] ?? ''),
            'company'        => $this->text($meta['company'] ?? ''),
            'contact_status' => $this->text($meta['contact_status'] ?? ''),
            'category'       => $category,
            // Email is the primary channel when none is tracked.
            'channel'        => '' !== $channel ? $channel : 'email',
            'last_contacted' => $last,
            'follow_up_date' => $followUp,
            'contact_count'  => abs($this->intValue($meta['contact_count'] ?? 0)),
            'tags'           => $this->tagList($tags),
            'added_date'     => $item->createdAt->format('Y-m-d H:i:s'),
            'edit_url'       => null,
        );

        if ($includeAnalytics) {
            $row['analytics'] = $this->analytics($last, $followUp, $row['contact_count'], $category);
        }

        return $row;
    }

    /**
     * The post-query filters, in WordPress' order.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function rowPasses(array $row, array $filters, int $daysSince): bool
    {
        $domain = (string) $filters['email_domain'];

        if ('' !== $domain && false === strpos((string) $row['email'], '@' . $domain)) {
            return false;
        }

        $wanted = $filters['tags'];

        if (is_array($wanted) && array() !== $wanted) {
            $have = array_intersect($wanted, (array) $row['tags']);

            if (count($have) < count($wanted)) {
                return false;
            }
        }

        if (! empty($filters['sla_breach_only']) && '' !== $row['last_contacted']) {
            $timestamp = strtotime((string) $row['last_contacted']);

            // A malformed date cannot prove a breach, so it is not reported as
            // one (WordPress would treat it as a breach since the epoch).
            if (false === $timestamp) {
                return false;
            }

            if (( $this->now() - $timestamp ) < ( $daysSince * 86400 )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Response-time analytics and the routing suggestion.
     *
     * @return array<string, mixed>
     */
    private function analytics(string $lastContacted, string $followUp, int $contactCount, string $category): array
    {
        $analytics = array(
            'days_since_last_contact' => null,
            'is_overdue'              => false,
            'days_overdue'            => null,
            'engagement_level'        => $this->engagementLevel($contactCount),
            'routing_suggestion'      => self::ROUTING[ $category ] ?? 'Review and assign manually',
        );

        if ('' !== $lastContacted) {
            $last = strtotime($lastContacted);

            if (false !== $last) {
                $analytics['days_since_last_contact'] = (int) floor(( $this->now() - $last ) / 86400);
            }
        }

        if ('' !== $followUp) {
            $due = strtotime($followUp);

            if (false !== $due) {
                $overdue = $this->now() - $due;

                if ($overdue > 0) {
                    $analytics['is_overdue']   = true;
                    $analytics['days_overdue'] = (int) floor($overdue / 86400);
                }
            }
        }

        return $analytics;
    }

    private function engagementLevel(int $count): string
    {
        if (0 === $count) {
            return 'dormant';
        }

        if ($count <= 3) {
            return 'low';
        }

        if ($count <= 10) {
            return 'moderate';
        }

        return 'high';
    }

    /**
     * A tag meta value as a list of strings.
     *
     * @return list<string>
     */
    private function tagList(mixed $value): array
    {
        if (is_array($value)) {
            $tags = array();

            foreach ($value as $tag) {
                if (is_scalar($tag)) {
                    $tags[] = trim((string) $tag);
                }
            }

            return $tags;
        }

        return is_scalar($value) && '' !== trim((string) $value) ? array(trim((string) $value)) : array();
    }
}
