<?php

/**
 * One relation as the catalogue reports it.
 *
 * Proposal 167. The catalogue is the allowlist's ground truth: the grammar
 * resolves every identifier against this object, so a name that is not here
 * cannot be emitted as SQL at all. That is the structural answer to identifier
 * injection — not escaping, but resolution.
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

final readonly class TableDescriptor
{
    /**
     * @param string                              $schema    Schema name.
     * @param string                              $name      Relation name.
     * @param array<string, ColumnDescriptor>     $columns   Columns keyed by lowercase name.
     * @param list<string>                        $primaryKey Column names, in key order.
     * @param bool                                $isView    Whether the relation is a view.
     */
    public function __construct(
        public string $schema,
        public string $name,
        public array $columns,
        public array $primaryKey = array(),
        public bool $isView = false,
    ) {
        foreach ($columns as $key => $column) {
            if (! $column instanceof ColumnDescriptor) {
                throw new InvalidArgumentException('Column map must contain ColumnDescriptor values.');
            }

            if (strtolower($column->name) !== $key) {
                throw new InvalidArgumentException('Column map must be keyed by the lowercased column name.');
            }
        }
    }

    /**
     * The `schema.name` key used throughout the catalogue and the scope.
     */
    public function key(): string
    {
        return $this->schema . '.' . $this->name;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[strtolower($name)]);
    }

    public function column(string $name): ?ColumnDescriptor
    {
        return $this->columns[strtolower($name)] ?? null;
    }

    /**
     * Whether the relation can be keyset-paginated without an extra sort key.
     */
    public function hasStableKey(): bool
    {
        return array() !== $this->primaryKey;
    }
}
