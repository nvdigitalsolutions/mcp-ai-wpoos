<?php

/**
 * The numeric bounds a scope places on a call.
 *
 * Proposal 167 sections 167.6.3 and 167.6.7. One object, merged from the
 * toolkit-wide defaults and the scope's overrides, so there is exactly one place
 * a cap can come from and one place to read it.
 *
 * `max_affected_rows` defaults to **0**, which means "writes are impossible".
 * That is a deliberate inversion: a scope must opt into writes explicitly, so a
 * configuration mistake produces a refusal rather than a mutation.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use InvalidArgumentException;

final readonly class DatabaseLimits
{
    /** Rows returned before `truncated: max_rows`. */
    public const DEFAULT_MAX_ROWS = 500;

    /** Shaped payload bytes before `truncated: max_bytes`. */
    public const DEFAULT_MAX_BYTES = 2097152;

    /** Server-side and PHP-side statement deadline. */
    public const DEFAULT_TIMEOUT_MS = 5000;

    /** Bytes per cell before `truncated: cell_cap`. */
    public const DEFAULT_CELL_BYTES = 16384;

    /** Largest permitted OFFSET before the cursor is required. */
    public const DEFAULT_OFFSET_MAX = 1000;

    /**
     * @param int $maxRows         Row cap.
     * @param int $maxBytes        Shaped payload cap.
     * @param int $timeoutMs       Statement deadline in milliseconds.
     * @param int $maxAffectedRows Write cap; 0 means writes are impossible.
     * @param int $cellBytes       Per-cell cap.
     * @param int $offsetMax       OFFSET ceiling before a cursor is required.
     */
    public function __construct(
        public int $maxRows = self::DEFAULT_MAX_ROWS,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public int $maxAffectedRows = 0,
        public int $cellBytes = self::DEFAULT_CELL_BYTES,
        public int $offsetMax = self::DEFAULT_OFFSET_MAX,
    ) {
        foreach (array(
            'maxRows' => $maxRows,
            'maxBytes' => $maxBytes,
            'timeoutMs' => $timeoutMs,
            'cellBytes' => $cellBytes,
            'offsetMax' => $offsetMax,
        ) as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be at least 1.', $name));
            }
        }

        if ($maxAffectedRows < 0) {
            throw new InvalidArgumentException('maxAffectedRows cannot be negative.');
        }
    }

    /**
     * Build from a stored settings array, ignoring unknown keys.
     *
     * Non-numeric values fall back to the default rather than casting to zero —
     * a corrupted settings row must not silently become "return no rows" or,
     * worse, "writes are unlimited".
     *
     * @param array<string, mixed> $values Raw values.
     */
    public static function fromArray(array $values): self
    {
        return new self(
            maxRows: self::intOrDefault($values, 'max_rows', self::DEFAULT_MAX_ROWS, 1),
            maxBytes: self::intOrDefault($values, 'max_bytes', self::DEFAULT_MAX_BYTES, 1),
            timeoutMs: self::intOrDefault($values, 'timeout_ms', self::DEFAULT_TIMEOUT_MS, 1),
            maxAffectedRows: self::intOrDefault($values, 'max_affected_rows', 0, 0),
            cellBytes: self::intOrDefault($values, 'cell_bytes', self::DEFAULT_CELL_BYTES, 1),
            offsetMax: self::intOrDefault($values, 'offset_max', self::DEFAULT_OFFSET_MAX, 1),
        );
    }

    /**
     * Merge a scope's limits over this set.
     *
     * Every field is taken from `$scope` when it differs from the default,
     * otherwise this set's value wins. The caller passes toolkit defaults as
     * `$this` and the scope's stored array as `$scope`, so a scope that omits a
     * key inherits rather than resets.
     *
     * @param array<string, mixed> $scope Scope overrides.
     */
    public function withScopeOverrides(array $scope): self
    {
        if (array() === $scope) {
            return $this;
        }

        return new self(
            maxRows: self::intOrDefault($scope, 'max_rows', $this->maxRows, 1),
            maxBytes: self::intOrDefault($scope, 'max_bytes', $this->maxBytes, 1),
            timeoutMs: self::intOrDefault($scope, 'timeout_ms', $this->timeoutMs, 1),
            maxAffectedRows: self::intOrDefault($scope, 'max_affected_rows', $this->maxAffectedRows, 0),
            cellBytes: self::intOrDefault($scope, 'cell_bytes', $this->cellBytes, 1),
            offsetMax: self::intOrDefault($scope, 'offset_max', $this->offsetMax, 1),
        );
    }

    /**
     * The effective row cap for a request with an explicit `limit`.
     *
     * A caller may ask for fewer rows than the scope allows; it may never ask
     * for more.
     */
    public function effectiveRowLimit(?int $requested): int
    {
        if (null === $requested || $requested < 1) {
            return $this->maxRows;
        }

        return min($requested, $this->maxRows);
    }

    /**
     * Whether the scope permits any write at all.
     */
    public function writesPossible(): bool
    {
        return $this->maxAffectedRows > 0;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function intOrDefault(array $values, string $key, int $default, int $minimum): int
    {
        if (! array_key_exists($key, $values)) {
            return $default;
        }

        $value = $values[$key];

        if (is_bool($value)) {
            return $default;
        }

        if (! is_int($value) && ! (is_string($value) && '' !== trim($value) && is_numeric($value))) {
            return $default;
        }

        $resolved = (int) $value;

        return $resolved < $minimum ? $default : $resolved;
    }
}
