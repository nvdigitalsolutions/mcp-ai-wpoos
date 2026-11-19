<?php

/**
 * Tests for catalogue-backed model limits (Proposal 149 §70).
 *
 * The budget manager's built-in tables are snapshots. These tests pin the
 * precedence — catalogue first, snapshot second, and "no opinion" is not zero —
 * plus the one behaviour that matters to a caller: an oversized payload is
 * refused with a limit that came from the catalogue.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure\Token;

use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use PHPUnit\Framework\TestCase;

final class TokenBudgetManagerCatalogueTest extends TestCase
{
    public function test_the_catalogue_wins_over_the_snapshot(): void
    {
        // `gpt-5.5` is in the hardcoded snapshot at 1050000.
        $budget = new TokenBudgetManager();
        $budget->setCatalogue($this->catalogue(array( 'gpt-5.5' => 500000 ), array( 'gpt-5.5' => 64000 )));
        $this->assertSame(500000, $budget->getModelLimit('gpt-5.5'));
        $this->assertSame(64000, $budget->getMaxOutputTokens('gpt-5.5'));
    }

    public function test_an_unknown_model_falls_back_to_the_snapshot(): void
    {
        $budget = new TokenBudgetManager();
        $budget->setCatalogue($this->catalogue(array(), array()));
        $this->assertSame(1050000, $budget->getModelLimit('gpt-5.5'));
    }

    public function test_a_zero_limit_is_not_an_answer(): void
    {
        // A catalogue row with an unknown limit must not disable the check.
        $budget = new TokenBudgetManager();
        $budget->setCatalogue($this->catalogue(array( 'gpt-5.5' => 0 ), array( 'gpt-5.5' => 0 )));
        $this->assertSame(1050000, $budget->getModelLimit('gpt-5.5'));
        $this->assertSame(4096, $budget->getMaxOutputTokens('gpt-5.5'));
    }

    public function test_without_a_catalogue_the_behaviour_is_unchanged(): void
    {
        $budget = new TokenBudgetManager();
        $this->assertSame(1050000, $budget->getModelLimit('gpt-5.5'));
        $this->assertSame(0, $budget->getModelLimit('a-model-nobody-knows'));
    }

    public function test_a_catalogue_limit_refuses_an_oversized_payload(): void
    {
        $budget = new TokenBudgetManager();
        $budget->setCatalogue($this->catalogue(array( 'tiny-ctx' => 100 ), array()));

        $verdict = $budget->validateContextWindow(
            array(
                'model' => 'tiny-ctx',
                'messages' => array(
                    array( 'role' => 'user', 'content' => str_repeat('a', 4000) ),
                ),
            ),
            'tiny-ctx',
            'openai'
        );

        $this->assertIsArray($verdict);
        $this->assertSame('context_window_exceeded', $verdict['code']);
        $this->assertSame(100, $verdict['data']['context_limit']);
    }

    public function test_an_unknown_model_is_not_validated(): void
    {
        $budget = new TokenBudgetManager();
        $budget->setCatalogue($this->catalogue(array(), array()));

        $verdict = $budget->validateContextWindow(
            array( 'model' => 'a-model-nobody-knows', 'messages' => array() ),
            'a-model-nobody-knows',
            'openai'
        );

        $this->assertNull($verdict);
    }

    /**
     * @param array<string, int> $context Context windows.
     * @param array<string, int> $output  Output budgets.
     */
    private function catalogue(array $context, array $output): ModelLimitCatalogueInterface
    {
        return new class ($context, $output) implements ModelLimitCatalogueInterface {
            /**
             * @param array<string, int> $context Context windows.
             * @param array<string, int> $output  Output budgets.
             */
            public function __construct(
                private readonly array $context,
                private readonly array $output,
            ) {
            }

            public function contextWindow(string $model): ?int
            {
                return $this->context[ $model ] ?? null;
            }

            public function maxOutputTokens(string $model): ?int
            {
                return $this->output[ $model ] ?? null;
            }
        };
    }
}
