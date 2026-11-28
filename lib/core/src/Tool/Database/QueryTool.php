<?php

/**
 * `db_query` — operator-authored SQL, behind its own tier.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same payload.
 *
 * Default off by tier: a scope must hold `raw_sql` before this tool can do
 * anything, and the port refuses otherwise. Even then the statement is:
 *
 *  - admitted by the kernel's `AdmissionClassifier` (`SELECT`/`WITH … SELECT`
 *    only, no `SET ROLE`, no file functions);
 *  - executed on a prepared statement inside a read-only transaction;
 *  - run by a role that cannot write, on a connection whose probe proved it.
 *
 * Three independent reasons a write cannot happen, only one of which is
 * lexical. This tool contributes none of them: it hands the port a statement
 * and a bind map.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Database\Domain\ValueObject\Database\RawQuery;

final class QueryTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_query';
    }

    public function getName(): string
    {
        return 'Run Database Query';
    }

    public function getDescription(): string
    {
        return 'Run one read-only SQL SELECT (or WITH … SELECT) against a scoped connection. Only available when the scope grants raw SQL. One statement per call; placeholders are required for every value.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'sql'    => array(
                        'type'        => 'string',
                        'maxLength'   => 8000,
                        'description' => 'A single SELECT or WITH … SELECT statement using :name placeholders.',
                    ),
                    'params' => array(
                        'type'        => 'object',
                        'description' => 'Placeholder values keyed by :name. Every placeholder in the statement must be supplied.',
                    ),
                ),
            ),
            'required'             => array('sql'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $scope      = $this->scope($arguments);
            $sql        = isset($arguments['sql']) ? trim((string) $arguments['sql']) : '';

            $params = array();

            foreach ((array) ($arguments['params'] ?? array()) as $key => $value) {
                $name = strtolower(trim((string) $key));

                if ('' === $name) {
                    continue;
                }

                $params[':' . $name] = $this->filterValue($value);
            }

            $result = $this->databases->query($connection, $scope, new RawQuery($sql, $params));

            return $this->envelope($result, array('connection' => $connection));
        });
    }
}
