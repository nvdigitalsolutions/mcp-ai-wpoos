<?php

/**
 * Handle opening the read executor needs.
 *
 * Proposal 167 phase P4. DSN construction and driver options are a host
 * concern — WordPress keeps encrypted credentials and a site-database guard,
 * Laravel reads `config('oos.database')` — but both end at the same two calls:
 * "which engine is this?" and "open a `PDO`".
 *
 * No persistent connections (decision D8): a pooled handle outlives the request
 * that was audited, and `application_name` / `statement_timeout` are
 * session-scoped, so a reused session carries another call's preamble.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use PDO;

interface ConnectionFactoryInterface
{
    /**
     * The engine profile for a connection.
     */
    public function engine(ConnectionDescriptor $connection): DatabaseEngineInterface;

    /**
     * Open a handle.
     *
     * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal
     */
    public function open(ConnectionDescriptor $connection): PDO;
}
