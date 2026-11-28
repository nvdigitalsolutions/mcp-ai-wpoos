<?php

/**
 * The database port for a Laravel application.
 *
 * Proposal 167 phase P4, tasks 4.3 and 4.6. This class is deliberately almost
 * empty: the read state machine, the caps, the masking, the grammar and the
 * error mapping all come from
 * {@see \Nvoos\Database\Domain\Service\Database\PdoExecutor}, which is the same
 * code the WordPress plugin runs. What is configured here is the host half — the
 * seven collaborators — and nothing else.
 *
 * ## Binding a principal
 *
 * `PdoExecutor` refuses every read until a principal is bound, which is the safe
 * default for a service object that escaped its request. The service provider
 * therefore resolves the port per request:
 *
 * ```php
 * $db = PdoDatabaseConnection::build( config( 'oos.database' ) )
 *     ->for_principal( new PrincipalRef( PrincipalRef::USER, (string) auth()->id() ) );
 * ```
 *
 * `for_principal()` returns a clone, so the shared instance is never mutated.
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
use Nvoos\Database\Domain\Contract\Database\AuditSinkInterface;
use Nvoos\Database\Domain\Contract\Database\ConnectionFactoryInterface;
use Nvoos\Database\Domain\Service\Database\PdoExecutor;

class PdoDatabaseConnection extends PdoExecutor
{
    /**
     * Assemble the port from `oos.database`.
     *
     * @param array<string, mixed>                     $config      The `oos.database` array.
     * @param ConnectionFactoryInterface|null          $factory     Handle opener; PDO by default.
     * @param Closure(array<string, mixed>):void|null  $auditWriter Receives audit records
     *                                                             when no sink is supplied.
     * @param Closure():?string|null                   $tenant      Resolves the current tenant id.
     * @param AuditSinkInterface|null                  $audit       An explicit sink; the table
     *                                                             ledger when the host opts in.
     */
    public static function build(
        array $config,
        ?ConnectionFactoryInterface $factory = null,
        ?Closure $auditWriter = null,
        ?Closure $tenant = null,
        ?AuditSinkInterface $audit = null,
    ): static {
        $registry = new DatabaseConfig($config);
        $factory  = $factory ?? new PdoConnectionFactory();

        return new static(
            $registry,
            $registry,
            new InformationSchemaCatalogue($factory),
            $factory,
            $audit ?? new LoggerAuditSink($auditWriter),
            new ScopeRedactor(),
            (string) ($config['cursor_secret'] ?? 'nvoos-database-cursor'),
            $tenant,
        );
    }
}
