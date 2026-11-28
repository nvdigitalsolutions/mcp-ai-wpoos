<?php

/**
 * Opens PDO handles from `oos.database.connections`.
 *
 * Proposal 167 sections 4.1 and 4.2. Credentials are never part of the DSN: they
 * are constructor arguments to `PDO`, which keeps them out of any driver error
 * that echoes the DSN. Emulated prepares are off so every value is bound by the
 * server, and MySQL multi-statements are off in the *constructor* — the one
 * place a driver-level guard cannot be bypassed by a later statement.
 *
 * No persistent connections (decision D8): `application_name` and
 * `statement_timeout` are session-scoped, and a pooled handle would carry one
 * call's preamble into the next.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Closure;
use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;
use Nvoos\Database\Domain\Contract\Database\DatabaseEngineInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Laravel\Database\Engine\AbstractProfile;
use PDO;

final class PdoConnectionFactory implements ConnectionFactoryInterface
{
    /**
     * @param Closure(ConnectionDescriptor):PDO|null $resolver Supplies an existing
     *                                                         handle (the Eloquent
     *                                                         variant uses this).
     */
    public function __construct(
        private readonly ?Closure $resolver = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function engine(ConnectionDescriptor $connection): DatabaseEngineInterface
    {
        return AbstractProfile::for($connection);
    }

    /**
     * {@inheritDoc}
     */
    public function open(ConnectionDescriptor $connection): PDO
    {
        if (null !== $this->resolver) {
            /** @var PDO $pdo */
            $pdo = ($this->resolver)($connection);

            return $pdo;
        }

        $options = $this->options();

        if (ConnectionDescriptor::MYSQL === $connection->engine || ConnectionDescriptor::MARIADB === $connection->engine) {
            $multi = $this->multiStatementsAttribute();

            if (null !== $multi) {
                $options[$multi] = false;
            }
        }

        try {
            return new PDO(
                $this->dsn($connection),
                '' !== $connection->role ? $connection->role : null,
                $connection->credential,
                $options,
            );
        } catch (\PDOException $error) {
            // The driver message can quote the DSN, so it is mapped, never shown.
            throw DatabaseRefusal::wrap(RefusalCode::CONNECTION_UNAVAILABLE, $error, 'pdo-open:' . $connection->id);
        }
    }

    /**
     * Build the DSN for a connection.
     *
     * @throws DatabaseRefusal When the engine is not supported.
     */
    public function dsn(ConnectionDescriptor $connection): string
    {
        $port = $connection->port > 0 ? $connection->port : $this->defaultPort($connection->engine);

        return match ($connection->engine) {
            ConnectionDescriptor::PGSQL => $this->pgsqlDsn($connection, $port),
            ConnectionDescriptor::MYSQL,
            ConnectionDescriptor::MARIADB => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $connection->host,
                $port,
                $connection->database,
            ),
            ConnectionDescriptor::SQLITE => 'sqlite:' . $connection->database,
            default => throw DatabaseRefusal::of(RefusalCode::NOT_A_DATABASE, 'Unsupported engine.'),
        };
    }

    /**
     * Driver options, identical on every connection.
     *
     * @return array<int, mixed>
     */
    public function options(): array
    {
        return array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
        );
    }

    /**
     * PostgreSQL keeps credentials out of the DSN and TLS in it.
     */
    private function pgsqlDsn(ConnectionDescriptor $connection, int $port): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $connection->host,
            $port,
            $connection->database,
            '' !== $connection->sslmode ? $connection->sslmode : 'require',
        );

        if (null !== $connection->sslRootCert && '' !== $connection->sslRootCert) {
            $dsn .= ';sslrootcert=' . $connection->sslRootCert;
        }

        return $dsn;
    }

    private function defaultPort(string $engine): int
    {
        return match ($engine) {
            ConnectionDescriptor::PGSQL => 5432,
            ConnectionDescriptor::MYSQL,
            ConnectionDescriptor::MARIADB => 3306,
            default => 0,
        };
    }

    /**
     * The driver constant for multi-statements, when the extension defines it.
     *
     * @return int|null
     */
    private function multiStatementsAttribute(): ?int
    {
        $name = 'PDO::MYSQL_ATTR_MULTI_STATEMENTS';

        if (defined($name)) {
            return (int) constant($name);
        }

        return null;
    }
}
