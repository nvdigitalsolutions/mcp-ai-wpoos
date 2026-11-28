<?php

/**
 * `db_list_connections` — what this assistant can reach.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same empty
 * argument schema, same `connections` / `count` payload.
 *
 * The list is scope-derived and redacted: it answers "which connections may I
 * use?" without disclosing a host, a database name, a credential handle or a
 * replica. A model that can enumerate endpoints can infer an estate, which is
 * information the scope did not grant.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

final class ListConnectionsTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_list_connections';
    }

    public function getName(): string
    {
        return 'List Database Connections';
    }

    public function getDescription(): string
    {
        return 'List the database connections this assistant may use, with each connection id, engine and health state. Use the returned id in other db_* tools.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        unset($arguments);

        return $this->guard(function () use ($context): array {
            $connections = array();

            foreach ($this->databases->connections($this->principal($context)) as $connection) {
                $connections[] = $this->summary($connection);
            }

            return array(
                'success'     => true,
                'connections' => $connections,
                'count'       => count($connections),
            );
        });
    }

    /**
     * A redacted connection summary.
     *
     * `ConnectionDescriptor::toSummary()` already omits credential, replica and
     * root-certificate handles. The unsets are defence in depth, matching the
     * WordPress redactor: a future change to `toSummary()` must not be able to
     * leak an endpoint through this tool.
     *
     * @return array<string, mixed>
     */
    private function summary(ConnectionDescriptor $connection): array
    {
        $summary = $connection->toSummary();

        unset(
            $summary['credential'],
            $summary['read_replica'],
            $summary['sslrootcert'],
            $summary['host'],
            $summary['port'],
            $summary['database'],
        );

        return $summary;
    }
}
