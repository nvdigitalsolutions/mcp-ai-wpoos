<?php

/**
 * The `math` family, first slice: the Boolean gate evaluator.
 *
 * Proposal 164 section 164.165. What is pinned: AND over two true inputs is
 * true; NOT takes exactly one input and a binary gate takes at least two;
 * ambiguous inputs are refused by index; XOR folds as parity, so three true
 * inputs are true; the NAND decomposition is off by default and the string
 * `false` does not switch it on; and an **n-ary** NAND decomposition keeps every
 * input in both forms, where the WordPress LaTeX converter rendered
 * `NAND(A, B, C)` as `(A ↑ B)` and discarded C.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Math\EvaluateLogicGateTool;
use PHPUnit\Framework\TestCase;

final class MathLogicGateTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );
    }

    public function test_and_of_two_true_inputs_is_true(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'and', 'inputs' => array(1, 1)),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('AND', $result['data']['gate']);
        $this->assertTrue($result['data']['result']);
        $this->assertSame(array(1, 1), $result['data']['inputs']);
        $this->assertSame(array('A' => 1, 'B' => 1, 'result' => 1), $result['data']['truth_row']);
        $this->assertSame('A AND B', $result['data']['symbolic']);
        $this->assertSame('A \\cdot B', $result['data']['latex']);
    }

    public function test_not_takes_exactly_one_input(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'NOT', 'inputs' => array(1, 0)),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('inputs', $result['error']['data']);
        $this->assertStringContainsString('exactly one', $result['error']['data']['inputs'][0]);
    }

    public function test_a_binary_gate_needs_two_inputs(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'OR', 'inputs' => array(1)),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('at least two', $result['error']['data']['inputs'][0]);
    }

    public function test_an_unknown_gate_is_refused(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'IMPLIES', 'inputs' => array(1, 1)),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('gate', $result['error']['data']);
    }

    public function test_an_ambiguous_input_is_refused_by_index(): void
    {
        $ambiguous = $this->gate()->execute(
            array('gate' => 'AND', 'inputs' => array(1, 2)),
            array('user_id' => 7)
        );

        $this->assertFalse($ambiguous['success']);
        $this->assertStringContainsString('Input 2', $ambiguous['error']['data']['inputs'][0]);

        // The spellings WordPress accepted are still accepted.
        $accepted = $this->gate()->execute(
            array('gate' => 'AND', 'inputs' => array('true', '0')),
            array('user_id' => 7)
        );

        $this->assertTrue($accepted['success']);
        $this->assertFalse($accepted['data']['result']);
        $this->assertSame(array(1, 0), $accepted['data']['inputs']);
    }

    public function test_xor_folds_as_parity(): void
    {
        $even = $this->gate()->execute(
            array('gate' => 'XOR', 'inputs' => array(1, 1, 0)),
            array('user_id' => 7)
        );

        $odd = $this->gate()->execute(
            array('gate' => 'XOR', 'inputs' => array(1, 1, 1)),
            array('user_id' => 7)
        );

        $this->assertFalse($even['data']['result']);
        $this->assertTrue($odd['data']['result']);
        $this->assertSame('A \\oplus B \\oplus C', $odd['data']['latex']);
    }

    public function test_the_nand_decomposition_is_off_by_default(): void
    {
        $default = $this->gate()->execute(
            array('gate' => 'AND', 'inputs' => array(1, 1)),
            array('user_id' => 7)
        );

        $this->assertArrayNotHasKey('nand_decomposition', $default['data']);

        // WordPress read this with ! empty(), where the string false is true.
        $stringFalse = $this->gate()->execute(
            array('gate' => 'AND', 'inputs' => array(1, 1), 'decompose_to_nand' => 'false'),
            array('user_id' => 7)
        );

        $this->assertArrayNotHasKey('nand_decomposition', $stringFalse['data']);
    }

    public function test_the_nand_decomposition_of_and_is_two_nands(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'AND', 'inputs' => array(1, 1), 'decompose_to_nand' => true),
            array('user_id' => 7)
        );

        $decomposition = $result['data']['nand_decomposition'];

        $this->assertSame('NAND(NAND(A, B), NAND(A, B))', $decomposition['text']);
        $this->assertSame('((A \\uparrow B) \\uparrow (A \\uparrow B))', $decomposition['latex']);
    }

    public function test_an_nary_nand_decomposition_keeps_every_input(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'NAND', 'inputs' => array(1, 1, 1), 'decompose_to_nand' => true),
            array('user_id' => 7)
        );

        $decomposition = $result['data']['nand_decomposition'];

        $this->assertSame('NAND(A, B, C)', $decomposition['text']);

        // WordPress parsed two operands and skipped to the closing parenthesis,
        // so this rendered as (A ↑ B) and C vanished.
        $this->assertSame('\\overline{A \\cdot B \\cdot C}', $decomposition['latex']);
        $this->assertStringContainsString('C', $decomposition['latex']);
    }

    public function test_not_decomposes_to_a_single_nand(): void
    {
        $result = $this->gate()->execute(
            array('gate' => 'NOT', 'inputs' => array(0), 'decompose_to_nand' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['result']);
        $this->assertSame('NOT A', $result['data']['symbolic']);
        $this->assertSame('NAND(A, A)', $result['data']['nand_decomposition']['text']);
        $this->assertSame('(A \\uparrow A)', $result['data']['nand_decomposition']['latex']);
    }

    public function test_format_selects_the_symbolic_forms(): void
    {
        $text = $this->gate()->execute(
            array('gate' => 'OR', 'inputs' => array(1, 0), 'format' => 'text'),
            array('user_id' => 7)
        );

        $this->assertArrayHasKey('symbolic', $text['data']);
        $this->assertArrayNotHasKey('latex', $text['data']);

        $latex = $this->gate()->execute(
            array('gate' => 'OR', 'inputs' => array(1, 0), 'format' => 'latex'),
            array('user_id' => 7)
        );

        $this->assertArrayHasKey('latex', $latex['data']);
        $this->assertArrayNotHasKey('symbolic', $latex['data']);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        // WordPress silently replaced it with "both"; the schema declares three.
        $result = $this->gate()->execute(
            array('gate' => 'OR', 'inputs' => array(1, 0), 'format' => 'unicode'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('format', $result['error']['data']);
    }

    public function test_an_unauthenticated_call_is_refused(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->gate()->execute(array('gate' => 'AND', 'inputs' => array(1, 1)));
    }

    private function gate(): EvaluateLogicGateTool
    {
        return new EvaluateLogicGateTool($this->errors);
    }
}
