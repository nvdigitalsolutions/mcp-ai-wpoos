<?php
/**
 * Token Budget Service — domain contract.
 *
 * Manages token budgets to prevent API limit overruns, chunk documents,
 * and enforce per-request token ceilings.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Token budget enforcement for API calls.
 *
 * @since 2.0.0
 */
interface TokenBudgetServiceInterface
{
    /**
     * Get the maximum context token limit for a model.
     *
     * @param string $modelId Model identifier.
     *
     * @return int Token limit.
     */
    public function getModelLimit(string $modelId): int;

    /**
     * Split a large document into chunks that fit within a model's context.
     *
     * @param string $text    Document text.
     * @param string $modelId Target model ID (determines chunk size).
     *
     * @return array<int, string> Chunked text segments.
     */
    public function chunkDocument(string $text, string $modelId): array;

    /**
     * Calculate tokens remaining in the current request budget.
     *
     * @param int $usedTokens Tokens consumed so far.
     * @param string $modelId Target model ID.
     *
     * @return int Remaining token budget.
     */
    public function remainingBudget(int $usedTokens, string $modelId): int;

    /**
     * Whether a request with the given token count fits within budget.
     *
     * @param int    $estimatedTokens Estimated token count for the request.
     * @param string $modelId         Target model ID.
     *
     * @return bool
     */
    public function fitsInBudget(int $estimatedTokens, string $modelId): bool;
}
