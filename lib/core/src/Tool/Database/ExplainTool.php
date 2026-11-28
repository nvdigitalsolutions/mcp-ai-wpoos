<?php

/**
 * `db_explain` — the query plan, and optionally an analysed run.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same payload.
 *
 * `analyze` executes the query, so it is a separate tier (`explain_analyze`) and
 * the port refuses it when the scope or the engine does not hold it. A plain
 * plan must still resolve the same relation and filters, otherwise the plan
 * would describe a query the scope would refuse.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

final class ExplainTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_explain';
    }

    public function getName(): string
    {
        return 'Explain Database Query';
    }

    public function getDescription(): string
    {
        return 'Return the database plan for a scoped select without returning rows. Set analyze to true to execute and measure, which requires the explain_analyze capability on the scope.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'relation' => $this->relationSchema(),
                    'columns'  => array(
                        'type'     => 'array',
                        'maxItems' => 40,
                        'items'    => array('type' => 'string'),
                    ),
                    'filters'  => $this->filterSchema(),
                    'order'    => $this->orderSchema(),
                    'limit'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'analyze'  => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Execute the query and return measured timings. Requires the explain_analyze capability.',
                    ),
                ),
            ),
            'required'             => array('relation'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $scope      = $this->scope($arguments);
            $analyze    = ! empty($arguments['analyze']);
            $query      = $this->selectQuery($arguments);
            $plan       = $this->databases->explain($connection, $scope, $query, $analyze);

            return array_merge(
                array(
                    'success'    => true,
                    'connection' => $connection,
                    'relation'   => $query->relation,
                ),
                $plan->toArray(),
            );
        });
    }
}
