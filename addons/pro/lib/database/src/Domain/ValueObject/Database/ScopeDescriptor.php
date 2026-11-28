<?php

/**
 * A grant: which connection, which relations, which columns, which tiers.
 *
 * Proposal 167 sections 167.6.3 and 167.6.4. This is the object the whole toolkit
 * exists to introduce. A *connection* is reused infrastructure; a *scope* is the
 * new thing, and it is deliberately stored separately so one connection can serve
 * several grants — a read-only analyst scope and a `raw_sql` scope on the same
 * warehouse are different records with different principals.
 *
 * ## Fail-closed defaults
 *
 *  - `schemas` may not contain `*`. A wildcard schema is the whole database.
 *  - `principals` empty means **nobody**. An unbound scope is inert, not public.
 *  - `tables` empty means **nothing**, even if the schema is allowlisted.
 *  - A tier is opt-in; absence denies.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class ScopeDescriptor
{
    /**
     * @param string                          $id         Stable slug.
     * @param string                          $label      Operator-facing name.
     * @param string                          $connection Connection id this scope grants.
     * @param list<string>                    $schemas    Allowed schemas; `*` is refused.
     * @param list<string>                    $tables     Allowed `schema.table` values, or `*` within the schemas.
     * @param array<string, array<string, list<string>>> $columns Per-relation column rules.
     * @param list<RowFilterRule>             $rowFilter  Mandatory predicates.
     * @param list<GrantTier>                 $tiers      Granted capability tiers.
     * @param array<string, mixed>            $limits     Raw limit overrides, applied over toolkit defaults.
     * @param list<PrincipalRef>              $principals Who may use it; empty means nobody.
     * @param int                             $version    Bumped on every change; recorded in the audit row.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $connection,
        public array $schemas,
        public array $tables,
        public array $columns = array(),
        public array $rowFilter = array(),
        public array $tiers = array(),
        public array $limits = array(),
        public array $principals = array(),
        public int $version = 1,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            throw DatabaseRefusal::of(RefusalCode::SCOPE_NOT_ALLOWED, 'Invalid scope id.');
        }

        if ('' === trim($connection)) {
            throw DatabaseRefusal::of(RefusalCode::SCOPE_NOT_ALLOWED, 'Scope has no connection.');
        }

        if (array() === $schemas) {
            throw DatabaseRefusal::of(RefusalCode::SCOPE_NOT_ALLOWED, 'Scope has no allowed schema.');
        }

        foreach ($schemas as $schema) {
            if ('*' === $schema || '' === trim($schema)) {
                throw DatabaseRefusal::of(
                    RefusalCode::SCOPE_NOT_ALLOWED,
                    'A wildcard or empty schema is not permitted; name the schemas.'
                );
            }
        }

        if ($version < 1) {
            throw DatabaseRefusal::of(RefusalCode::SCOPE_NOT_ALLOWED, 'Scope version must be at least 1.');
        }
    }

    /**
     * Hydrate from a stored record.
     *
     * @param array<string, mixed> $record Stored scope.
     */
    public static function fromArray(array $record): self
    {
        $tiers = GrantTier::listFrom(is_array($record['tiers'] ?? null) ? $record['tiers'] : array());

        $tables = array();
        foreach ((array) ($record['tables'] ?? array()) as $table) {
            if (is_string($table) && '' !== trim($table)) {
                $tables[] = strtolower(trim($table));
            }
        }

        $schemas = array();
        foreach ((array) ($record['schemas'] ?? array()) as $schema) {
            if (is_string($schema) && '' !== trim($schema)) {
                $schemas[] = strtolower(trim($schema));
            }
        }

        $rowFilter = array();
        foreach ((array) ($record['row_filter'] ?? array()) as $rule) {
            if (is_array($rule)) {
                $rowFilter[] = RowFilterRule::fromArray($rule);
            }
        }

        $principals = array();
        foreach ((array) ($record['principals'] ?? array()) as $principal) {
            if (! is_string($principal)) {
                continue;
            }

            $resolved = PrincipalRef::tryFromString($principal);

            if (null !== $resolved) {
                $principals[] = $resolved;
            }
        }

        return new self(
            id: strtolower(trim((string) ($record['id'] ?? ''))),
            label: (string) ($record['label'] ?? $record['name'] ?? ($record['id'] ?? '')),
            connection: strtolower(trim((string) ($record['connection'] ?? ''))),
            schemas: $schemas,
            tables: $tables,
            columns: is_array($record['columns'] ?? null) ? self::normaliseColumns($record['columns']) : array(),
            rowFilter: $rowFilter,
            tiers: $tiers,
            limits: is_array($record['limits'] ?? null) ? $record['limits'] : array(),
            principals: $principals,
            version: isset($record['version']) ? max(1, (int) $record['version']) : 1,
        );
    }

    public function hasTier(GrantTier $tier): bool
    {
        return in_array($tier, $this->tiers, true);
    }

    /**
     * Whether a principal may use this scope.
     */
    public function allowsPrincipal(PrincipalRef $principal): bool
    {
        foreach ($this->principals as $bound) {
            if ($bound->equals($principal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a relation is inside the allowlist.
     */
    public function allowsTable(TableDescriptor $table): bool
    {
        $schema = strtolower($table->schema);

        if (! in_array($schema, $this->schemas, true)) {
            return false;
        }

        if (array() === $this->tables) {
            return false;
        }

        if (in_array('*', $this->tables, true)) {
            return true;
        }

        return in_array(strtolower($table->key()), $this->tables, true);
    }

    /**
     * Whether a column may be projected.
     */
    public function allowsColumn(TableDescriptor $table, string $column): bool
    {
        if (! $this->allowsTable($table) || ! $table->hasColumn($column)) {
            return false;
        }

        return ! in_array(strtolower($column), $this->excludedColumns($table), true);
    }

    /**
     * Columns removed from the projection entirely.
     *
     * @return list<string>
     */
    public function excludedColumns(TableDescriptor $table): array
    {
        return $this->columnRule($table, 'exclude');
    }

    /**
     * Columns that must be masked unless the scope holds `read_unmasked`.
     *
     * @return list<string>
     */
    public function sensitiveColumns(TableDescriptor $table): array
    {
        return $this->columnRule($table, 'sensitive');
    }

    /**
     * Whether a column must be masked for this scope.
     */
    public function isSensitive(TableDescriptor $table, string $column): bool
    {
        if ($this->hasTier(GrantTier::READ_UNMASKED)) {
            return false;
        }

        return in_array(strtolower($column), $this->sensitiveColumns($table), true);
    }

    /**
     * Columns `db_search` may match against.
     *
     * Uses the declared list when present; otherwise falls back to text-like
     * columns, which is the only sensible default for a search. A relation with
     * neither yields an empty list and the tool refuses.
     *
     * @return list<string>
     */
    public function searchableColumns(TableDescriptor $table): array
    {
        $declared = $this->columnRule($table, 'searchable');

        if (array() !== $declared) {
            // A declared column may still be excluded or outside the projection;
            // filter so a config error cannot widen the search surface.
            return array_values(array_filter(
                $declared,
                fn (string $name): bool => $this->allowsColumn($table, $name)
            ));
        }

        $textual = array();

        foreach ($table->columns as $name => $column) {
            $type = strtolower($column->type);

            if ((str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'clob'))
                && $this->allowsColumn($table, $name)
            ) {
                $textual[] = $name;
            }
        }

        return $textual;
    }

    /**
     * Row-filter rules that apply to a relation.
     *
     * @return list<RowFilterRule>
     */
    public function rowFilterFor(TableDescriptor $table): array
    {
        $applicable = array();

        foreach ($this->rowFilter as $rule) {
            if ($rule->appliesTo($table->key())) {
                $applicable[] = $rule;
            }
        }

        return $applicable;
    }

    /**
     * Effective limits, merged over a toolkit default set.
     */
    public function limitsOver(DatabaseLimits $defaults): DatabaseLimits
    {
        return $defaults->withScopeOverrides($this->limits);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'label' => $this->label,
            'connection' => $this->connection,
            'schemas' => $this->schemas,
            'tables' => $this->tables,
            'columns' => $this->columns,
            'row_filter' => array_map(
                static fn (RowFilterRule $rule): array => array(
                    'column' => $rule->column,
                    'op' => $rule->operator->value,
                    'value_from' => $rule->valueFrom,
                    'value' => $rule->value,
                    'relation' => $rule->relation,
                ),
                $this->rowFilter
            ),
            'tiers' => array_map(static fn (GrantTier $tier): string => $tier->value, $this->tiers),
            'limits' => $this->limits,
            'principals' => array_map(static fn (PrincipalRef $p): string => $p->key(), $this->principals),
            'version' => $this->version,
        );
    }

    /**
     * @return list<string>
     */
    private function columnRule(TableDescriptor $table, string $rule): array
    {
        $entry = $this->columns[strtolower($table->key())] ?? array();

        if (! isset($entry[$rule]) || ! is_array($entry[$rule])) {
            return array();
        }

        $values = array();

        foreach ($entry[$rule] as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $values[] = strtolower(trim($value));
            }
        }

        return $values;
    }

    /**
     * Normalise the per-relation column map to lowercase keys and string lists.
     *
     * @param array<string, mixed> $columns Raw map.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function normaliseColumns(array $columns): array
    {
        $normalised = array();

        foreach ($columns as $relation => $rules) {
            if (! is_string($relation) || ! is_array($rules)) {
                continue;
            }

            $entry = array();

            foreach (array( 'exclude', 'sensitive', 'searchable' ) as $rule) {
                if (! isset($rules[$rule]) || ! is_array($rules[$rule])) {
                    continue;
                }

                $values = array();

                foreach ($rules[$rule] as $value) {
                    if (is_string($value) && '' !== trim($value)) {
                        $values[] = strtolower(trim($value));
                    }
                }

                if (array() !== $values) {
                    $entry[$rule] = $values;
                }
            }

            if (array() !== $entry) {
                $normalised[strtolower($relation)] = $entry;
            }
        }

        return $normalised;
    }
}
