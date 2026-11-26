<?php

namespace App\Support\Budgets;

use RuntimeException;

/**
 * Spend could not be read, so the budget cannot be checked — Proposal 154.
 *
 * Kept distinct from {@see BudgetExceededException} because the two are
 * different operator problems: "you are over" is a working budget doing its job,
 * "we cannot tell" is a database problem. The chat routes answer 503 for this and
 * 429 for that.
 */
final class BudgetUnverifiableException extends RuntimeException
{
}
