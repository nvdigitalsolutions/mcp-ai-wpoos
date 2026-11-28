<?php

/**
 * The database port — what `lib/core` is allowed to know about a database.
 *
 * Proposal 167 sections 167.6.10 and 167.6.14. Deliberately narrow: descriptors
 * and structured query objects in, a shaped result out. There is no `PDO`, no
 * `wpdb`, no connection string and no WordPress type anywhere in this signature,
 * which is what lets the same kernel serve the WordPress plugin and the Laravel
 * runtime without a second implementation.
 *
 * ## Who implements it
 *
 *  - WordPress: `WP_MCP_AI_Db_Executor` (PDO, `includes/database/`)
 *  - Laravel:   `EloquentDatabaseConnection` / `PdoDatabaseConnection`
 *
 * Both must return the same {@see RefusalCode} for the same failure. That is the
 * cross-runtime parity gate in the implementation plan, and it is the reason the
 * codes live in this layer rather than in each host.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract;

use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PlanDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;

interface DatabaseQueryInterface
{
    /**
     * Connections this principal may use, redacted.
     *
     * @return list<ConnectionDescriptor>
     */
    public function connections(PrincipalRef $principal): array;

    /**
     * The scope-filtered catalogue for one connection.
     *
     * System schemas are never present, whatever the credential can read.
     */
    public function describe(string $connection, ?string $relation = null): CatalogueDescriptor;

    /**
     * A structured read.
     */
    public function select(string $connection, string $scope, SelectQuery $query): ResultSet;

    /**
     * A grouped aggregate.
     */
    public function aggregate(string $connection, string $scope, AggregateQuery $query): ResultSet;

    /**
     * A term search.
     */
    public function search(string $connection, string $scope, SearchQuery $query): ResultSet;

    /**
     * A plan, optionally executed.
     *
     * `$analyze` requires the `explain_analyze` tier: it runs the query.
     */
    public function explain(string $connection, string $scope, SelectQuery $query, bool $analyze = false): PlanDescriptor;

    /**
     * Operator-authored SQL. Requires the `raw_sql` tier.
     */
    public function query(string $connection, string $scope, RawQuery $query): ResultSet;
}
