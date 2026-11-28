<?php

/**
 * What the introspector was allowed to see.
 *
 * Proposal 167 section 167.6.4 (tier `metadata`) and D7. This object is built by
 * the host adapter from `information_schema`/`pg_catalog`, then **filtered to the
 * scope** before it reaches the grammar or a tool. System schemas are never
 * emitted, so `db_describe` cannot be used to enumerate `pg_authid`,
 * `pg_shadow` or any other catalog the scope did not name.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class CatalogueDescriptor
{
    /**
     * @param array<string, TableDescriptor> $tables Relations keyed by `schema.name`.
     */
    public function __construct(
        public array $tables = array(),
    ) {
    }

    public function has(string $schema, string $table): bool
    {
        return isset($this->tables[self::key($schema, $table)]);
    }

    public function table(string $schema, string $table): ?TableDescriptor
    {
        return $this->tables[self::key($schema, $table)] ?? null;
    }

    /**
     * Relations in one schema, sorted by name, for stable output.
     *
     * @return list<TableDescriptor>
     */
    public function inSchema(string $schema): array
    {
        $schema = strtolower($schema);
        $tables = array();

        foreach ($this->tables as $table) {
            if (strtolower($table->schema) === $schema) {
                $tables[] = $table;
            }
        }

        usort($tables, static fn (TableDescriptor $a, TableDescriptor $b): int => strcmp($a->name, $b->name));

        return $tables;
    }

    /**
     * Restrict the catalogue to a scope's allowlist.
     *
     * Called once, on the way out of the adapter, so nothing downstream has to
     * re-check whether a relation is visible.
     */
    public function only(ScopeDescriptor $scope): self
    {
        $kept = array();

        foreach ($this->tables as $key => $table) {
            if ($scope->allowsTable($table)) {
                $kept[$key] = $table;
            }
        }

        return new self($kept);
    }

    public function isEmpty(): bool
    {
        return array() === $this->tables;
    }

    private static function key(string $schema, string $table): string
    {
        return strtolower($schema) . '.' . strtolower($table);
    }
}
