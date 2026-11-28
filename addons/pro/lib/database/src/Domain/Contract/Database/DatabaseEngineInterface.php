<?php

/**
 * Engine profile the read executor needs.
 *
 * Proposal 167 phase P4. Until the Laravel port there was one engine family, so
 * the executor could hold a concrete `WP_MCP_AI_Db_Engine`. The Laravel runtime
 * needs the same four engines, and extracting the executor into the kernel means
 * it must speak to an interface rather than to a class it cannot load.
 *
 * Deliberately narrow: only the members the executor calls. `dsn()` and
 * `options()` stay on the host's engine because they are how a host *opens* a
 * handle, and the executor receives an already-open `PDO`.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

interface DatabaseEngineInterface
{
    /**
     * Engine key: `pgsql`, `mysql`, `mariadb` or `sqlite`.
     */
    public function name(): string;

    /**
     * The statement that opens the read transaction.
     */
    public function begin_statement(): string;

    /**
     * Session statements, run in order inside the transaction.
     *
     * @return list<CompiledStatement>
     */
    public function session_statements(
        ConnectionDescriptor $connection,
        ScopeDescriptor $scope,
        DatabaseLimits $limits,
        string $application_name,
        ?string $tenant_id,
    ): array;

    /**
     * Wrap a compiled statement in the engine's EXPLAIN form.
     */
    public function explain_statement(CompiledStatement $statement, bool $analyze): CompiledStatement;

    /**
     * Whether the engine can execute an `EXPLAIN ANALYZE` equivalent.
     */
    public function supports_explain_analyze(ConnectionDescriptor $connection): bool;

    /**
     * Whether a metric can be computed on this engine.
     */
    public function supports_metric(Metric $metric): bool;
}
