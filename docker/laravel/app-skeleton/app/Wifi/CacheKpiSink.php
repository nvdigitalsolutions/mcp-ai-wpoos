<?php

namespace App\Wifi;

use Illuminate\Contracts\Cache\Repository;
use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Window;
use NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink;

/**
 * Cache-backed KPI sample store for the Laravel host (Proposal 150, T-0.10).
 *
 * Mirrors the WordPress implementation's semantics exactly, because the two are
 * compared by the same parity test:
 *
 *  - writes are idempotent per (key, at, source_adapter), so a retried poll
 *    cannot double-count a counter KPI;
 *  - the retained set per venue is capped;
 *  - reads skip rows that no longer validate against the KPI schema rather than
 *    failing the whole read.
 *
 * Phase 0 uses the cache rather than a table so the port can be exercised
 * without a migration. Phase 2 replaces it with a real store once the KPI
 * vocabulary is frozen.
 */
final class CacheKpiSink implements Interface_WiFi_Kpi_Sink
{
    /**
     * Retained per venue. Long enough for a day of 5-minute samples.
     */
    private const MAX_SAMPLES = 500;

    /**
     * Cache TTL in seconds (24h).
     */
    private const TTL_SECONDS = 86400;

    public function __construct(private Repository $cache) {}

    /**
     * {@inheritdoc}
     */
    public function write(array $samples): Result
    {
        if ([] === $samples) {
            return Result::ok(['written' => 0]);
        }

        /** @var array<string, array<int, Kpi_Sample>> $grouped */
        $grouped = [];

        foreach ($samples as $sample) {
            if (! $sample instanceof Kpi_Sample) {
                return Result::fail('invalid_sample', 'The KPI sink only accepts Kpi_Sample instances.');
            }

            $grouped[$sample->venue_id()][] = $sample;
        }

        $written = 0;

        foreach ($grouped as $venueId => $batch) {
            $stored = $this->load((string) $venueId);
            $index = [];

            foreach ($stored as $row) {
                $index[$this->fingerprint($row)] = true;
            }

            foreach ($batch as $sample) {
                $row = $sample->to_array();
                $key = $this->fingerprint($row);

                if (isset($index[$key])) {
                    continue;
                }

                $stored[] = $row;
                $index[$key] = true;
                $written++;
            }

            if (count($stored) > self::MAX_SAMPLES) {
                $stored = array_slice($stored, -self::MAX_SAMPLES);
            }

            $this->cache->put($this->cacheKey((string) $venueId), $stored, self::TTL_SECONDS);
        }

        return Result::ok(['written' => $written]);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $venue_id, Window $window): Result
    {
        $samples = [];

        foreach ($this->load($venue_id) as $row) {
            if (! is_array($row) || ! isset($row['key'], $row['at'], $row['venue_id'], $row['source_adapter'])) {
                continue;
            }

            if (! $window->contains((int) $row['at'])) {
                continue;
            }

            $sample = Kpi_Sample::make(
                (string) $row['key'],
                $row['value'] ?? null,
                (int) $row['at'],
                (string) $row['venue_id'],
                isset($row['method']) && is_string($row['method']) ? $row['method'] : null,
                (string) $row['source_adapter'],
            );

            if ($sample->is_ok()) {
                $samples[] = $sample->value();
            }
        }

        usort(
            $samples,
            static fn (Kpi_Sample $a, Kpi_Sample $b): int => $a->at() === $b->at()
                ? strcmp($a->key(), $b->key())
                : $a->at() <=> $b->at(),
        );

        return Result::ok($samples);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function load(string $venueId): array
    {
        $stored = $this->cache->get($this->cacheKey($venueId), []);

        return is_array($stored) ? $stored : [];
    }

    private function cacheKey(string $venueId): string
    {
        return 'nvoos.wifi.kpi.'.sha1($venueId);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fingerprint(array $row): string
    {
        return sprintf(
            '%s|%s|%s',
            isset($row['key']) ? (string) $row['key'] : '',
            isset($row['at']) ? (string) $row['at'] : '',
            isset($row['source_adapter']) ? (string) $row['source_adapter'] : '',
        );
    }
}
