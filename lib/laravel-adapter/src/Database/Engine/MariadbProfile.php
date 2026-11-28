<?php

/**
 * MariaDB session profile.
 *
 * Proposal 167 section 4.3.3. MariaDB takes `max_statement_time` in *seconds*,
 * not milliseconds, so the timeout is formatted as a decimal string rather than
 * handed over as an integer — passing `5000` would mean 5 000 seconds.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database\Engine;

use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

final class MariadbProfile extends MysqlProfile
{
    public function name(): string
    {
        return ConnectionDescriptor::MARIADB;
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
                'SET SESSION max_statement_time = :p0',
                array(':p0' => $this->asSeconds($limits->timeoutMs)),
            ),
            new CompiledStatement(
                'SET SESSION innodb_lock_wait_timeout = :p0',
                array(':p0' => self::LOCK_WAIT_SECONDS),
            ),
            new CompiledStatement(
                'SET @nvoos_application_name = :p0',
                array(':p0' => $this->applicationName($application_name, 64)),
            ),
        );

        if (null !== $tenant_id && '' !== $tenant_id) {
            $statements[] = new CompiledStatement(
                'SET @nvoos_tenant_id = :p0',
                array(':p0' => $tenant_id),
            );
        }

        return $statements;
    }

    /**
     * {@inheritDoc}
     *
     * MariaDB's analyse form is `ANALYZE FORMAT=JSON`, not MySQL's
     * `EXPLAIN ANALYZE`.
     */
    public function explain_statement(CompiledStatement $statement, bool $analyze): CompiledStatement
    {
        $sql = $analyze ? 'ANALYZE FORMAT=JSON ' : 'EXPLAIN FORMAT=JSON ';

        return new CompiledStatement($sql . $statement->sql, $statement->bindings);
    }

    public function supports_explain_analyze(ConnectionDescriptor $connection): bool
    {
        return true;
    }
}
