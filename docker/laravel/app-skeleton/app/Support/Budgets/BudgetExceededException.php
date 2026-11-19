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
 *
 * `$assistantId` is `0` for a whole-estate limit and the assistant's id for a
 * narrowed one, so a caller can say *which* ceiling was reached — the difference
 * matters to whoever has to decide whether to raise a limit or fix an agent.
 */
final class BudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $window,
        public readonly float $spend,
        public readonly float $limit,
        public readonly int $assistantId = 0,
    ) {
        $message = $assistantId > 0
            ? sprintf(
                'The %s budget for assistant #%d is exhausted: $%s spent of $%s.',
                $window,
                $assistantId,
                number_format($spend, 4, '.', ''),
                number_format($limit, 4, '.', '')
            )
            : sprintf(
                'The %s budget is exhausted: $%s spent of $%s.',
                $window,
                number_format($spend, 4, '.', ''),
                number_format($limit, 4, '.', '')
            );

        parent::__construct($message);
    }
}
