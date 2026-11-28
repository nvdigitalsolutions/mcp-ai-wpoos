<?php

/**
 * Shared base for the `lib/core` database tools.
 *
 * Proposal 167 phase P4, task 4.4. These tools are ports of the WordPress Pro
 * tools in `addons/pro/includes/tools/database/`, and the point of the port is
 * that they are *the same contract* on a different host: same slug, same
 * argument schema, same success payload, same refusal code.
 *
 * ## Why the tools are thin
 *
 * Everything that can refuse lives in the kernel
 * (`Nvoos\Database\Domain\Service\Database`) and in the port implementation:
 * the grammar resolves identifiers against a scope-filtered catalogue, the
 * policy decides grants, the shaper masks cells, the error mapper turns a
 * SQLSTATE into a {@see RefusalCode}. A tool that re-implemented any of that
 * would be a second place for the guarantee to rot.
 *
 * What is left for this layer is argument handling and envelope shaping — and
 * even the envelope is delegated to {@see ResultSet::toArray()} so the payload
 * is byte-identical to the WordPress host.
 *
 * ## The port is optional
 *
 * `Nvoos\Database` is a path-repository dependency of `nvoos/laravel-adapter`,
 * not of `nvoos/core` (decision D15 keeps the kernel in the Pro add-on so a
 * base+pro WordPress install still has it). A `Nvoos\Core` consumer that never
 * installs the kernel gets a tool class that cannot be constructed, which
 * `Nvoos\Laravel\Tool\ManifestToolRegistrar` reports as `class not found` and
 * skips. Nothing in the core engine references a kernel symbol at class-load
 * time, so no other tool pays for the optional dependency.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tool\AbstractTool;
use Nvoos\Database\Domain\Contract\Database\ScopeAwareInterface;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Throwable;

abstract class DatabaseTool extends AbstractTool
{
    /**
     * @param ErrorFactoryInterface  $errors    Host error factory.
     * @param DatabaseQueryInterface $databases Scoped database port.
     */
    public function __construct(
        ErrorFactoryInterface $errors,
        protected readonly DatabaseQueryInterface $databases,
    ) {
        parent::__construct($errors);
    }

    /**
     * The capability a caller must hold.
     *
     * Mirrors the WordPress tools. The host's tool registry enforces it; the
     * port never sees a capability, only a principal.
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    // ─── Execution guard ──────────────────────────────────────────────

    /**
     * Run a call and convert a kernel refusal into the host error object.
     *
     * The code and payload deliberately match the WordPress host
     * (`WP_MCP_AI_Database_Tool_Base::to_error()`): the same string
     * (`wp_mcp_ai_db_scope_table_not_allowed`), the same `status`, the same
     * `refusal_code`. A client that keys on the code therefore behaves the same
     * on either host, and the parity gate compares this payload rather than the
     * message text.
     *
     * @param callable():mixed $work Work to run.
     * @return mixed Success payload, or the host error object.
     */
    protected function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (DatabaseRefusal $refusal) {
            return $this->refusalError($refusal);
        } catch (Throwable $error) {
            // Mirrors the WordPress `attempt()`: an unexpected failure is a
            // generic 500, never a fatal in the tool surface and never the
            // driver text. A refusal has already been handled above, so this
            // only catches the genuinely unforeseen.
            unset($error);

            return $this->errors->create(
                'wp_mcp_ai_db_internal',
                'The database call could not be completed.',
                array('status' => 500),
            );
        }
    }

    /**
     * Build the host error object for a refusal.
     *
     * Exposed to subclasses (and to the parity test) so there is exactly one
     * mapping in this host.
     */
    protected function refusalError(DatabaseRefusal $refusal): mixed
    {
        return $this->errors->create(
            'wp_mcp_ai_' . $refusal->getRefusalCode(),
            $refusal->getMessage(),
            array(
                'status'       => $this->statusFor($refusal->getRefusal()),
                'refusal_code' => $refusal->getRefusalCode(),
            ),
        );
    }

    /**
     * HTTP status for a refusal.
     *
     * Identical to the WordPress mapping, including the 504 for the two timeout
     * codes and the 404 for the "does not exist" codes. A refusal is not a 400
     * by default: "you may not" and "there is no such table" are different
     * answers to an operator.
     */
    protected function statusFor(RefusalCode $code): int
    {
        return match ($code) {
            RefusalCode::NOT_CONFIGURED,
            RefusalCode::UNKNOWN_CONNECTION,
            RefusalCode::NOT_A_DATABASE,
            RefusalCode::RELATION_NOT_FOUND,
            RefusalCode::COLUMN_NOT_FOUND => 404,
            RefusalCode::SCOPE_NOT_ALLOWED,
            RefusalCode::TABLE_NOT_ALLOWED,
            RefusalCode::COLUMN_NOT_ALLOWED,
            RefusalCode::FILTER_UNMAPPABLE,
            RefusalCode::TIER_REQUIRED,
            RefusalCode::STATEMENT_NOT_ADMITTED,
            RefusalCode::SITE_DATABASE_REFUSED,
            RefusalCode::PRIVILEGE_DENIED,
            RefusalCode::APPROVAL_REQUIRED => 403,
            RefusalCode::TIMEOUT,
            RefusalCode::LOCK_TIMEOUT => 504,
            RefusalCode::CONNECTION_LIMIT => 503,
            RefusalCode::EXPORT_LIMIT => 429,
            default => 400,
        };
    }

    // ─── Argument handling ────────────────────────────────────────────

    /**
     * Resolve the calling principal.
     *
     * Assistant first, then user — the same precedence as the WordPress tools,
     * because an assistant's grants are the assistant's, not those of the
     * operator who happens to be chatting through it.
     *
     * @param array<string, mixed> $context Execution context.
     * @throws DatabaseRefusal When the context carries neither.
     */
    protected function principal(array $context): PrincipalRef
    {
        $assistant = isset($context['assistant_id']) ? (int) $context['assistant_id'] : 0;

        if ($assistant > 0) {
            return new PrincipalRef(PrincipalRef::ASSISTANT, (string) $assistant);
        }

        $user = isset($context['user_id']) ? (int) $context['user_id'] : 0;

        if ($user > 0) {
            return new PrincipalRef(PrincipalRef::USER, (string) $user);
        }

        throw DatabaseRefusal::of(
            RefusalCode::SCOPE_NOT_ALLOWED,
            'No assistant or user principal in the execution context.',
        );
    }

    /**
     * Resolve the connection id for a call.
     *
     * An explicit argument wins. Otherwise exactly one visible connection is
     * used; zero or several is a refusal, because guessing which warehouse a
     * question meant is not a decision this layer should make.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     * @throws DatabaseRefusal When no unambiguous connection exists.
     */
    protected function connection(array $arguments, array $context): string
    {
        $explicit = isset($arguments['connection']) ? strtolower(trim((string) $arguments['connection'])) : '';

        if ('' !== $explicit) {
            return $explicit;
        }

        $visible = $this->databases->connections($this->principal($context));

        if (0 === count($visible)) {
            throw DatabaseRefusal::of(RefusalCode::NOT_CONFIGURED, 'No connection is visible to this assistant.');
        }

        if (1 === count($visible)) {
            return $visible[0]->id;
        }

        throw DatabaseRefusal::of(RefusalCode::UNKNOWN_CONNECTION, 'Several connections are available; name one.');
    }

    /**
     * Resolve the scope id for a call.
     *
     * Empty means "the scope the policy picks for this principal", which is the
     * normal case: a scope is a grant, and most assistants hold one.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function scope(array $arguments): string
    {
        return isset($arguments['scope']) ? strtolower(trim((string) $arguments['scope'])) : '';
    }

    /**
     * Normalise a relation argument.
     *
     * Not an escaping step: the grammar resolves the identifier against the
     * scope-filtered catalogue, so an unknown or excluded relation is a refusal
     * rather than an injection. This only removes the whitespace and casing a
     * model varies by.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function relation(array $arguments): string
    {
        $value = $arguments['relation'] ?? $arguments['table'] ?? '';

        return strtolower(trim((string) $value));
    }

    /**
     * Build a select query from tool arguments.
     *
     * Filters and ordering become clause objects here, not raw arrays: the
     * grammar compiles `FilterClause` / `OrderClause` and would otherwise read a
     * null column off an array. `SelectQuery::fromArray()` performs the same
     * conversion for callers that start from a raw argument array.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function selectQuery(array $arguments): SelectQuery
    {
        $cursor = isset($arguments['cursor']) ? trim((string) $arguments['cursor']) : '';

        return new SelectQuery(
            relation: $this->relation($arguments),
            columns: $this->stringList($arguments['columns'] ?? array()),
            filters: $this->filterClauses($arguments['filters'] ?? array()),
            order: $this->orderClauses($arguments['order'] ?? array()),
            limit: isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : null,
            offset: isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : null,
            cursor: '' !== $cursor ? $cursor : null,
        );
    }

    /**
     * Build an aggregate query from tool arguments.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @throws DatabaseRefusal When a metric name is outside the closed enum.
     */
    protected function aggregateQuery(array $arguments): AggregateQuery
    {
        return new AggregateQuery(
            relation: $this->relation($arguments),
            groupBy: $this->stringList($arguments['group_by'] ?? array()),
            metrics: $this->metricClauses($arguments['metrics'] ?? array()),
            filters: $this->filterClauses($arguments['filters'] ?? array()),
            order: $this->orderClauses($arguments['order'] ?? array()),
            limit: isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : null,
        );
    }

    /**
     * Filters as clause objects.
     *
     * @param mixed $value Raw filters.
     * @return list<FilterClause>
     * @throws DatabaseRefusal When an operator is outside the closed enum.
     */
    protected function filterClauses(mixed $value): array
    {
        $clauses = array();

        foreach ($this->filters($value) as $filter) {
            $clauses[] = FilterClause::fromArray($filter);
        }

        return $clauses;
    }

    /**
     * Ordering as clause objects.
     *
     * @param mixed $value Raw order.
     * @return list<OrderClause>
     */
    protected function orderClauses(mixed $value): array
    {
        $clauses = array();

        foreach ($this->order($value) as $term) {
            $clauses[] = OrderClause::fromArray($term);
        }

        return $clauses;
    }

    /**
     * Build a search query from tool arguments.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    protected function searchQuery(array $arguments): SearchQuery
    {
        $term = $arguments['term'] ?? $arguments['query'] ?? '';

        return new SearchQuery(
            relation: $this->relation($arguments),
            term: trim((string) $term),
            columns: $this->stringList($arguments['columns'] ?? array()),
            limit: isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : null,
        );
    }

    /**
     * Translate the metric list into clauses.
     *
     * A metric outside `Metric` is a refusal, not a default. Guessing `count`
     * would answer a question nobody asked.
     *
     * @param mixed $metrics Raw metric list.
     * @return list<MetricClause>
     * @throws DatabaseRefusal When a metric name is unknown.
     */
    protected function metricClauses(mixed $metrics): array
    {
        $clauses = array();

        foreach ((array) $metrics as $metric) {
            if (! is_array($metric)) {
                continue;
            }

            $name  = strtolower(trim((string) ($metric['metric'] ?? 'count')));
            $value = Metric::tryFrom($name);

            if (null === $value) {
                throw DatabaseRefusal::of(
                    RefusalCode::UNSUPPORTED_METRIC,
                    'Unknown metric: ' . $name,
                );
            }

            $alias  = strtolower(trim((string) ($metric['alias'] ?? '')));
            $column = isset($metric['column']) && '' !== trim((string) $metric['column'])
                ? strtolower(trim((string) $metric['column']))
                : null;

            $clauses[] = new MetricClause(
                $value,
                '' !== $alias ? $alias : $value->value,
                $column,
            );
        }

        return $clauses;
    }

    /**
     * Sanitise filters.
     *
     * Scalar values keep their type so a numeric comparison stays numeric; only
     * strings are trimmed, and arrays are flattened to a list of scalars (for
     * `in` / `not_in` / `between`).
     *
     * @param mixed $value Raw filters.
     * @return list<array{column: string, op: string, value: mixed}>
     */
    protected function filters(mixed $value): array
    {
        $filters = array();

        foreach ((array) $value as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $filters[] = array(
                'column' => strtolower(trim((string) ($filter['column'] ?? ''))),
                'op'     => strtolower(trim((string) ($filter['op'] ?? $filter['operator'] ?? ''))),
                'value'  => $this->filterValue($filter['value'] ?? null),
            );
        }

        return $filters;
    }

    /**
     * Sanitise an order list.
     *
     * @param mixed $value Raw order.
     * @return list<array{column: string, direction: string}>
     */
    protected function order(mixed $value): array
    {
        $order = array();

        foreach ((array) $value as $term) {
            if (! is_array($term)) {
                continue;
            }

            $order[] = array(
                'column'    => strtolower(trim((string) ($term['column'] ?? ''))),
                'direction' => 'desc' === strtolower((string) ($term['direction'] ?? 'asc')) ? 'desc' : 'asc',
            );
        }

        return $order;
    }

    /**
     * Sanitise a filter value, preserving its type.
     *
     * @param mixed $value Raw value.
     */
    protected function filterValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        if (is_array($value)) {
            return array_values(array_map(array($this, 'filterValue'), $value));
        }

        return trim((string) $value);
    }

    /**
     * Normalise a list of identifiers.
     *
     * @param mixed $value Raw value.
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        $list = array();

        foreach ((array) $value as $entry) {
            if (is_string($entry) && '' !== trim($entry)) {
                $list[] = strtolower(trim($entry));
            }
        }

        return $list;
    }

    // ─── Envelope ─────────────────────────────────────────────────────

    /**
     * The canonical success envelope for a result set.
     *
     * `ResultSet::toArray()` already emits `columns`, `rows`, `row_count`,
     * `truncated`, `masked`, `elapsed_ms` and `audit_id`, so both hosts return
     * the same keys without this layer choosing any of them.
     *
     * @param ResultSet            $result Result.
     * @param array<string, mixed> $extra  Host-neutral extras (connection, relation).
     * @return array<string, mixed>
     */
    protected function envelope(ResultSet $result, array $extra = array()): array
    {
        return array_merge(array('success' => true), $result->toArray(), $extra);
    }

    /**
     * The scope descriptor a call would run under.
     *
     * Three tools need the descriptor rather than a decision: `db_profile_table`
     * to skip sensitive columns, `db_export` to check the export tier, and any
     * future tool whose shaping depends on the grant. A host that cannot expose
     * it refuses rather than proceeding blind.
     *
     * @throws DatabaseRefusal When the port is not scope-aware or no scope binds.
     */
    protected function scopeAt(string $connection, string $scope, PrincipalRef $principal): ScopeDescriptor
    {
        if (! $this->databases instanceof ScopeAwareInterface) {
            throw DatabaseRefusal::of(
                RefusalCode::SCOPE_NOT_ALLOWED,
                'This host cannot resolve a scope descriptor, so the scope rules cannot be applied.',
            );
        }

        return $this->databases->scopeDescriptor($connection, $scope, $principal);
    }

    /**
     * The connection descriptor for an explicit id.
     *
     * `db_test_connection` has to probe a named connection, and the port only
     * lists what a principal may see — so an id outside that list is
     * indistinguishable from an unknown one, which is the correct disclosure.
     *
     * @param string               $id      Connection id.
     * @param array<string, mixed> $context Execution context.
     *
     * @throws DatabaseRefusal When the principal cannot see the id.
     */
    protected function descriptor(string $id, array $context): \Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor
    {
        foreach ($this->databases->connections($this->principal($context)) as $connection) {
            if ($connection->id === $id) {
                return $connection;
            }
        }

        throw DatabaseRefusal::of(RefusalCode::UNKNOWN_CONNECTION, 'The connection is not visible to this assistant.');
    }

    // ─── Schema fragments ─────────────────────────────────────────────

    /**
     * Shared schema properties for every data tool.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function connectionProperties(): array
    {
        return array(
            'connection' => array(
                'type'        => 'string',
                'description' => 'Connection id from db_list_connections. Optional when only one connection is available.',
            ),
            'scope'      => array(
                'type'        => 'string',
                'description' => 'Scope id, when the assistant holds more than one for the connection.',
            ),
        );
    }

    /**
     * Filter schema fragment.
     *
     * The operator list is generated from the kernel's `FilterOperator` enum, so
     * the schema cannot drift from what the grammar can actually compile.
     *
     * @return array<string, mixed>
     */
    protected function filterSchema(): array
    {
        return array(
            'type'        => 'array',
            'description' => 'Predicates, all ANDed. A value is always bound, never interpolated.',
            'maxItems'    => 20,
            'items'       => array(
                'type'                 => 'object',
                'properties'           => array(
                    'column' => array(
                        'type'        => 'string',
                        'description' => 'Column name.',
                    ),
                    'op'     => array(
                        'type'        => 'string',
                        'enum'        => array_map(
                            static fn (FilterOperator $operator): string => $operator->value,
                            FilterOperator::cases(),
                        ),
                        'description' => 'Comparison operator.',
                    ),
                    'value'  => array(
                        'description' => 'Value; an array for in/not_in/between. Omit for is_null/is_not_null.',
                    ),
                ),
                'required'             => array('column', 'op'),
                'additionalProperties' => false,
            ),
        );
    }

    /**
     * Order schema fragment.
     *
     * @return array<string, mixed>
     */
    protected function orderSchema(): array
    {
        return array(
            'type'        => 'array',
            'description' => 'Ordering, applied in the given sequence. A single direction enables cursor paging.',
            'maxItems'    => 4,
            'items'       => array(
                'type'                 => 'object',
                'properties'           => array(
                    'column'    => array(
                        'type'        => 'string',
                        'description' => 'Column name.',
                    ),
                    'direction' => array(
                        'type' => 'string',
                        'enum' => array('asc', 'desc'),
                    ),
                ),
                'required'             => array('column'),
                'additionalProperties' => false,
            ),
        );
    }

    /**
     * Relation schema fragment.
     *
     * @return array<string, mixed>
     */
    protected function relationSchema(): array
    {
        return array(
            'type'        => 'string',
            'description' => 'Schema-qualified relation, e.g. "reporting.orders".',
        );
    }
}
