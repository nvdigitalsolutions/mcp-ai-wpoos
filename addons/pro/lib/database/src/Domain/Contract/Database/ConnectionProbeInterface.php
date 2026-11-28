<?php

/**
 * Optional port capability: introspect a credential's privileges.
 *
 * Proposal 167 amendment A1 and phase P4. The probe answers "what can this
 * credential do?" by introspection, not by attempting a write: a temporary-table
 * write test was rejected during research because it mutates the target database,
 * can fail for reasons unrelated to grants, and leaves an artefact when cleanup is
 * interrupted.
 *
 * It is a separate interface rather than a method on
 * {@see \Nvoos\Database\Domain\Contract\DatabaseQueryInterface} because reading
 * rows and describing a role are different authorities. A host can serve every
 * read tool without it; `db_test_connection` then reports that this host has no
 * probe, which is honest, rather than guessing a role's privileges from a query.
 *
 * Never throws: an unreachable connection is a recorded state, because the caller
 * is a diagnostics tool whose whole job is to render the reason.
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
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

interface ConnectionProbeInterface
{
    /**
     * Probe a connection.
     */
    public function probe(ConnectionDescriptor $connection): ProbeResult;
}
