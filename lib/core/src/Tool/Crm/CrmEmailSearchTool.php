<?php

/**
 * Shared ground for the three `crm_email_search_*` tools.
 *
 * The WordPress tools share a shape that is worth naming once: a filter set
 * hashed into a cache key, a cache-aside search, a `relevance` order that ranks
 * after the query, and actions for cached retrieval and cache clearing. The
 * three tools differ in their store filters and their projections, not in that
 * machinery.
 *
 * ## What the core cannot do
 *
 * Two WordPress actions depend on host services the framework-agnostic core
 * does not carry: `schedule` / `unschedule` (WP-Cron) and
 * `import_from_gmail` / `include_external` (the Gmail client). The actions stay
 * in the schema and in the dispatcher, but they answer with a typed
 * `*_unavailable` error rather than pretending. That is the same contract the
 * core uses elsewhere: name the absent capability instead of failing mid-run.
 *
 * ## Cache is optional
 *
 * A `CacheStoreInterface` is injected when the host has one. Without it, a
 * search runs live every time and the payload says `cached: false` — it does
 * not claim a cache it never wrote.
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

abstract class CrmEmailSearchTool extends CrmTool
{
    /** WordPress' default cache lifetime. */
    public const DEFAULT_CACHE_TTL = 3600;

    /** The ranker the `relevance` order uses. */
    protected RelevanceRanker $ranker;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
        protected readonly ?CacheStoreInterface $cache = null,
    ) {
        parent::__construct($e, $content, $settings);

        $this->ranker = new RelevanceRanker();
    }

    /**
     * The tool's cache-key prefix, as WordPress names it.
     */
    abstract protected function cachePrefix(): string;

    /**
     * A stable key for a filter set.
     *
     * @param array<string, mixed> $filters
     */
    protected function cacheKey(array $filters): string
    {
        ksort($filters);

        $encoded = json_encode($filters);

        return $this->cachePrefix() . md5(false !== $encoded ? $encoded : serialize($filters));
    }

    /**
     * @return mixed False on a miss, as WordPress' transient API reports it.
     */
    protected function cacheGet(string $key): mixed
    {
        return null !== $this->cache ? $this->cache->getValue($key, false) : false;
    }

    protected function cacheSet(string $key, mixed $value, int $ttl): void
    {
        if (null !== $this->cache) {
            $this->cache->setValue($key, $value, $ttl);
        }
    }

    protected function cacheDelete(string $key): void
    {
        if (null !== $this->cache) {
            $this->cache->deleteValue($key);
        }
    }

    protected function hasCache(): bool
    {
        return null !== $this->cache;
    }

    /**
     * The current time as WordPress stores it.
     */
    protected function nowString(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now());
    }

    /**
     * A typed refusal for a host service this runtime does not carry.
     */
    protected function unavailable(string $code, string $message): mixed
    {
        return $this->errors->create($code, $message);
    }

    /**
     * The shared "scheduled refresh" refusal.
     */
    protected function scheduleUnavailable(): mixed
    {
        return $this->unavailable(
            'schedule_unavailable',
            'Scheduled refresh requires a host cron service. This runtime has none; call action=search with force_refresh=true instead.'
        );
    }

    /**
     * The shared "external mailbox" refusal.
     */
    protected function externalUnavailable(): mixed
    {
        return $this->unavailable(
            'external_search_unavailable',
            'External Gmail search requires the Gmail client. This runtime has none; search local CRM records only.'
        );
    }
}
