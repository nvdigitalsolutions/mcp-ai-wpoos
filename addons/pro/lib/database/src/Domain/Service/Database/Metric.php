<?php

/**
 * The closed metric vocabulary for `db_aggregate`.
 *
 * Proposal 167 section 167.6.6. Percentiles exist only where the engine can
 * express them honestly: PostgreSQL has `percentile_cont`, MySQL and MariaDB do
 * not without a window-function rewrite that changes the semantics for ties.
 * The toolkit refuses rather than approximating, because a "median" that is
 * really an interpolation over a partial window is worse than no answer.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

enum Metric: string
{
    case COUNT = 'count';
    case COUNT_DISTINCT = 'count_distinct';
    case SUM = 'sum';
    case AVG = 'avg';
    case MIN = 'min';
    case MAX = 'max';
    case MEDIAN = 'median';
    case P90 = 'p90';

    /**
     * Whether the metric requires a column.
     *
     * `count(*)` is the only column-free metric; asking for `sum()` with no
     * column is a schema error the grammar reports as `UNSUPPORTED_METRIC`.
     */
    public function requiresColumn(): bool
    {
        return self::COUNT !== $this;
    }

    /**
     * Whether the engine can compute the metric.
     *
     * @param string $engine One of pgsql, mysql, mariadb, sqlite.
     */
    public function isSupportedBy(string $engine): bool
    {
        return match ($this) {
            self::MEDIAN, self::P90 => 'pgsql' === $engine,
            default => true,
        };
    }

    /**
     * The percentile fraction for the two percentile metrics.
     */
    public function percentile(): ?float
    {
        return match ($this) {
            self::MEDIAN => 0.5,
            self::P90 => 0.9,
            default => null,
        };
    }
}
