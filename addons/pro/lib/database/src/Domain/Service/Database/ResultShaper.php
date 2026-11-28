<?php

/**
 * Cell caps, byte accounting and type fidelity.
 *
 * Proposal 167 section 167.6.7. An unbounded `SELECT` is a denial of service
 * against the context window before it is anything else, so shaping is part of
 * the contract rather than a rendering detail. Three properties are enforced
 * here:
 *
 *  1. a cell larger than the cap is truncated with an explicit marker;
 *  2. binary or non-UTF-8 data never reaches JSON (it would break the encoder and
 *     the model's tokeniser);
 *  3. integers stay integers, but a float derived from a `numeric`/`decimal`
 *     column becomes a string, because a float cannot represent it exactly.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class ResultShaper
{
    /** Suffix appended to a truncated cell. */
    public const TRUNCATION_MARKER = '…[truncated]';

    /** Replacement for a non-UTF-8 value. */
    public const BINARY_MARKER = '[binary]';

    /**
     * Shape one row for the response envelope.
     *
     * @param array<string, mixed> $row            Raw driver row.
     * @param TableDescriptor      $table          Relation, for column types.
     * @param DatabaseLimits       $limits         Caps.
     * @param bool                 $cellTruncated  Set to true when any cell was truncated.
     *
     * @return array<string, mixed>
     */
    public function shapeRow(array $row, TableDescriptor $table, DatabaseLimits $limits, bool &$cellTruncated): array
    {
        $shaped = array();

        foreach ($row as $name => $value) {
            $column = $table->column((string) $name);

            $shaped[$name] = $this->shapeValue($value, $column?->isNumeric() ?? false, $limits, $cellTruncated);
        }

        return $shaped;
    }

    /**
     * Approximate the serialised size of a row.
     *
     * Deliberately an estimate: exact JSON length would require encoding twice,
     * and the cap only needs to be monotone with the real size.
     *
     * @param array<string, mixed> $row Shaped row.
     */
    public function rowBytes(array $row): int
    {
        $bytes = 2; // Braces.

        foreach ($row as $key => $value) {
            $bytes += strlen((string) $key) + 4;

            $bytes += match (true) {
                null === $value => 4,
                is_bool($value) => 5,
                is_int($value), is_float($value) => strlen((string) $value),
                is_array($value) => strlen((string) json_encode($value)),
                default => strlen((string) $value),
            };
        }

        return $bytes;
    }

    /**
     * Shape one value.
     *
     * @param bool $numeric Whether the column is numeric.
     */
    private function shapeValue(mixed $value, bool $numeric, DatabaseLimits $limits, bool &$cellTruncated): mixed
    {
        if (null === $value) {
            return null;
        }

        if (is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // A float from a numeric column has already lost precision at the
            // driver boundary; stringifying at least avoids a second loss in JSON.
            return $numeric ? (string) $value : $value;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value);

            if (false === $encoded) {
                $cellTruncated = true;

                return self::BINARY_MARKER;
            }

            return $this->capString($encoded, $limits, $cellTruncated);
        }

        $string = (string) $value;

        if ('' !== $string && ! mb_check_encoding($string, 'UTF-8')) {
            $cellTruncated = true;

            return self::BINARY_MARKER;
        }

        return $this->capString($string, $limits, $cellTruncated);
    }

    /**
     * Truncate a string to the cell cap.
     */
    private function capString(string $value, DatabaseLimits $limits, bool &$cellTruncated): string
    {
        if (strlen($value) <= $limits->cellBytes) {
            return $value;
        }

        $cellTruncated = true;

        $keep = max(0, $limits->cellBytes - strlen(self::TRUNCATION_MARKER));

        return substr($value, 0, $keep) . self::TRUNCATION_MARKER;
    }
}
