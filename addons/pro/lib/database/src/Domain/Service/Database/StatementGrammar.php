<?php

/**
 * The only place SQL syntax is produced.
 *
 * Proposal 167 sections 167.6.6 and 167.4.1. Two rules make this class the
 * structural answer to injection rather than a string-escaping exercise:
 *
 *  1. **Every value is a placeholder.** The compiled statement contains `:pN`
 *     tokens and a binding map; a caller's value never appears in the SQL text.
 *  2. **Every identifier is resolved from the catalogue.** `ScopePolicy` returns
 *     the stored name or refuses; this class quotes that name. A caller cannot
 *     introduce an identifier the catalogue does not know, so `users; DROP …`
 *     never reaches the database as syntax — it fails as an unknown relation.
 *
 * ## Dialect handling
 *
 * The output is deliberately conservative so one grammar serves PostgreSQL,
 * MySQL, MariaDB and SQLite:
 *
 *  - identifiers are `"quoted"` everywhere (MySQL accepts this under
 *    `ANSI_QUOTES`, but not by default — so MySQL and MariaDB get backticks);
 *  - `LIMIT n` rather than `FETCH FIRST n ROWS ONLY`, which SQLite cannot parse;
 *  - pattern matching uses `ESCAPE '!'` rather than a backslash, because MySQL
 *    treats backslash specially inside a string literal unless
 *    `NO_BACKSLASH_ESCAPES` is set — `!` has no such meaning anywhere;
 *  - percentiles use `percentile_cont`, which exists only on PostgreSQL, so the
 *    metric is refused on other engines rather than approximated.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class StatementGrammar
{
    /** The escape character used for pattern matching on every engine. */
    public const LIKE_ESCAPE = '!';

    public function __construct(
        private readonly ScopePolicy $policy = new ScopePolicy(),
    ) {
    }

    /**
     * Compile a structured read.
     *
     * @param array<int, mixed>|null $after Decoded keyset tuple, matching `$query->order`.
     */
    public function select(
        ScopeDescriptor $scope,
        CatalogueDescriptor $catalogue,
        SelectQuery $query,
        ?string $tenantId,
        string $engine,
        DatabaseLimits $limits,
        ?array $after = null,
    ): CompiledStatement {
        $table = $this->policy->assertRelation($scope, $catalogue, $query->relation);
        $bindings = array();
        $counter = 0;

        $projection = $this->projection($scope, $table, $query->columns, $engine);

        $where = $this->predicates($scope, $table, $query->filters, $tenantId, $engine, $bindings, $counter);

        $order = $this->orderTerms($scope, $table, $query->order, $engine);

        if (null !== $after) {
            $where[] = $this->cursorPredicate($order, $after, $engine, $bindings, $counter);
        } elseif (null === $order && null !== $query->offset && $query->offset > 0) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'Offset paging requires an explicit, stable ordering.'
            );
        }

        if (null !== $query->offset && $query->offset > $limits->offsetMax) {
            throw DatabaseRefusal::of(
                RefusalCode::LIMIT_EXCEEDED,
                sprintf(
                    'Offset %d exceeds the %d-row ceiling; use the cursor instead.',
                    $query->offset,
                    $limits->offsetMax
                )
            );
        }

        $sql = 'SELECT ' . $projection
            . ' FROM ' . $this->relationSql($table, $engine)
            . $this->whereSql($where)
            . $this->orderSql($order, $engine)
            . ' LIMIT ' . $limits->effectiveRowLimit($query->limit);

        if (null !== $query->offset && $query->offset > 0) {
            $sql .= ' OFFSET ' . $query->offset;
        }

        return new CompiledStatement($sql, $bindings);
    }

    /**
     * Compile a grouped aggregate.
     */
    public function aggregate(
        ScopeDescriptor $scope,
        CatalogueDescriptor $catalogue,
        AggregateQuery $query,
        ?string $tenantId,
        string $engine,
        DatabaseLimits $limits,
    ): CompiledStatement {
        $table = $this->policy->assertRelation($scope, $catalogue, $query->relation);
        $bindings = array();
        $counter = 0;

        $groupColumns = array();

        foreach ($query->groupBy as $column) {
            $groupColumns[] = $this->quote($this->policy->assertColumn($scope, $table, $column), $engine);
        }

        $selects = $groupColumns;
        $aliases = array();

        foreach ($query->metrics as $metric) {
            $aliases[] = $metric->alias;
            $selects[] = $this->metricSql($scope, $table, $metric, $engine);
        }

        if (array() === $selects) {
            throw DatabaseRefusal::of(RefusalCode::UNSUPPORTED_METRIC, 'Aggregate has nothing to select.');
        }

        $where = $this->predicates($scope, $table, $query->filters, $tenantId, $engine, $bindings, $counter);
        $order = $this->aggregateOrder($query->order, $query->groupBy, $aliases, $engine);

        $sql = 'SELECT ' . implode(', ', $selects)
            . ' FROM ' . $this->relationSql($table, $engine)
            . $this->whereSql($where);

        if (array() !== $groupColumns) {
            $sql .= ' GROUP BY ' . implode(', ', $groupColumns);
        }

        $sql .= $this->orderSql($order, $engine) . ' LIMIT ' . $limits->effectiveRowLimit($query->limit);

        return new CompiledStatement($sql, $bindings);
    }

    /**
     * Compile a term search.
     */
    public function search(
        ScopeDescriptor $scope,
        CatalogueDescriptor $catalogue,
        SearchQuery $query,
        ?string $tenantId,
        string $engine,
        DatabaseLimits $limits,
    ): CompiledStatement {
        $table = $this->policy->assertRelation($scope, $catalogue, $query->relation);
        $bindings = array();
        $counter = 0;

        $candidates = $query->columns;

        if (array() === $candidates) {
            $candidates = $scope->searchableColumns($table);
        } else {
            $searchable = $scope->searchableColumns($table);
            $candidates = array_values(array_intersect($candidates, $searchable));
        }

        if (array() === $candidates) {
            throw DatabaseRefusal::of(
                RefusalCode::COLUMN_NOT_ALLOWED,
                'No searchable column is granted for ' . $table->key() . '.'
            );
        }

        $pattern = '%' . $this->escapePattern(trim($query->term)) . '%';
        $operator = ConnectionDescriptor::PGSQL === $engine ? 'ILIKE' : 'LIKE';

        $matches = array();

        foreach ($candidates as $column) {
            $name = $this->policy->assertColumn($scope, $table, $column);
            $placeholder = $this->bind($bindings, $counter, $pattern);

            $matches[] = $this->quote($name, $engine)
                . ' ' . $operator . ' ' . $placeholder
                . " ESCAPE '" . self::LIKE_ESCAPE . "'";
        }

        $where = array( '(' . implode(' OR ', $matches) . ')' );

        foreach ($this->policy->composeRowFilter($scope, $table, $tenantId) as $predicate) {
            $where[] = $this->predicateSql(
                $predicate['column'],
                $predicate['operator'],
                $predicate['value'],
                $engine,
                $bindings,
                $counter
            );
        }

        $projection = $this->projection($scope, $table, array(), $engine);
        $order = $this->orderTerms($scope, $table, array(), $engine);

        $sql = 'SELECT ' . $projection
            . ' FROM ' . $this->relationSql($table, $engine)
            . $this->whereSql($where)
            . $this->orderSql($order, $engine)
            . ' LIMIT ' . $limits->effectiveRowLimit($query->limit);

        return new CompiledStatement($sql, $bindings);
    }

    /**
     * Wrap an operator-authored statement after admission.
     *
     * The SQL text is passed through unchanged — it is the one path where the
     * caller's text reaches the driver — which is why the tier is off by default,
     * the classifier runs first, and the role has no write grant underneath.
     */
    public function raw(RawQuery $query, string $engine, AdmissionClassifier $admission): CompiledStatement
    {
        $admission->admit($query->sql, $engine);
        $this->assertPlaceholdersMatch($query->sql, $query->params);

        return new CompiledStatement($query->sql, $query->params);
    }

    /**
     * The engine's `EXPLAIN` prefix.
     *
     * `ANALYZE` executes the plan, which is why it is a separate tier.
     */
    public function explainPrefix(string $engine, bool $analyze): string
    {
        return match (true) {
            ConnectionDescriptor::PGSQL === $engine => $analyze
                ? 'EXPLAIN (FORMAT JSON, ANALYZE true) '
                : 'EXPLAIN (FORMAT JSON) ',
            ConnectionDescriptor::MYSQL === $engine, ConnectionDescriptor::MARIADB === $engine => $analyze
                ? 'EXPLAIN ANALYZE FORMAT=JSON '
                : 'EXPLAIN FORMAT=JSON ',
            default => throw DatabaseRefusal::of(
                RefusalCode::UNSUPPORTED_METRIC,
                'EXPLAIN is not supported on ' . $engine . '.'
            ),
        };
    }

    	/**
    	 * Compile a standalone WHERE clause.
    	 *
    	 * Used by the write path, which needs the same predicate semantics (scope row
    	 * filter always ANDed, every value bound) but a different statement shape.
    	 * Exposing the compiler rather than reimplementing it in the adapter is what
    	 * keeps a write's `WHERE` identical to a read's.
    	 *
    	 * @param list<FilterClause> $filters Request filters.
    	 * @return array{sql: string, bindings: array<string, mixed>, table: TableDescriptor}
    	 */
    	public function compileWhere(
    		ScopeDescriptor $scope,
    		CatalogueDescriptor $catalogue,
    		string $relation,
    		array $filters,
    		?string $tenantId,
    		string $engine,
    	): array {
    		$table = $this->policy->assertRelation($scope, $catalogue, $relation);
    		$bindings = array();
    		$counter = 0;

    		$where = $this->predicates($scope, $table, $filters, $tenantId, $engine, $bindings, $counter);

    		return array(
    			'sql' => $this->whereSql($where),
    			'bindings' => $bindings,
    			'table' => $table,
    		);
    	}

    	/**
    	 * Quote an identifier for an engine.
     *
     * Defensive rather than load-bearing: identifiers reaching this method have
     * already been resolved from the catalogue. The length checks exist because a
     * silently truncated identifier is a correctness bug, not a security one.
     */
    public function quote(string $identifier, string $engine): string
    {
        if ('' === $identifier) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Empty identifier.');
        }

        if (str_contains($identifier, "\0")) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'NUL byte in identifier.');
        }

        if (ConnectionDescriptor::MYSQL === $engine || ConnectionDescriptor::MARIADB === $engine) {
            if (mb_strlen($identifier) > 64) {
                throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Identifier exceeds 64 characters.');
            }

            return '`' . str_replace('`', '``', $identifier) . '`';
        }

        if (strlen($identifier) > 63) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Identifier exceeds 63 bytes.');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * Escape LIKE metacharacters with the portable escape character.
     *
     * Without this, a search term of `100%` matches every row — the wildcard
     * escape bug the Laravel `ProductCatalog` already documents for `LIKE`.
     */
    public function escapePattern(string $term): string
    {
        return str_replace(
            array( self::LIKE_ESCAPE, '%', '_' ),
            array( self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_' ),
            $term
        );
    }

    /**
     * Build the projection list.
     *
     * @param list<string> $requested Empty means every column the scope allows.
     */
    private function projection(
        ScopeDescriptor $scope,
        TableDescriptor $table,
        array $requested,
        string $engine,
    ): string {
        $names = array();

        if (array() === $requested) {
            foreach ($table->columns as $name => $column) {
                if ($scope->allowsColumn($table, $name)) {
                    $names[] = $column->name;
                }
            }
        } else {
            foreach ($requested as $column) {
                $names[] = $this->policy->assertColumn($scope, $table, $column);
            }
        }

        $names = array_values(array_unique($names));

        if (array() === $names) {
            throw DatabaseRefusal::of(
                RefusalCode::COLUMN_NOT_ALLOWED,
                'No projectable column is granted for ' . $table->key() . '.'
            );
        }

        return implode(', ', array_map(fn (string $name): string => $this->quote($name, $engine), $names));
    }

    /**
     * Compile the caller's filters plus the scope's mandatory row filter.
     *
     * @param list<\Nvoos\Database\Domain\ValueObject\Database\FilterClause> $filters
     * @param array<string, mixed> $bindings
     *
     * @return list<string>
     */
    private function predicates(
        ScopeDescriptor $scope,
        TableDescriptor $table,
        array $filters,
        ?string $tenantId,
        string $engine,
        array &$bindings,
        int &$counter,
    ): array {
        $where = array();

        foreach ($filters as $filter) {
            $name = $this->policy->assertColumn($scope, $table, $filter->column);

            $where[] = $this->predicateSql($name, $filter->operator, $filter->value, $engine, $bindings, $counter);
        }

        foreach ($this->policy->composeRowFilter($scope, $table, $tenantId) as $predicate) {
            $where[] = $this->predicateSql(
                $predicate['column'],
                $predicate['operator'],
                $predicate['value'],
                $engine,
                $bindings,
                $counter
            );
        }

        return $where;
    }

    /**
     * Emit one predicate with bound values.
     *
     * @param array<string, mixed> $bindings
     */
    private function predicateSql(
        string $column,
        FilterOperator $operator,
        mixed $value,
        string $engine,
        array &$bindings,
        int &$counter,
    ): string {
        $quoted = $this->quote($column, $engine);

        return match ($operator) {
            FilterOperator::EQUALS => $quoted . ' = ' . $this->bind($bindings, $counter, $value),
            FilterOperator::NOT_EQUALS => $quoted . ' <> ' . $this->bind($bindings, $counter, $value),
            FilterOperator::GREATER => $quoted . ' > ' . $this->bind($bindings, $counter, $value),
            FilterOperator::GREATER_OR_EQUAL => $quoted . ' >= ' . $this->bind($bindings, $counter, $value),
            FilterOperator::LESS => $quoted . ' < ' . $this->bind($bindings, $counter, $value),
            FilterOperator::LESS_OR_EQUAL => $quoted . ' <= ' . $this->bind($bindings, $counter, $value),
            FilterOperator::IS_NULL => $quoted . ' IS NULL',
            FilterOperator::IS_NOT_NULL => $quoted . ' IS NOT NULL',
            FilterOperator::IN => $quoted . ' IN ' . $this->inList($value, $bindings, $counter),
            FilterOperator::NOT_IN => $quoted . ' NOT IN ' . $this->inList($value, $bindings, $counter),
            FilterOperator::BETWEEN => $quoted
                . ' BETWEEN ' . $this->bind($bindings, $counter, $this->pair($value)[0])
                . ' AND ' . $this->bind($bindings, $counter, $this->pair($value)[1]),
            FilterOperator::LIKE, FilterOperator::ILIKE => $quoted
                . (FilterOperator::ILIKE === $operator && ConnectionDescriptor::PGSQL === $engine ? ' ILIKE ' : ' LIKE ')
                . $this->bind($bindings, $counter, '%' . $this->escapePattern((string) $value) . '%')
                . " ESCAPE '" . self::LIKE_ESCAPE . "'",
        };
    }

    /**
     * Emit `( :p0, :p1, … )`.
     *
     * @param array<string, mixed> $bindings
     */
    private function inList(mixed $value, array &$bindings, int &$counter): string
    {
        if (! is_array($value) || array() === $value) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'IN requires at least one value.');
        }

        $placeholders = array();

        foreach (array_values($value) as $item) {
            $placeholders[] = $this->bind($bindings, $counter, $item);
        }

        return '(' . implode(', ', $placeholders) . ')';
    }

    /**
     * Emit `col > :p` / `col < :p` for a keyset tuple.
     *
     * Row-value comparison is used, so the predicate is one term rather than an
     * expanded OR chain. Mixed directions are refused: a tuple comparison is only
     * meaningful when every term sorts the same way.
     *
     * @param list<array{0: string, 1: string}>|null $order
     * @param array<int, mixed>                      $after
     * @param array<string, mixed>                   $bindings
     */
    private function cursorPredicate(
        ?array $order,
        array $after,
        string $engine,
        array &$bindings,
        int &$counter,
    ): string {
        if (null === $order || array() === $order) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'A cursor requires an explicit ordering.'
            );
        }

        if (count($after) !== count($order)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Cursor tuple width mismatch.');
        }

        $directions = array_unique(array_column($order, 1));

        if (1 !== count($directions)) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'A cursor cannot page a mixed-direction ordering.'
            );
        }

        $columns = array();
        $placeholders = array();

        foreach ($order as $term) {
            $columns[] = $this->quote($term[0], $engine);
        }

        foreach (array_values($after) as $value) {
            $placeholders[] = $this->bind($bindings, $counter, $value);
        }

        $operator = 'desc' === reset($directions) ? '<' : '>';

        return '(' . implode(', ', $columns) . ') ' . $operator . ' (' . implode(', ', $placeholders) . ')';
    }

    /**
     * Resolve ordering terms against the scope and the relation.
     *
     * Appends the primary key when the caller's ordering is not already unique, so
     * a keyset cursor cannot skip or repeat rows. Returns null when the relation
     * has neither an ordering nor a key.
     *
     * @param list<OrderClause> $terms
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function orderTerms(ScopeDescriptor $scope, TableDescriptor $table, array $terms, string $engine): ?array
    {
        if (array() === $terms) {
            return $this->defaultOrder($table);
        }

        $order = array();
        $named = array();

        foreach ($terms as $term) {
            $name = $this->policy->assertColumn($scope, $table, $term->column);
            $order[] = array( $name, $term->direction );
            $named[] = strtolower($name);
        }

        // Append the primary key as a tie-breaker so pagination is total.
        foreach ($table->primaryKey as $key) {
            $key = strtolower($key);

            if (! in_array($key, $named, true)) {
                $order[] = array( $key, $terms[0]->direction );
            }
        }

        return $order;
    }

    /**
     * A stable default ordering: the primary key, ascending.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function defaultOrder(TableDescriptor $table): ?array
    {
        if (array() === $table->primaryKey) {
            return null;
        }

        $order = array();

        foreach ($table->primaryKey as $key) {
            $order[] = array( strtolower($key), OrderClause::ASC );
        }

        return $order;
    }

    /**
     * Resolve aggregate ordering: a group column or a metric alias, nothing else.
     *
     * @param list<OrderClause> $terms
     * @param list<string>      $groupBy
     * @param list<string>      $aliases
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function aggregateOrder(array $terms, array $groupBy, array $aliases, string $engine): ?array
    {
        if (array() === $terms) {
            return null;
        }

        $order = array();

        foreach ($terms as $term) {
            $column = strtolower(trim($term->column));

            if (! in_array($column, $groupBy, true) && ! in_array($term->column, $aliases, true)) {
                throw DatabaseRefusal::of(
                    RefusalCode::STATEMENT_NOT_ADMITTED,
                    'Aggregate ordering must name a grouping column or a metric alias.'
                );
            }

            $order[] = array( $term->column, $term->direction );
        }

        return $order;
    }

    /**
     * Compile one metric expression.
     */
    private function metricSql(
        ScopeDescriptor $scope,
        TableDescriptor $table,
        MetricClause $metric,
        string $engine,
    ): string {
        if (! $metric->metric->isSupportedBy($engine)) {
            throw DatabaseRefusal::of(
                RefusalCode::UNSUPPORTED_METRIC,
                sprintf('%s is not supported on %s.', $metric->metric->value, $engine)
            );
        }

        $alias = $this->quote($metric->alias, $engine);

        if (Metric::COUNT === $metric->metric) {
            return 'COUNT(*) AS ' . $alias;
        }

        $column = $this->quote(
            $this->policy->assertColumn($scope, $table, (string) $metric->column),
            $engine
        );

        $expression = match ($metric->metric) {
            Metric::COUNT_DISTINCT => 'COUNT(DISTINCT ' . $column . ')',
            Metric::SUM => 'SUM(' . $column . ')',
            Metric::AVG => 'AVG(' . $column . ')',
            Metric::MIN => 'MIN(' . $column . ')',
            Metric::MAX => 'MAX(' . $column . ')',
            Metric::MEDIAN, Metric::P90 => sprintf(
                'percentile_cont(%s) WITHIN GROUP (ORDER BY %s)',
                (string) $metric->metric->percentile(),
                $column
            ),
            Metric::COUNT => 'COUNT(*)',
        };

        return $expression . ' AS ' . $alias;
    }

    private function relationSql(TableDescriptor $table, string $engine): string
    {
        return $this->quote($table->schema, $engine) . '.' . $this->quote($table->name, $engine);
    }

    /**
     * @param list<string> $where
     */
    private function whereSql(array $where): string
    {
        return array() === $where ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    /**
     * @param list<array{0: string, 1: string}>|null $order
     */
    private function orderSql(?array $order, string $engine): string
    {
        if (null === $order || array() === $order) {
            return '';
        }

        $terms = array();

        foreach ($order as $term) {
            $terms[] = $this->quote($term[0], $engine) . ' ' . strtoupper($term[1]);
        }

        return ' ORDER BY ' . implode(', ', $terms);
    }

    /**
     * Register a binding and return its placeholder.
     *
     * @param array<string, mixed> $bindings
     */
    private function bind(array &$bindings, int &$counter, mixed $value): string
    {
        $placeholder = ':p' . $counter;
        $bindings[$placeholder] = $value;
        ++$counter;

        return $placeholder;
    }

    /**
     * Normalise a BETWEEN value into a two-element list.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function pair(mixed $value): array
    {
        if (! is_array($value) || 2 !== count($value)) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'BETWEEN requires exactly two values.'
            );
        }

        $values = array_values($value);

        return array( $values[0], $values[1] );
    }

    /**
     * Ensure a raw statement's placeholders and bindings agree.
     *
     * Skipping this turns a typo into a driver error that may echo part of the
     * statement; matching here keeps the failure inside the kernel's vocabulary.
     *
     * @param array<string, mixed> $params
     */
    private function assertPlaceholdersMatch(string $sql, array $params): void
    {
        preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);

        $declared = array_unique($matches[1] ?? array());
        $provided = array_map(static fn (string $key): string => ltrim($key, ':'), array_keys($params));

        sort($declared);
        sort($provided);

        if ($declared !== $provided) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'Placeholders and bindings do not match.'
            );
        }
    }
}
