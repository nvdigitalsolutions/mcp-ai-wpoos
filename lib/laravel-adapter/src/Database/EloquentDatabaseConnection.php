<?php

/**
 * The database port for an app that already has an Eloquent connection.
 *
 * Proposal 167 phase P4, task 4.2. When a scope targets the application's own
 * database, the reader should not open a second socket: this variant supplies the
 * handle Laravel already manages, and the catalogue can come from the same
 * connection.
 *
 * ## What is shared, and what is not
 *
 * Everything that decides *what* is read — grammar, policy, caps, masking,
 * audit, the read-only transaction — is `PdoExecutor`'s, identical to the
 * external-DSN path. Only `PDO` acquisition moves, which is the only thing that
 * can differ between "a DSN in config" and "the app's connection".
 *
 * ## Deliberate deviation from the plan
 *
 * The plan named `DB::transaction()` plus the query builder. Neither is used:
 *
 *  - `DB::transaction()` opens a *writable* transaction. The guarantee this
 *    toolkit rests on is `BEGIN READ ONLY`, which the executor emits on the raw
 *    handle; wrapping it in a Laravel transaction would either nest badly or
 *    drop the read-only flag.
 *  - Identifier safety comes from the scope-filtered catalogue and the grammar,
 *    not from the builder. Adding the builder would create a second identifier
 *    path with its own quoting rules — a second place for a bypass to appear.
 *
 * The Laravel-side benefit (one pool, one config) is kept; the safety argument is
 * unchanged.
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
use Nvoos\Database\Domain\Service\Database\PdoExecutor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use PDO;

final class EloquentDatabaseConnection
{
    /**
     * Build the port on top of an existing Eloquent connection.
     *
     * @param array<string, mixed>                    $config     The `oos.database` array.
     * @param Closure(ConnectionDescriptor):PDO       $resolver   Returns the PDO for a
     *                                                            descriptor; typically
     *                                                            `fn () => DB::connection( $name )->getPdo()`.
     * @param Closure(array<string, mixed>):void|null $auditWriter Receives audit records.
     * @param Closure():?string|null                  $tenant     Resolves the tenant id.
     */
    public static function make(
        array $config,
        Closure $resolver,
        ?Closure $auditWriter = null,
        ?Closure $tenant = null,
    ): PdoExecutor {
        return PdoDatabaseConnection::build(
            $config,
            new PdoConnectionFactory($resolver),
            $auditWriter,
            $tenant,
        );
    }
}
