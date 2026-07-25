<?php
/**
 * WordPress Adapter — Token Budget Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\TokenBudgetServiceInterface;

/**
 * WordPress adapter for token budget operations.
 *
 * @since 2.0.0
 */
final class TokenBudgetService implements TokenBudgetServiceInterface
{
    /**
     * Legacy token budget manager.
     *
     * @var \WP_MCP_AI_Token_Budget_Manager|null
     */
    private $legacy;

    public function __construct()
    {
        if (\class_exists('WP_MCP_AI_Token_Budget_Manager')) {
            $this->legacy = new \WP_MCP_AI_Token_Budget_Manager();
        }
    }

    public function getModelLimit(string $modelId): int
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'get_model_limit')) {
            return 0;
        }

        /** @var int|float $limit */
        $limit = $this->legacy->get_model_limit($modelId);

        return (int) $limit;
    }

    public function chunkDocument(string $text, string $modelId): array
    {
        if ($this->legacy === null || !\method_exists($this->legacy, 'chunk_document')) {
            return [$text];
        }

        /** @var array<int, string> $chunks */
        $chunks = $this->legacy->chunk_document($text, $modelId);

        return \is_array($chunks) ? $chunks : [$text];
    }

    public function remainingBudget(int $usedTokens, string $modelId): int
    {
        $limit = $this->getModelLimit($modelId);

        if ($limit <= 0) {
            return 0;
        }

        $budget = (int) ($limit * (1.0 - self::DEFAULT_SAFETY_MARGIN));

        return \max(0, $budget - $usedTokens);
    }

    public function fitsInBudget(int $estimatedTokens, string $modelId): bool
    {
        return $estimatedTokens <= $this->remainingBudget(0, $modelId);
    }

    /**
     * Default safety margin (10%).
     */
    private const DEFAULT_SAFETY_MARGIN = 0.1;
}
