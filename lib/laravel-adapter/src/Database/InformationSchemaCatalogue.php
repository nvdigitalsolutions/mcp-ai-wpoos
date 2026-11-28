<?php

/**
 * Catalogue built from `information_schema`.
 *
 * Proposal 167 decision D7. The catalogue is the grammar's identifier source, so
 * "what may be named" comes from here and nowhere else. It is built from the
 * union of the scope's schemas, and the executor filters it per scope afterwards
 * — this class never decides what a principal may see.
 *
 * `information_schema` is used rather than `Schema::getTables()` because the
 * same three queries answer for PostgreSQL and MySQL/MariaDB, and because a
 * `PdoDatabaseConnection` may point at an external DSN that no Eloquent
 * connection describes. Fewer code paths means fewer places to drift.
 *
 * Placeholders are generated for the schema list; the names themselves are
 * never interpolated.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Nvoos\Database\Domain\Contract\Database\CatalogueInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use PDO;

final class InformationSchemaCatalogue implements CatalogueInterface
{
    public function __construct(
        private readonly ConnectionFactoryInterface $factory,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function load(ConnectionDescriptor $connection, array $schemas = array()): CatalogueDescriptor
    {
        $schemas = array_values(array_unique(array_filter(
            array_map(static fn (string $schema): string => strtolower(trim($schema)), $schemas),
            static fn (string $schema): bool => '' !== $schema && '*' !== $schema,
        )));

        if (array() === $schemas) {
            // Fail closed: no schema allowlist means no relations.
            return new CatalogueDescriptor();
        }

        $pdo     = $this->factory->open($connection);
        $holders = implode(', ', array_fill(0, count($schemas), '?'));

        $relations = $this->fetch(
            $pdo,
            'SELECT table_schema, table_name, table_type
               FROM information_schema.tables
              WHERE table_schema IN (' . $holders . ')',
            $schemas,
        );

        $columns = $this->fetch(
            $pdo,
            'SELECT table_schema, table_name, column_name, data_type, is_nullable
               FROM information_schema.columns
              WHERE table_schema IN (' . $holders . ')
              ORDER BY ordinal_position',
            $schemas,
        );

        $primary = $this->fetch(
            $pdo,
            "SELECT tc.table_schema, tc.table_name, kcu.column_name
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_name = tc.constraint_name
                AND kcu.constraint_schema = tc.constraint_schema
                AND kcu.table_name = tc.table_name
              WHERE tc.constraint_type = 'PRIMARY KEY'
                AND tc.table_schema IN (" . $holders . ')',
            $schemas,
        );

        $pdo = null;

        $columnMap = array();
        $keyMap    = array();

        foreach ($columns as $row) {
            $key = strtolower($row['table_schema'] . '.' . $row['table_name']);

            $columnMap[$key][strtolower((string) $row['column_name'])] = new ColumnDescriptor(
                name: strtolower((string) $row['column_name']),
                type: strtolower((string) $row['data_type']),
                nullable: 'YES' === strtoupper((string) $row['is_nullable']),
            );
        }

        foreach ($primary as $row) {
            $key               = strtolower($row['table_schema'] . '.' . $row['table_name']);
            $keyMap[$key][]    = strtolower((string) $row['column_name']);
        }

        $tables = array();

        foreach ($relations as $row) {
            $schema = strtolower((string) $row['table_schema']);
            $name   = strtolower((string) $row['table_name']);
            $key    = $schema . '.' . $name;

            $tables[$key] = new TableDescriptor(
                schema: $schema,
                name: $name,
                columns: $columnMap[$key] ?? array(),
                primaryKey: $keyMap[$key] ?? array(),
                isView: 'VIEW' === strtoupper((string) $row['table_type']),
            );
        }

        return new CatalogueDescriptor($tables);
    }

    /**
     * Run one introspection query.
     *
     * @param list<string> $bindings Bound schema names.
     * @return list<array<string, mixed>>
     */
    private function fetch(PDO $pdo, string $sql, array $bindings): array
    {
        $statement = $pdo->prepare($sql);

        if (false === $statement) {
            return array();
        }

        $statement->execute($bindings);

        $rows = array();

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }

        $statement->closeCursor();

        return $rows;
    }
}
