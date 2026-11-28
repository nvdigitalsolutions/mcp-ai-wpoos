<?php

/**
 * The `math` family, sixth slice: solve_equation.
 *
 * Proposal 164 section 164.170. The regression this file exists for is the
 * WordPress fallback: `3x^2 + 2 = 5` was matched as `3x` and answered `x = 1`
 * under a success envelope, with the exponent silently discarded. The tool no
 * longer guesses — a non-polynomial, a second symbol or an unusable equation
 * shape is a typed refusal.
 *
 * Also pinned: the equation reaches the engine unmodified, the variable must be
 * an identifier, `x = 2 = 3` is not two sides, and `show_steps` is gone rather
 * than accepted and ignored.
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
use Nvoos\Core\Tool\Math\SolveEquationTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SolveEquationToolTest extends TestCase
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

    public function test_the_tool_metadata(): void
    {
        $tool = $this->tool();

        $this->assertSame('solve_equation', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertSame(array('equation'), $tool->getParametersSchema()['required']);
    }

    public function test_it_does_not_offer_show_steps(): void
    {
        // WordPress accepted it and no implementation ever produced a step.
        $this->assertArrayNotHasKey('show_steps', $this->tool()->getParametersSchema()['properties']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('equation' => '2x = 4'), array());
    }

    public function test_it_requires_an_equation(): void
    {
        $result = $this->tool()->execute(array(), $this->context());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('equation', $result['error']['data']);
    }

    public function test_it_refuses_an_equation_without_an_equals_sign(): void
    {
        $result = $this->tool()->execute(array('equation' => '2x + 5'), $this->context());

        $this->assertArrayHasKey('equation', $result['error']['data']);
    }

    public function test_it_refuses_three_sides(): void
    {
        // explode() without a limit returns three parts here; WordPress checked
        // only that the count was not two, which is luck rather than a rule.
        $result = $this->tool()->execute(array('equation' => 'x = 2 = 3'), $this->context());

        $this->assertArrayHasKey('equation', $result['error']['data']);
    }

    public function test_it_refuses_an_empty_side(): void
    {
        $result = $this->tool()->execute(array('equation' => 'x ='), $this->context());

        $this->assertArrayHasKey('equation', $result['error']['data']);
    }

    public function test_it_refuses_a_variable_that_is_not_an_identifier(): void
    {
        // The variable is interpolated into expressions downstream.
        $result = $this->tool()->execute(
            array('equation' => '2x = 4', 'variable' => 'x + 1'),
            $this->context()
        );

        $this->assertArrayHasKey('variable', $result['error']['data']);
    }

    public function test_it_refuses_an_unknown_format(): void
    {
        $result = $this->tool()->execute(
            array('equation' => '2x = 4', 'format' => 'markdown'),
            $this->context()
        );

        $this->assertArrayHasKey('format', $result['error']['data']);
    }

    public function test_a_missing_engine_is_named(): void
    {
        $result = $this->tool($this->engine(false))
            ->execute(array('equation' => '2x = 4'), $this->context());

        $this->assertSame(MathEngineInterface::ERROR_UNAVAILABLE, $result['error']['code']);
    }

    public function test_a_non_polynomial_is_a_validation_failure(): void
    {
        $engine = $this->engine(true);
        $engine->method('solve')->willThrowException(
            new InvalidArgumentException('The equation is not a polynomial in x.')
        );

        $result = $this->tool($engine)->execute(
            array('equation' => 'sin(x) = 0.5'),
            $this->context()
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('equation', $result['error']['data']);
    }

    public function test_an_engine_failure_is_reported(): void
    {
        $engine = $this->engine(true);
        $engine->method('solve')->willThrowException(new RuntimeException('worker down'));

        $result = $this->tool($engine)->execute(array('equation' => '2x = 4'), $this->context());

        $this->assertSame('wp_mcp_ai_math_engine_failed', $result['error']['code']);
    }

    public function test_the_equation_reaches_the_engine_unmodified(): void
    {
        $captured = array();
        $engine = $this->engine(true, function (string $left, string $right, string $variable) use (&$captured): array {
            $captured = array($left, $right, $variable);

            return $this->answer();
        });

        // An HTML filter would mangle the comparison and the exponent.
        $this->tool($engine)->execute(
            array('equation' => 'x^2 - 4 < 1 = 0'),
            $this->context()
        );

        $this->assertSame(array('x^2 - 4 < 1', '0', 'x'), $captured);
    }

    public function test_the_solutions_are_described(): void
    {
        $result = $this->tool()->execute(array('equation' => 'x^2 - 4 = 0'), $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame(array(-2, 2), $result['data']['solutions']);
        $this->assertSame(2, $result['data']['degree']);
        $this->assertFalse($result['data']['infinite_solutions']);
        $this->assertSame('x1 = -2, x2 = 2', $result['data']['solution_text']);
        $this->assertSame('Solutions: x1 = -2, x2 = 2', $result['data']['text']);
        $this->assertSame('x_{1} = -2, \\quad x_{2} = 2', $result['data']['latex']);
    }

    public function test_text_only_omits_the_latex_rendering(): void
    {
        $result = $this->tool()->execute(
            array('equation' => '2x = 4', 'format' => 'text'),
            $this->context()
        );

        $this->assertArrayNotHasKey('latex', $result['data']);
    }

    public function test_an_identity_is_reported_as_infinite(): void
    {
        $engine = $this->engine(true, fn (): array => array(
            'solutions' => array(),
            'degree' => 0,
            'infinite' => true,
            'text' => 'Every value of x satisfies the equation',
            'tex' => '',
        ));

        $result = $this->tool($engine)->execute(
            array('equation' => '2x + 1 = 1 + 2x'),
            $this->context()
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['infinite_solutions']);
        $this->assertSame(array(), $result['data']['solutions']);
    }

    public function test_the_variable_defaults_to_x(): void
    {
        $captured = '';
        $engine = $this->engine(true, function (string $left, string $right, string $variable) use (&$captured): array {
            $captured = $variable;

            return $this->answer();
        });

        $this->tool($engine)->execute(array('equation' => 'y = 1'), $this->context());

        $this->assertSame('x', $captured);
    }

    /** A signed-in caller. */
    private function context(): array
    {
        return array('user_id' => 7);
    }

    /** The answer the fake engine returns by default. */
    private function answer(): array
    {
        return array(
            'solutions' => array(-2, 2),
            'degree' => 2,
            'infinite' => false,
            'text' => 'x1 = -2, x2 = 2',
            'tex' => 'x_{1} = -2, \\quad x_{2} = 2',
        );
    }

    /**
     * An engine double.
     *
     * @param bool          $available Whether the engine is present.
     * @param callable|null $solve     Answer for solve(), when it should differ.
     */
    private function engine(bool $available = true, ?callable $solve = null): MathEngineInterface
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn($available);
        $engine->method('solve')->willReturnCallback($solve ?? fn (): array => $this->answer());

        return $engine;
    }

    /** The tool under test. */
    private function tool(?MathEngineInterface $engine = null): SolveEquationTool
    {
        return new SolveEquationTool($this->errors, $engine ?? $this->engine());
    }
}
