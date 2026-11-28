<?php

/**
 * The `math` family, seventh and final slice: calculate_integral.
 *
 * Proposal 164 section 164.171. Two things are pinned that no other test in
 * the family can pin:
 *
 *  - an indefinite answer is only returned if the engine says it verified it,
 *    because the engine that produces it can also produce a malformed answer
 *    that looks right;
 *  - a non-numeric limit is refused, because `floatval( 'abc' )` is `0.0` and
 *    WordPress integrated over the wrong interval without saying so.
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
use Nvoos\Core\Tool\Math\CalculateIntegralTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CalculateIntegralToolTest extends TestCase
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

        $this->assertSame('calculate_integral', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertSame(array('function'), $tool->getParametersSchema()['required']);
    }

    public function test_it_does_not_offer_simplify(): void
    {
        // Accepted, passed to a filter nobody implemented, and ignored.
        $this->assertArrayNotHasKey('simplify', $this->tool()->getParametersSchema()['properties']);
    }

    public function test_it_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('function' => 'x'), array());
    }

    public function test_it_requires_a_function(): void
    {
        $result = $this->tool()->execute(array(), $this->context());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('function', $result['error']['data']);
    }

    public function test_it_refuses_a_variable_that_is_not_an_identifier(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'variable' => 'x y'),
            $this->context()
        );

        $this->assertArrayHasKey('variable', $result['error']['data']);
    }

    public function test_it_refuses_an_unknown_type(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'type' => 'improper'),
            $this->context()
        );

        $this->assertArrayHasKey('type', $result['error']['data']);
    }

    public function test_a_definite_integral_needs_both_limits(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'type' => 'definite', 'lower_limit' => 0),
            $this->context()
        );

        $this->assertArrayHasKey('upper_limit', $result['error']['data']);
    }

    public function test_a_non_numeric_limit_is_refused(): void
    {
        // floatval('abc') is 0.0: WordPress silently integrated over [0, 0].
        $result = $this->tool()->execute(
            array('function' => 'x', 'type' => 'definite', 'lower_limit' => 'abc', 'upper_limit' => 1),
            $this->context()
        );

        $this->assertArrayHasKey('lower_limit', $result['error']['data']);
    }

    public function test_it_refuses_an_unknown_format(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x', 'format' => 'markdown'),
            $this->context()
        );

        $this->assertArrayHasKey('format', $result['error']['data']);
    }

    public function test_a_missing_engine_is_named(): void
    {
        $result = $this->tool($this->engine(false))
            ->execute(array('function' => 'x'), $this->context());

        $this->assertSame(MathEngineInterface::ERROR_UNAVAILABLE, $result['error']['code']);
    }

    public function test_an_untrusted_antiderivative_is_a_validation_failure(): void
    {
        $engine = $this->engine(true);
        $engine->method('integral')->willThrowException(
            new InvalidArgumentException('An antiderivative was produced but not trusted.')
        );

        $result = $this->tool($engine)->execute(array('function' => 'e^(-x^2)'), $this->context());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('function', $result['error']['data']);
    }

    public function test_an_engine_failure_is_reported(): void
    {
        $engine = $this->engine(true);
        $engine->method('integral')->willThrowException(new RuntimeException('worker down'));

        $result = $this->tool($engine)->execute(array('function' => 'x'), $this->context());

        $this->assertSame('wp_mcp_ai_math_engine_failed', $result['error']['code']);
    }

    public function test_the_integrand_reaches_the_engine_unmodified(): void
    {
        $captured = array();
        $engine = $this->engine(true, function (string $expression, string $variable, string $type) use (&$captured): array {
            $captured = array($expression, $variable, $type);

            return $this->indefiniteAnswer();
        });

        // An HTML filter would strip the comparison.
        $this->tool($engine)->execute(array('function' => 'x^2 - 4 < 1'), $this->context());

        $this->assertSame(array('x^2 - 4 < 1', 'x', 'indefinite'), $captured);
    }

    public function test_an_indefinite_integral_is_described(): void
    {
        $result = $this->tool()->execute(array('function' => 'x^2'), $this->context());

        $this->assertTrue($result['success']);
        $this->assertSame('(1/3)*x^3', $result['data']['integral']);
        $this->assertTrue($result['data']['verified']);
        $this->assertSame(7, $result['data']['samples']);
        $this->assertSame('Integral = (1/3)*x^3 + C', $result['data']['text']);
        $this->assertSame('\frac{x^{3}}{3}', $result['data']['latex']);
        $this->assertArrayNotHasKey('value', $result['data']);
    }

    public function test_a_definite_integral_is_described(): void
    {
        $engine = $this->engine(true, fn (): array => array(
            'type' => 'definite',
            'value' => 2.0,
            'tex' => '2',
            'verified' => false,
            'samples' => 0,
        ));

        $result = $this->tool($engine)->execute(
            array('function' => 'sin(x)', 'type' => 'definite', 'lower_limit' => 0, 'upper_limit' => 3.14),
            $this->context()
        );

        $this->assertSame(2.0, $result['data']['value']);
        $this->assertSame(0.0, $result['data']['lower_limit']);
        $this->assertSame(3.14, $result['data']['upper_limit']);
        $this->assertSame('Integral = 2', $result['data']['text']);
        $this->assertArrayNotHasKey('integral', $result['data']);
    }

    public function test_text_only_omits_the_latex_rendering(): void
    {
        $result = $this->tool()->execute(
            array('function' => 'x^2', 'format' => 'text'),
            $this->context()
        );

        $this->assertArrayNotHasKey('latex', $result['data']);
    }

    /** A signed-in caller. */
    private function context(): array
    {
        return array('user_id' => 7);
    }

    /** The answer the fake engine returns for indefinite requests. */
    private function indefiniteAnswer(): array
    {
        return array(
            'type' => 'indefinite',
            'antiderivative' => '(1/3)*x^3',
            'tex' => '\frac{x^{3}}{3}',
            'verified' => true,
            'samples' => 7,
        );
    }

    /**
     * An engine double.
     *
     * @param bool          $available Whether the engine is present.
     * @param callable|null $integral  Answer for integral(), when it should differ.
     */
    private function engine(bool $available = true, ?callable $integral = null): MathEngineInterface
    {
        $engine = $this->createMock(MathEngineInterface::class);
        $engine->method('isAvailable')->willReturn($available);
        $engine->method('integral')->willReturnCallback($integral ?? fn (): array => $this->indefiniteAnswer());

        return $engine;
    }

    /** The tool under test. */
    private function tool(?MathEngineInterface $engine = null): CalculateIntegralTool
    {
        return new CalculateIntegralTool($this->errors, $engine ?? $this->engine());
    }
}
