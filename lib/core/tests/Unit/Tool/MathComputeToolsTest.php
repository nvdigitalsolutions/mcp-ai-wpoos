<?php

/**
 * The `math` family, fourth slice: the three compute tools.
 *
 * Proposal 164 section 164.168. What is pinned: the expression reaches the
 * engine unmodified; the order the schema promises is enforced; the string
 * `false` does not switch simplification on; an unknown format is refused; and —
 * the regression this slice exists for — a tool whose engine is missing says so
 * instead of returning the input under a success envelope, which is what
 * `simplify_expression` did when its filter was unbound.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathEngineInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Math\CalculateDerivativeTool;
use Nvoos\Core\Tool\Math\MatrixOperationsTool;
use Nvoos\Core\Tool\Math\SimplifyExpressionTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MathComputeToolsTest extends TestCase
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

    // ── derivative ────────────────────────────────────────────

    public function test_the_derivative_tool_metadata(): void
    {
        $tool = $this->derivative();

        $this->assertSame('calculate_derivative', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertSame(array('function'), $tool->getParametersSchema()['required']);
    }

    public function test_the_derivative_tool_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->derivative()->execute(array('function' => 'x^2'));
    }

    public function test_it_differentiates_and_spells_the_answer_twice(): void
    {
        $result = $this->invoke($this->derivative(), array('function' => 'x^2'));

        $this->assertTrue($result['success']);
        $this->assertSame('2 * x', $result['data']['derivative']);
        $this->assertSame('d/dx(x^2) = 2 * x', $result['data']['text']);
        $this->assertSame('\\frac{d}{dx} (x^2) = 2 x', $result['data']['latex']);
        $this->assertSame(1, $result['data']['order']);
    }

    public function test_the_order_sets_the_notation(): void
    {
        $engine = $this->engine();
        $engine->method('derivative')->willReturn(array('text' => '2', 'tex' => '2'));

        $result = $this->invoke(
            new CalculateDerivativeTool($this->errors, $engine),
            array('function' => 'x^3', 'order' => 2)
        );

        $this->assertStringContainsString('\\frac{d^{2}}{dx^{2}}', $result['data']['latex']);
    }

    public function test_a_string_false_does_not_enable_simplification(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->expects($this->once())
            ->method('derivative')
            ->with('x^2', 'x', 1, false)
            ->willReturn(array('text' => '2 * x', 'tex' => '2 x'));

        $result = $this->invoke(
            new CalculateDerivativeTool($this->errors, $engine),
            array('function' => 'x^2', 'simplify' => 'false')
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['simplified']);
    }

    public function test_an_order_outside_the_schema_is_refused(): void
    {
        foreach (array(0, 6, 'abc') as $order) {
            $result = $this->invoke($this->derivative(), array('function' => 'x^2', 'order' => $order));

            $this->assertFalse($result['success'], 'order ' . var_export($order, true));
            $this->assertArrayHasKey('order', $result['error']['data']);
        }
    }

    public function test_an_unknown_format_is_refused_by_every_compute_tool(): void
    {
        $derivative = $this->invoke($this->derivative(), array('function' => 'x^2', 'format' => 'json'));
        $simplify = $this->invoke($this->simplify(), array('expression' => 'x + x', 'format' => 'json'));
        $matrix = $this->invoke(
            $this->matrix(),
            array('operation' => 'transpose', 'matrix_a' => array(array(1, 2)), 'format' => 'json')
        );

        foreach (array($derivative, $simplify, $matrix) as $result) {
            $this->assertFalse($result['success']);
            $this->assertArrayHasKey('format', $result['error']['data']);
        }
    }

    public function test_a_text_only_format_omits_the_latex(): void
    {
        $result = $this->invoke(
            $this->derivative(),
            array('function' => 'x^2', 'format' => 'text')
        );

        $this->assertArrayNotHasKey('latex', $result['data']);
        $this->assertSame('2 * x', $result['data']['derivative']);
    }

    public function test_an_unavailable_engine_is_named_by_every_compute_tool(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(false);

        $derivative = $this->invoke(
            new CalculateDerivativeTool($this->errors, $engine),
            array('function' => 'x^2')
        );
        $simplify = $this->invoke(
            new SimplifyExpressionTool($this->errors, $engine),
            array('expression' => 'x + x')
        );
        $matrix = $this->invoke(
            new MatrixOperationsTool($this->errors, $engine),
            array('operation' => 'transpose', 'matrix_a' => array(array(1, 2)))
        );

        foreach (array($derivative, $simplify, $matrix) as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('wp_mcp_ai_engine_missing', $result['error']['code']);
        }
    }

    public function test_an_engine_refusal_is_a_validation_failure(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('derivative')->willThrowException(new InvalidArgumentException('Unexpected end of expression'));

        $result = $this->invoke(
            new CalculateDerivativeTool($this->errors, $engine),
            array('function' => 'x +')
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Unexpected end', $result['error']['data']['function'][0]);
    }

    public function test_an_engine_failure_is_not_a_validation_failure(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('derivative')->willThrowException(new RuntimeException('the worker is unreachable'));

        $result = $this->invoke(
            new CalculateDerivativeTool($this->errors, $engine),
            array('function' => 'x^2')
        );

        $this->assertFalse($result['success']);
        $this->assertSame('wp_mcp_ai_math_engine_failed', $result['error']['code']);
    }

    // ── simplify ──────────────────────────────────────────────

    public function test_it_simplifies_and_reports_a_change(): void
    {
        $result = $this->invoke($this->simplify(), array('expression' => '2x + 3x'));

        $this->assertTrue($result['success']);
        $this->assertSame('5 * x - 3', $result['data']['simplified']);
        $this->assertSame('2x + 3x', $result['data']['original_expression']);
        $this->assertTrue($result['data']['changed']);
        $this->assertSame('5 x - 3', $result['data']['latex']);
    }

    public function test_an_unchanged_result_says_so(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('simplify')->willReturn(array('text' => 'x', 'tex' => 'x'));

        $result = $this->invoke(new SimplifyExpressionTool($this->errors, $engine), array('expression' => 'x'));

        $this->assertFalse($result['data']['changed']);
    }

    public function test_simplify_never_returns_the_input_as_a_success(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(false);
        $engine->expects($this->never())->method('simplify');

        $result = $this->invoke(new SimplifyExpressionTool($this->errors, $engine), array('expression' => '2x + 3x'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not simplified', $result['error']['message']);
    }

    // ── matrix ────────────────────────────────────────────────

    public function test_it_multiplies_two_matrices(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('matrix')->willReturn(
            array(
                'result' => array(array(5, 5), array(11, 11)),
                'text'   => '[[5,5],[11,11]]',
                'tex'    => '\\begin{bmatrix} 5 & 5 \\\\ 11 & 11 \\end{bmatrix}',
            )
        );

        $result = $this->invoke(
            new MatrixOperationsTool($this->errors, $engine),
            array(
                'operation' => 'multiply',
                'matrix_a'  => array(array(1, 2), array(3, 4)),
                'matrix_b'  => array(array(1, 1), array(2, 2)),
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(array(5, 5), array(11, 11)), $result['data']['result']);
        $this->assertStringContainsString('\\times', $result['data']['latex']);
        $this->assertArrayHasKey('matrix_b', $result['data']);
    }

    public function test_a_binary_operation_requires_a_second_matrix(): void
    {
        $result = $this->invoke(
            $this->matrix(),
            array('operation' => 'add', 'matrix_a' => array(array(1, 2)))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('matrix_b', $result['error']['data']);
    }

    public function test_a_ragged_matrix_is_refused(): void
    {
        $result = $this->invoke(
            $this->matrix(),
            array('operation' => 'transpose', 'matrix_a' => array(array(1, 2), array(3)))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('matrix_a', $result['error']['data']);
    }

    public function test_a_vector_result_renders_as_a_vector(): void
    {
        // WordPress fed this through a 2D formatter and fataled; the port asks
        // the engine for the LaTeX of whatever shape came back.
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('matrix')->willReturn(
            array(
                'result' => array(2.0, 2.0),
                'text'   => '[2,2]',
                'tex'    => '\\begin{bmatrix} 2 \\\\ 2 \\end{bmatrix}',
            )
        );

        $result = $this->invoke(
            new MatrixOperationsTool($this->errors, $engine),
            array('operation' => 'eigenvalues', 'matrix_a' => array(array(2, 0), array(0, 2)))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(2.0, 2.0), $result['data']['result']);
        $this->assertStringContainsString('\\lambda', $result['data']['latex']);
        $this->assertStringContainsString('\\begin{bmatrix} 2 \\\\ 2 \\end{bmatrix}', $result['data']['latex']);
    }

    public function test_a_scalar_result_renders_as_a_scalar(): void
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('matrix')->willReturn(array('result' => -2, 'text' => '-2', 'tex' => '-2'));

        $result = $this->invoke(
            new MatrixOperationsTool($this->errors, $engine),
            array('operation' => 'determinant', 'matrix_a' => array(array(1, 2), array(3, 4)))
        );

        $this->assertSame(-2, $result['data']['result']);
        $this->assertStringContainsString('\\det', $result['data']['latex']);
        $this->assertStringContainsString('= -2', $result['data']['latex']);
    }

    public function test_an_unknown_operation_is_refused(): void
    {
        $result = $this->invoke(
            $this->matrix(),
            array('operation' => 'invert', 'matrix_a' => array(array(1)))
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('operation', $result['error']['data']);
    }

    private function engine(): MathEngineInterface
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn(true);
        $engine->method('derivative')->willReturn(array('text' => '2 * x', 'tex' => '2 x'));
        $engine->method('simplify')->willReturn(array('text' => '5 * x - 3', 'tex' => '5 x - 3'));
        $engine->method('matrix')->willReturn(
            array(
                'result' => array(array(1)),
                'text'   => '[[1]]',
                'tex'    => '\\begin{bmatrix} 1 \\end{bmatrix}',
            )
        );

        return $engine;
    }

    private function derivative(): CalculateDerivativeTool
    {
        return new CalculateDerivativeTool($this->errors, $this->engine());
    }

    private function simplify(): SimplifyExpressionTool
    {
        return new SimplifyExpressionTool($this->errors, $this->engine());
    }

    private function matrix(): MatrixOperationsTool
    {
        return new MatrixOperationsTool($this->errors, $this->engine());
    }

    /**
     * @param array<mixed, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>
     */
    private function invoke(object $tool, array $arguments): array
    {
        /** @var array<string, mixed> $result */
        $result = $tool->execute($arguments, array('user_id' => 7));

        return $result;
    }
}
