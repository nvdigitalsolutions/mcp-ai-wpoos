<?php

namespace App\Support\Budgets;

use RuntimeException;

/**
 * The estate has spent its budget for a window — Proposal 154.
 *
 * A plain runtime exception rather than an HTTP one on purpose: the guard runs
 * from HTTP controllers, from a chat stream and from workflow nodes, and only
 * the controllers should turn it into a status code. A workflow node that hits
 * the limit should fail as a node, not abort the request that started it.
 */
final class BudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $window,
        public readonly float $spend,
        public readonly float $limit,
    ) {
        parent::__construct(sprintf(
            'The %s budget is exhausted: $%s spent of $%s.',
            $window,
            number_format($spend, 4, '.', ''),
            number_format($limit, 4, '.', '')
        ));
    }
}
