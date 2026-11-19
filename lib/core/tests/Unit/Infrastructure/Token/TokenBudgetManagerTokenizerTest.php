<?php

/**
 * Tests for tokenizer-backed estimation (Proposal 149 §71).
 *
 * The manager must delegate when a tokenizer is attached and keep the chars/4
 * heuristic when none is — the second case is every deployment that has not
 * installed the optional tiktoken package, so it is not the edge case.
 *
 * @package Nvoos\Core\Tests
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Token;

use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use PHPUnit\Framework\TestCase;

final class TokenBudgetManagerTokenizerTest extends TestCase
{
    public function test_an_attached_tokenizer_decides_the_count(): void
    {
        $budget = new TokenBudgetManager();
        $budget->setTokenizer($this->tokenizer(5));

        // chars/4 would say 1 for a four-character string; the tokenizer says 5.
        $this->assertSame(5, $budget->estimateTokens('abcd'));
        $this->assertSame(5, $budget->estimateTokens('abcd', 'gpt-4o'));
    }

    public function test_without_a_tokenizer_the_heuristic_is_used(): void
    {
        $budget = new TokenBudgetManager();

        $this->assertNull($budget->getTokenizer());
        $this->assertSame(1, $budget->estimateTokens('abcd'));
        $this->assertSame(3, $budget->estimateTokens('1234567890'));
        $this->assertSame(0, $budget->estimateTokens(''));
    }

    public function test_the_tokenizer_flows_into_message_estimation(): void
    {
        $budget = new TokenBudgetManager();
        $budget->setTokenizer($this->tokenizer(100));

        // Three priming tokens + four overhead + role + content, each text
        // counted as 100 by the fake: 3 + 4 + 100 + 100.
        $this->assertSame(207, $budget->estimateMessageTokens(array(
            array( 'role' => 'user', 'content' => 'hi' ),
        )));
    }

    private function tokenizer(int $count): TiktokenServiceInterface
    {
        return new class ($count) implements TiktokenServiceInterface {
            public function __construct(private readonly int $count)
            {
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return '' === $text ? 0 : $this->count;
            }

            public function countTokensAccurate(string $text, ?string $model = null): int
            {
                return $this->countTokens($text, $model);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function resolveEncoding(?string $model = null): string
            {
                return 'cl100k_base';
            }
        };
    }
}
