<?php

/**
 * The `math` family, second slice: the truth-table generator.
 *
 * Proposal 164 section 164.166. What is pinned: the truth table of a NAND is
 * enumerated most-significant-bit first; symbolic operators fold to their
 * keyword forms; constants and postfix complements evaluate; the explicit
 * variable order wins over the alphabetical default; and the four defects the
 * port corrects stay corrected — the expression `0` is a table rather than a
 * missing argument, a variable named `result` is refused instead of silently
 * losing its column, an unknown `format` is refused instead of becoming `both`,
 * and twelve nested parentheses parse where WordPress refused at about seven.
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
use Nvoos\Core\Tool\Math\GenerateTruthTableTool;
use PHPUnit\Framework\TestCase;

final class MathTruthTableTest extends TestCase
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

    public function test_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('generate_truth_table', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertSame(array('expression'), $tool->getParametersSchema()['required']);
    }

    public function test_a_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('expression' => 'A'));
    }

    public function test_nand_over_two_variables(): void
    {
        $result = $this->callTool(array('expression' => 'A NAND B'));

        $this->assertTrue($result['success']);
        $this->assertSame(array('A', 'B'), $result['data']['variables']);
        $this->assertSame(
            array(
                array('A' => 0, 'B' => 0, 'result' => 1),
                array('A' => 0, 'B' => 1, 'result' => 1),
                array('A' => 1, 'B' => 0, 'result' => 1),
                array('A' => 1, 'B' => 1, 'result' => 0),
            ),
            $result['data']['rows']
        );
    }

    public function test_three_variable_expression_matches_its_semantics(): void
    {
        $result = $this->callTool(array('expression' => '(A AND B) OR NOT C'));

        $this->assertSame(array('A', 'B', 'C'), $result['data']['variables']);
        $this->assertCount(8, $result['data']['rows']);

        foreach ($result['data']['rows'] as $row) {
            $expected = (1 === $row['A'] && 1 === $row['B']) || 0 === $row['C'];

            $this->assertSame($expected ? 1 : 0, $row['result']);
        }
    }

    public function test_sheffer_stroke_is_nand(): void
    {
        $result = $this->callTool(array('expression' => 'A↑B'));

        $this->assertSame(array('A', 'B'), $result['data']['variables']);
        $this->assertSame(array(1, 1, 1, 0), $this->column($result['data']['rows']));
    }

    public function test_conjunction_and_disjunction_symbols(): void
    {
        $this->assertSame(array(0, 0, 0, 1), $this->column($this->callTool(array('expression' => 'A·B'))['data']['rows']));
        $this->assertSame(array(0, 1, 1, 1), $this->column($this->callTool(array('expression' => 'A+B'))['data']['rows']));
        $this->assertSame(array(1, 0, 0, 0), $this->column($this->callTool(array('expression' => 'A↓B'))['data']['rows']));
    }

    public function test_exclusive_operators(): void
    {
        $this->assertSame(array(0, 1, 1, 0), $this->column($this->callTool(array('expression' => 'A XOR B'))['data']['rows']));
        $this->assertSame(array(1, 0, 0, 1), $this->column($this->callTool(array('expression' => 'A XNOR B'))['data']['rows']));
    }

    public function test_postfix_complement_negates_its_operand(): void
    {
        $result = $this->callTool(array('expression' => "A' AND B"));

        $this->assertSame(array(0, 1, 0, 0), $this->column($result['data']['rows']));
    }

    public function test_prefix_not_binds_tighter_than_conjunction(): void
    {
        $result = $this->callTool(array('expression' => 'NOT A AND B'));

        $this->assertSame(array(0, 1, 0, 0), $this->column($result['data']['rows']));
    }

    public function test_double_complement_cancels(): void
    {
        $result = $this->callTool(array('expression' => "A''"));

        $this->assertSame(array(0, 1), $this->column($result['data']['rows']));
    }

    public function test_constants_are_accepted(): void
    {
        $result = $this->callTool(array('expression' => 'A AND TRUE'));

        $this->assertSame(array('A'), $result['data']['variables']);
        $this->assertSame(array(0, 1), $this->column($result['data']['rows']));
    }

    public function test_the_expression_zero_is_a_table(): void
    {
        $result = $this->callTool(array('expression' => '0'));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['variables']);
        $this->assertSame(array(array('result' => 0)), $result['data']['rows']);
    }

    public function test_the_expression_one_is_a_table(): void
    {
        $result = $this->callTool(array('expression' => '1'));

        $this->assertSame(array(array('result' => 1)), $result['data']['rows']);
    }

    public function test_explicit_variable_order_wins(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A AND B',
                'variables'  => array('B', 'A'),
            )
        );

        $this->assertSame(array('B', 'A'), $result['data']['variables']);
        $this->assertSame(array('B' => 0, 'A' => 0, 'result' => 0), $result['data']['rows'][0]);
        $this->assertSame(array('B' => 1, 'A' => 1, 'result' => 1), $result['data']['rows'][3]);
    }

    public function test_detected_variables_are_alphabetical(): void
    {
        $result = $this->callTool(array('expression' => 'b AND A'));

        $this->assertSame(array('A', 'b'), $result['data']['variables']);
    }

    public function test_too_many_variables_is_refused(): void
    {
        $result = $this->callTool(
            array('expression' => 'A AND B AND C AND D AND E AND F AND G AND H AND I')
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('limit 8', $result['error']['data']['variables'][0]);
    }

    public function test_a_variable_named_result_is_refused(): void
    {
        $result = $this->callTool(array('expression' => 'A AND result'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('reserved', $result['error']['data']['variables'][0]);
    }

    public function test_the_reserved_name_is_case_insensitive(): void
    {
        $result = $this->callTool(array('expression' => 'A AND RESULT'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('reserved', $result['error']['data']['variables'][0]);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A AND B',
                'format'     => 'csv',
            )
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('format', $result['error']['data']);
    }

    public function test_the_format_is_case_insensitive(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A AND B',
                'format'     => 'Text',
            )
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('text', $result['data']);
        $this->assertArrayNotHasKey('markdown_table', $result['data']);
        $this->assertArrayNotHasKey('latex', $result['data']);
    }

    public function test_markdown_table_shape(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A NAND B',
                'format'     => 'markdown_table',
            )
        );

        $this->assertStringContainsString('| A | B | Result |', $result['data']['markdown_table']);
        $this->assertStringContainsString('| 0 | 0 | 1 |', $result['data']['markdown_table']);
    }

    public function test_latex_table_shape(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A AND B',
                'format'     => 'latex',
            )
        );

        $this->assertStringContainsString('\\begin{array}{cc|c}', $result['data']['latex']);
        $this->assertStringContainsString('\\text{Result}', $result['data']['latex']);
        $this->assertStringContainsString('\\hline', $result['data']['latex']);
    }

    public function test_a_non_string_variable_is_refused(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A',
                'variables'  => array(array('A')),
            )
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not a string', $result['error']['data']['variables'][0]);
    }

    public function test_a_variable_that_is_not_an_identifier_is_refused(): void
    {
        $result = $this->callTool(
            array(
                'expression' => 'A',
                'variables'  => array('1A'),
            )
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not an identifier', $result['error']['data']['variables'][0]);
    }

    public function test_deep_nesting_parses(): void
    {
        $result = $this->callTool(
            array('expression' => str_repeat('(', 12) . 'A' . str_repeat(')', 12))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('A'), $result['data']['variables']);
        $this->assertSame(array(0, 1), $this->column($result['data']['rows']));
    }

    public function test_nesting_beyond_the_cap_is_refused(): void
    {
        $result = $this->callTool(
            array('expression' => str_repeat('(', 40) . 'A' . str_repeat(')', 40))
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nests too deeply', $result['error']['data']['expression'][0]);
    }

    public function test_unbalanced_parentheses_are_refused(): void
    {
        $result = $this->callTool(array('expression' => '(A AND B'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unbalanced', $result['error']['data']['expression'][0]);
    }

    public function test_trailing_tokens_are_refused(): void
    {
        $result = $this->callTool(array('expression' => 'A B'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unexpected token', $result['error']['data']['expression'][0]);
    }

    public function test_an_unknown_character_is_refused_by_name(): void
    {
        $result = $this->callTool(array('expression' => 'A @ B'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('"@"', $result['error']['data']['expression'][0]);
    }

    public function test_a_multibyte_unknown_character_is_reported_whole(): void
    {
        $result = $this->callTool(array('expression' => 'A ≠ B'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('≠', $result['error']['data']['expression'][0]);
    }

    public function test_an_oversized_expression_is_refused(): void
    {
        $result = $this->callTool(array('expression' => str_repeat('A', 1001)));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('1000', $result['error']['data']['expression'][0]);
    }

    public function test_a_missing_expression_is_refused(): void
    {
        $result = $this->callTool(array());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('expression', $result['error']['data']);
    }

    public function test_a_non_string_expression_is_refused(): void
    {
        $result = $this->callTool(array('expression' => array('A AND B')));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('expression', $result['error']['data']);
    }

    private function tool(): GenerateTruthTableTool
    {
        return new GenerateTruthTableTool($this->errors);
    }

    /**
     * @param array<mixed, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>
     */
    private function callTool(array $arguments): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->tool()->execute($arguments, array('user_id' => 7));

        return $result;
    }

    /**
     * The result column of a table.
     *
     * @param list<array<string, int>> $rows Table rows.
     *
     * @return list<int>
     */
    private function column(array $rows): array
    {
        $column = array();

        foreach ($rows as $row) {
            $column[] = $row['result'];
        }

        return $column;
    }
}
