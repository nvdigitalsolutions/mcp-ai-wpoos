<?php

/**
 * Where the F&B tools read their tables from.
 *
 * The WordPress toolkit resolves a spec table through a four-level source
 * cascade (per-table override → `table_map` → toolkit default Drive connection
 * → the only enabled connection) and can also read the five transactional
 * tables from WordPress CPTs. This runtime has neither a Drive client nor an
 * F&B post type, so the port keeps the part that carries meaning — a per-table
 * file location — and drops the rest rather than pretending to route:
 *
 * ```
 * wp_mcp_ai_fnb_toolkit_settings = array(
 *     'as_of_date' => '2026-06-30',
 *     'table_map'  => array(
 *         '3.0' => array( 'path' => '/srv/fnb/ingredients.csv' ),
 *         '6.0' => array( 'path' => '/srv/fnb/sales.xlsx', 'sheet' => 'Daily sales' ),
 *     ),
 * )
 * ```
 *
 * Parsing is the host's: `TabularReaderInterface` is already bound in the
 * Laravel adapter to the media worker, which reads `xlsx`, `csv`, `tsv` and
 * `json`. This class only maps a table id to a path, calls the reader, and adds
 * the header index the metric engine needs.
 *
 * ## What is deliberately absent
 *
 * - **`connection_id`.** With one source per host there is nothing to select, so
 *   the argument is gone rather than accepted-and-ignored.
 * - **`fnb_native_cpt`.** WordPress can back five tables with posts; this
 *   runtime's answer to "several sources" is a host that points `table_map` at
 *   wherever it likes.
 * - **The native-storage hint.** It exists to tell an operator to flip a
 *   WordPress setting that has no counterpart here.
 *
 * The `source` key is kept and always reads `fnb_data_source`, so a consumer
 * that branches on it does not have to distinguish "absent" from "changed".
 *
 * ## Caching
 *
 * WordPress caches a resolved table for five minutes in a transient. The port
 * does the same through an optional `CacheStoreInterface`, keyed by the table
 * and its path, so a host without a cache pays a file read per call and the
 * payload still says where the rows came from.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;

final class FnbTableSource
{
    /** The WordPress option the toolkit settings live in. */
    public const SETTINGS_OPTION = 'wp_mcp_ai_fnb_toolkit_settings';

    /** The cache lifetime WordPress uses. */
    public const CACHE_TTL = 300;

    /** The cache-key prefix WordPress uses. */
    public const CACHE_PREFIX = 'fnb_table_';

    /** The only `source` value this runtime produces. */
    public const SOURCE = 'fnb_data_source';

    public function __construct(
        private readonly ErrorFactoryInterface $errors,
        private readonly ?TabularReaderInterface $reader = null,
        private readonly ?SettingsStoreInterface $settings = null,
        private readonly ?CacheStoreInterface $cache = null,
    ) {
    }

    /**
     * Read one spec table.
     *
     * @return array{table_id: string, label: string, columns: list<string>, rows: list<array<int, mixed>>, count: int, source: string, header_index: list<string>}|mixed
     *         The table, or the platform's error object.
     */
    public function getTable(string $tableId): mixed
    {
        if (! FnbTableCatalogue::has($tableId)) {
            return $this->errors->create(
                'unknown_table',
                sprintf('Unknown F&B data table "%s".', $tableId)
            );
        }

        $entry = $this->entry($tableId);

        if (null === $entry) {
            return $this->errors->create(
                'table_not_configured',
                sprintf(
                    'F&B table "%s" has no source. Map it under "%s" → table_map with a path the host can read.',
                    $tableId,
                    self::SETTINGS_OPTION
                ),
                array('table_id' => $tableId)
            );
        }

        $cacheKey = self::CACHE_PREFIX . md5($tableId . ':' . $entry['path'] . '|' . $entry['sheet'] . '|' . $entry['format']);

        $cached = $this->cacheGet($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        if (null === $this->reader) {
            return $this->sourceUnavailable();
        }

        if (! $this->reader->isAvailable()) {
            return $this->sourceUnavailable();
        }

        $options = array('max_rows' => 0);

        if ('' !== $entry['sheet']) {
            $options['sheet'] = $entry['sheet'];
        }

        if ('' !== $entry['format']) {
            $options['format'] = $entry['format'];
        }

        try {
            $matrix = $this->reader->read($entry['path'], $options);
        } catch (\Throwable $e) {
            return $this->errors->create(
                'table_unreadable',
                sprintf('F&B table "%s" could not be read: %s', $tableId, $e->getMessage()),
                array('table_id' => $tableId, 'path' => $entry['path'])
            );
        }

        $columns = isset($matrix['columns']) && is_array($matrix['columns']) ? array_values($matrix['columns']) : array();
        $rows    = isset($matrix['rows']) && is_array($matrix['rows']) ? array_values($matrix['rows']) : array();

        if (array() === $columns) {
            return $this->errors->create(
                'table_unreadable',
                sprintf('F&B table "%s" has no header row, so no canonical column can resolve.', $tableId),
                array('table_id' => $tableId, 'path' => $entry['path'])
            );
        }

        $headerIndex = array();

        foreach ($columns as $column) {
            $headerIndex[] = FnbTableCatalogue::normaliseHeader(is_scalar($column) ? (string) $column : '');
        }

        $table = array(
            'table_id'     => $tableId,
            'label'        => FnbTableCatalogue::label($tableId),
            'columns'      => array_map(static fn (mixed $column): string => is_scalar($column) ? (string) $column : '', $columns),
            'rows'         => $rows,
            'count'        => count($rows),
            'source'       => self::SOURCE,
            'header_index' => $headerIndex,
        );

        $this->cacheSet($cacheKey, $table);

        return $table;
    }

    /**
     * The configured location for one table, or null when unmapped.
     *
     * @return array{path: string, sheet: string, format: string}|null
     */
    private function entry(string $tableId): ?array
    {
        $stored = null !== $this->settings
            ? $this->settings->get(self::SETTINGS_OPTION, array())
            : array();

        if (! is_array($stored)) {
            return null;
        }

        $map = $stored['table_map'] ?? array();

        if (! is_array($map) || ! isset($map[ $tableId ]) || ! is_array($map[ $tableId ])) {
            return null;
        }

        $entry  = $map[ $tableId ];
        $path   = is_string($entry['path'] ?? null) ? trim($entry['path']) : '';

        if ('' === $path) {
            return null;
        }

        return array(
            'path'   => $path,
            'sheet'  => is_string($entry['sheet'] ?? null) ? trim($entry['sheet']) : '',
            'format' => is_string($entry['format'] ?? null) ? strtolower(trim($entry['format'])) : '',
        );
    }

    private function sourceUnavailable(): mixed
    {
        return $this->errors->create(
            'table_source_unavailable',
            'No tabular reader is bound on this host, so F&B tables cannot be read.'
        );
    }

    private function cacheGet(string $key): mixed
    {
        return null !== $this->cache ? $this->cache->getValue($key, false) : false;
    }

    /**
     * @param array<string, mixed> $table Resolved table.
     */
    private function cacheSet(string $key, array $table): void
    {
        if (null !== $this->cache) {
            $this->cache->setValue($key, $table, self::CACHE_TTL);
        }
    }
}
