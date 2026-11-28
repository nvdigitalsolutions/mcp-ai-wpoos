<?php

/**
 * PostgreSQL session profile.
 *
 * Proposal 167 section 4.3.1. `SET LOCAL statement_timeout = $1` is not valid —
 * `SET` takes no placeholder — so every setting goes through `set_config()`,
 * whose third argument makes the change transaction-local: the same lifetime as
 * `SET LOCAL`, with the value still bound.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database\Engine;

use Nvoos\Database\Domain\Service\Database\StatementGrammar;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

final class PgsqlProfile extends AbstractProfile
{
    /**
     * Idle-in-transaction guard, as a multiple of the statement timeout.
     */
    private const IDLE_TX_MULTIPLIER = 2;

    /**
     * Lock wait, in milliseconds.
     */
    private const LOCK_TIMEOUT_MS = 2000;

    public function name(): string
    {
        return ConnectionDescriptor::PGSQL;
    }

    public function begin_statement(): string
    {
        return 'BEGIN READ ONLY';
    }

    /**
     * {@inheritDoc}
     *
     * @return list<CompiledStatement>
     */
    public function session_statements(
        ConnectionDescriptor $connection,
        ScopeDescriptor $scope,
        DatabaseLimits $limits,
        string $application_name,
        ?string $tenant_id,
    ): array {
        $statements = array(
            new CompiledStatement(
                "SELECT set_config('statement_timeout', :p0, true)",
                array(':p0' => (string) max(1, $limits->timeoutMs)),
            ),
            new CompiledStatement(
                "SELECT set_config('idle_in_transaction_session_timeout', :p0, true)",
                array(':p0' => (string) max(1, $limits->timeoutMs * self::IDLE_TX_MULTIPLIER)),
            ),
            new CompiledStatement(
                "SELECT set_config('lock_timeout', :p0, true)",
                array(':p0' => (string) self::LOCK_TIMEOUT_MS),
            ),
            new CompiledStatement(
                "SELECT set_config('search_path', :p0, true)",
                array(':p0' => $this->searchPath($scope)),
            ),
            new CompiledStatement(
                "SELECT set_config('application_name', :p0, true)",
                array(':p0' => $this->applicationName($application_name)),
            ),
        );

        if (null !== $tenant_id && '' !== $tenant_id) {
            $statements[] = new CompiledStatement(
                "SELECT set_config('app.tenant_id', :p0, true)",
                array(':p0' => $tenant_id),
            );
        }

        return $statements;
    }

    public function explain_statement(CompiledStatement $statement, bool $analyze): CompiledStatement
    {
        $sql = sprintf(
            'EXPLAIN (FORMAT JSON, ANALYZE %s, COSTS true, VERBOSE false) ',
            $analyze ? 'true' : 'false',
        );

        return new CompiledStatement($sql . $statement->sql, $statement->bindings);
    }

    public function supports_explain_analyze(ConnectionDescriptor $connection): bool
    {
        return true;
    }

    /**
     * The schema path, quoted, with `pg_catalog` last.
     */
    private function searchPath(ScopeDescriptor $scope): string
    {
        $grammar = new StatementGrammar();
        $parts   = array();

        foreach ($scope->schemas as $schema) {
            $parts[] = $grammar->quote($schema, ConnectionDescriptor::PGSQL);
        }

        $parts[] = 'pg_catalog';

        return implode(', ', $parts);
    }
}
