<?php

/**
 * One column as the catalogue reports it.
 *
 * Proposal 167 section 167.6.7. The type matters to the shaper for two reasons:
 * a numeric column must come back as a **string** (float casting silently loses
 * precision past 15–17 significant digits), and a temporal column must be
 * rendered ISO-8601 with an offset rather than whatever the driver happened to
 * produce.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class ColumnDescriptor
{
    /**
     * Type names that must be returned as strings, not floats.
     *
     * Matched on the lowercased type's leading word, so `numeric(12,2)` and
     * `double precision` both resolve.
     *
     * @var list<string>
     */
    private const NUMERIC_TYPES = array(
        'numeric',
        'decimal',
        'dec',
        'money',
        'smallint',
        'integer',
        'int',
        'bigint',
        'int2',
        'int4',
        'int8',
        'real',
        'float',
        'float4',
        'float8',
        'double',
        'serial',
        'bigserial',
    );

    /**
     * Type names treated as temporal.
     *
     * @var list<string>
     */
    private const TEMPORAL_TYPES = array(
        'date',
        'timestamp',
        'timestamptz',
        'datetime',
        'time',
    );

    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = true,
    ) {
    }

    /**
     * Whether the column is numeric and must be stringified on output.
     */
    public function isNumeric(): bool
    {
        return in_array($this->typeWord(), self::NUMERIC_TYPES, true);
    }

    /**
     * Whether the column is temporal.
     */
    public function isTemporal(): bool
    {
        return in_array($this->typeWord(), self::TEMPORAL_TYPES, true);
    }

    /**
     * The leading word of the type, lowercased and stripped of length/precision.
     */
    private function typeWord(): string
    {
        $type = strtolower(trim($this->type));
        $type = preg_replace('/\(.*$/', '', $type) ?? $type;
        $type = preg_replace('/\s+(without|with)\s+time\s+zone$/', '', $type) ?? $type;

        return trim($type);
    }
}
