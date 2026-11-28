<?php

/**
 * What a scope resolves to, before a single row is read.
 *
 * Proposal 167 phase P3 task 3.3. The console's editor needs to answer "what
 * will this grant actually do?" — which columns come back, which are masked,
 * what the effective caps are — without executing the query. This is that
 * answer.
 *
 * ## Literal-free by construction
 *
 * `statement` is the grammar's output: framework-emitted SQL with `:pN`
 * placeholders. Bound values are counted (`bindings`), never carried — the
 * same rule as the audit trail, so a preview can be logged, screenshotted or
 * pasted into a ticket without leaking a row value.
 *
 * @package Nvoos\Database
 * @since   3.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class PreviewDescriptor
{
    /**
     * @param string       $connection  Connection id.
     * @param string       $scope       Scope id.
     * @param string       $relation    `schema.table` the statement targets.
     * @param string       $engine      Engine the statement was compiled for.
     * @param string       $statement   SQL template with `:pN` placeholders.
     * @param int          $bindings    Count of bound values (never the values).
     * @param list<string> $projected   Columns the statement returns.
     * @param list<string> $masked      Columns whose values are masked.
     * @param list<string> $excluded    Columns removed from the projection entirely.
     * @param list<string> $searchable  Columns `db_search` may match, when declared.
     * @param int          $rowLimit    Effective row cap for this request.
     * @param array<string, int> $limits Effective caps.
     * @param bool         $tenantBound Whether a mandatory tenant predicate was applied.
     */
    public function __construct(
        public string $connection,
        public string $scope,
        public string $relation,
        public string $engine,
        public string $statement,
        public int $bindings,
        public array $projected,
        public array $masked,
        public array $excluded,
        public array $searchable,
        public int $rowLimit,
        public array $limits,
        public bool $tenantBound,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'connection'   => $this->connection,
            'scope'        => $this->scope,
            'relation'     => $this->relation,
            'engine'       => $this->engine,
            'statement'    => $this->statement,
            'bindings'     => $this->bindings,
            'projected'    => $this->projected,
            'masked'       => $this->masked,
            'excluded'     => $this->excluded,
            'searchable'   => $this->searchable,
            'row_limit'    => $this->rowLimit,
            'limits'       => $this->limits,
            'tenant_bound' => $this->tenantBound,
        );
    }
}
