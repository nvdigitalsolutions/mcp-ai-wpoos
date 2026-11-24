<?php

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Cache;
use Nvoos\Core\Domain\Contract\MediaWorkerClientInterface;
use Nvoos\Core\Domain\ValueObject\MediaWorkerCapabilityReport;

/**
 * The worker capability report, probed once and cached — Proposal 164 §164.4.2.
 *
 * `MediaWorkerCapabilityReport` is pure: it describes what it was told. This is
 * the class that asks, and it exists as its own service for three reasons.
 *
 *  - **One probe, many readers.** Diagnostics, the media command centre and
 *    every future domain adapter need the same answer. Without a single owner
 *    each would invent its own timeout and its own idea of what "down" means.
 *  - **The TTL is a decision, not an accident.** The matrix is a boot-time fact
 *    on the worker, so a per-request probe would be wasteful; but a singleton
 *    memoised for the process would go stale on an Octane worker that lives for
 *    hours. A cache entry with a TTL is the only shape that is both cheap under
 *    FPM and bounded under Octane.
 *  - **A probe never fails the page.** Diagnostics that 500s because a sidecar
 *    is down is worse than diagnostics that says `unknown`.
 *
 * The cache is keyed without the worker URL, deliberately: a host has one
 * worker, and changing the URL is a deploy.
 */
final class MediaWorkerCapabilityProbe
{
    /**
     * Cache key for the report.
     *
     * Versioned: the serialised report is a value object, and changing its shape
     * would otherwise let a pre-upgrade entry be read as a post-upgrade one.
     */
    private const CACHE_KEY = 'oos:media-worker:capabilities:v1';

    public function __construct(private readonly MediaWorkerClientInterface $worker)
    {
    }

    /**
     * The current report.
     *
     * @param bool $fresh Skip the cache and re-probe. Operators get this through
     *                    a "refresh" action; tests need it because a fake can
     *                    answer differently per call.
     */
    public function report(bool $fresh = false): MediaWorkerCapabilityReport
    {
        $ttl = $this->ttl();

        try {
            if ($fresh) {
                Cache::forget(self::CACHE_KEY);
            }

            $cached = Cache::get(self::CACHE_KEY);

            // `instanceof` rather than a shape check: a pre-upgrade entry or a
            // corrupt one is discarded, not dereferenced.
            if ($cached instanceof MediaWorkerCapabilityReport) {
                return $cached;
            }

            $report = $this->probe();

            Cache::put(self::CACHE_KEY, $report, $ttl);

            return $report;
        } catch (\Throwable) {
            // A cache outage must not take the diagnostics page with it. The
            // probe is cheap and its own failures are already absorbed; the
            // cache is an optimisation, not a dependency.
            return $this->probe();
        }
    }

    /**
     * The report as the serialisable shape diagnostics and the console read.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $fresh = false): array
    {
        return $this->report($fresh)->toArray();
    }

    /**
     * Ask the worker, without ever throwing.
     */
    private function probe(): MediaWorkerCapabilityReport
    {
        if (! $this->worker->isConfigured()) {
            return MediaWorkerCapabilityReport::unconfigured();
        }

        // `health()` never throws, so a configured worker that is down lands on
        // `unreachable` with whatever version it last reported rather than on a
        // 500 in the diagnostics page.
        $health = $this->worker->health();
        $version = isset($health['version']) && is_string($health['version'])
            ? $health['version']
            : null;

        $capabilities = $this->worker->capabilities();

        if ([] === $capabilities) {
            return MediaWorkerCapabilityReport::unreachable($version);
        }

        return MediaWorkerCapabilityReport::fromProbe(true, true, $version, $capabilities);
    }

    private function ttl(): int
    {
        return max(1, (int) config('oos.media_worker.capabilities_ttl', 300));
    }
}
