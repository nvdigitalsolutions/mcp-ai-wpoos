<?php

/**
 * Engine profile base, and the factory the Laravel host uses.
 *
 * Proposal 167 section 4.3. The session preamble is the part of the toolkit that
 * makes a read structurally incapable of writing: the transaction opens
 * read-only, the statement timeout is transaction-local, and nothing survives the
 * `ROLLBACK`.
 *
 * ## Why this is a second copy, and what guards it
 *
 * On WordPress the profiles live in `WP_MCP_AI_Db_Engine_*`. They could not be
 * moved into the kernel for this phase without also moving the factory and the
 * probe, so the Laravel host carries its own. That is only acceptable because
 * `tests/database/parity/EngineParityTest.php` asserts the two produce
 * byte-identical statements for every engine, scope and limit combination — a
 * drift fails the build rather than silently weakening one host.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database\Engine;

use InvalidArgumentException;
use Nvoos\Database\Domain\Contract\Database\DatabaseEngineInterface;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

abstract class AbstractProfile implements DatabaseEngineInterface
{
    /**
     * Pick the profile for a connection.
     *
     * @throws InvalidArgumentException When the engine is unknown.
     */
    public static function for(ConnectionDescriptor $connection): DatabaseEngineInterface
    {
        return match ($connection->engine) {
            ConnectionDescriptor::PGSQL => new PgsqlProfile(),
            ConnectionDescriptor::MYSQL => new MysqlProfile(),
            ConnectionDescriptor::MARIADB => new MariadbProfile(),
            ConnectionDescriptor::SQLITE => new SqliteProfile(),
            default => throw new InvalidArgumentException('Unsupported engine: ' . $connection->engine),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function supports_metric(Metric $metric): bool
    {
        return $metric->isSupportedBy($this->name());
    }

    /**
     * Trim an application name to the engine's identifier limit.
     */
    protected function applicationName(string $value, int $limit = 63): string
    {
        $value = strtolower(preg_replace('/[^a-z0-9:_.\-]+/i', '-', $value) ?? '');
        $value = trim($value, '-');

        if ('' === $value) {
            $value = 'nvoos-database';
        }

        return substr($value, 0, $limit);
    }

    /**
     * Milliseconds as a decimal-seconds string for MariaDB.
     */
    protected function asSeconds(int $timeoutMs): string
    {
        return number_format(max(1, $timeoutMs) / 1000, 3, '.', '');
    }
}
