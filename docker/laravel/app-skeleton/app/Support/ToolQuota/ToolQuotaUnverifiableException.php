<?php

namespace App\Support\ToolQuota;

use RuntimeException;
use Throwable;

/**
 * A tool quota could not be read — Proposal 149 §176.
 *
 * **Fails closed**, for the reason `BudgetUnverifiableException` does: the
 * counter is what the refusal is computed from, and a counter that cannot be
 * read under-reports. Under-reporting is exactly the failure a quota exists to
 * prevent, so the call is refused rather than allowed on an unknown.
 *
 * Unlike the budget path this does not surface as an HTTP status — the tool is
 * one step inside a turn that has already been admitted — so it becomes a tool
 * error the model can see and route around.
 */
final class ToolQuotaUnverifiableException extends RuntimeException
{
    public function __construct(
        string $message = 'The tool quota could not be verified.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
