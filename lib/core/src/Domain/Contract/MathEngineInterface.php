<?php

/**
 * Symbolic computation, as `lib/core` is allowed to see it.
 *
 * Proposal 164 section 164.168. Three of the `math` family's remaining tools ask
 * for operations mathjs performs and a fourth (`render_math_equation`) is served
 * by its own renderer contract, so this interface covers exactly the three:
 * differentiation, simplification and linear algebra.
 *
 * ## Why not the whole family
 *
 * `calculate_integral` and `solve_equation` are absent on purpose. mathjs has no
 * symbolic integral — it is an open feature request upstream — and reaches only
 * polynomials for equation solving, through `rationalize` and
 * `polynomialRoot`. A method here that the engine cannot implement would be a
 * promise the contract makes and no adapter can keep, which is the defect this
 * whole track exists to remove. When a computer-algebra library lands, the two
 * methods arrive with it.
 *
 * ## One spelling per call, two spellings per answer
 *
 * Every method returns `text` and `tex` computed by the engine from a single
 * syntax tree. The WordPress tools asked the filter for one string and wrote it
 * into both fields, so a simplified `2 * x + 5` was reported as LaTeX and
 * rendered as source. Two spellings of one node cannot disagree; one spelling
 * copied twice can.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface MathEngineInterface
{
    /**
     * The WordPress error code for "no engine is available".
     *
     * Carried here so both runtimes log the same string, as
     * {@see MathRendererInterface::ERROR_UNAVAILABLE} is.
     */
    public const ERROR_UNAVAILABLE = 'wp_mcp_ai_engine_missing';

    /** The highest derivative order the tools offer. */
    public const MAX_ORDER = 5;

    /** The linear-algebra operations the engine performs. */
    public const OPERATIONS = array(
        'add',
        'subtract',
        'multiply',
        'transpose',
        'inverse',
        'determinant',
        'eigenvalues',
        'rank',
        'trace',
    );

    /** The operations that read a second matrix. */
    public const BINARY_OPERATIONS = array('add', 'subtract', 'multiply');

    /**
     * Whether this host has an engine to talk to.
     *
     * Config presence, not liveness.
     */
    public function isAvailable(): bool;

    /**
     * Differentiate an expression.
     *
     * @param string $expression The function to differentiate, unmodified.
     * @param string $variable   The variable to differentiate with respect to.
     * @param int    $order      Derivative order, 1 to {@see self::MAX_ORDER}.
     * @param bool   $simplify   Whether the engine should simplify the result.
     *
     * @return array{text: string, tex: string}
     *
     * @throws \InvalidArgumentException When the engine refuses the expression.
     * @throws \RuntimeException         When the engine is unavailable or fails.
     */
    public function derivative(string $expression, string $variable, int $order, bool $simplify): array;

    /**
     * Simplify an expression.
     *
     * @param string $expression The expression to simplify, unmodified.
     *
     * @return array{text: string, tex: string}
     *
     * @throws \InvalidArgumentException When the engine refuses the expression.
     * @throws \RuntimeException         When the engine is unavailable or fails.
     */
    public function simplify(string $expression): array;

    /**
     * Perform a linear-algebra operation.
     *
     * @param string                                  $operation One of {@see self::OPERATIONS}.
     * @param array<int, array<int, int|float>>        $a         The first matrix.
     * @param array<int, array<int, int|float>>|null   $b         The second, for a binary operation.
     *
     * @return array{result: int|float|array<int, int|float>|array<int, array<int, int|float>>, text: string, tex: string}
     *
     * @throws \InvalidArgumentException When the engine refuses the operation or the matrices.
     * @throws \RuntimeException         When the engine is unavailable or fails.
     */
    public function matrix(string $operation, array $a, ?array $b = null): array;

    /**
     * Solve `left = right` for one variable.
     *
     * Polynomial only: a linear, quadratic or higher-degree equation in a
     * single variable is solved exactly where the library can and numerically
     * otherwise. An equation that is not a polynomial, or that carries a second
     * symbol, is refused rather than approximated — the WordPress fallback
     * matched `3x^2 + 2 = 5` as `3x` and answered `x = 1`.
     *
     * @param string $left     Left-hand expression.
     * @param string $right    Right-hand expression.
     * @param string $variable The variable to solve for.
     *
     * @return array{solutions: array<int, int|float|array{re: int|float, im: int|float}>, degree: int, infinite: bool, text: string, tex: string}
     *
     * @throws \InvalidArgumentException When the equation is not a polynomial in that variable.
     * @throws \RuntimeException         When the engine is unavailable or fails.
     */
    public function solve(string $left, string $right, string $variable): array;

    /**
     * Integrate an expression, symbolically or over an interval.
     *
     * An indefinite result has been differentiated and checked against the
     * integrand before it is returned; `verified` reports that. A definite
     * result is a number, computed numerically where no elementary
     * antiderivative exists.
     *
     * @param string     $expression The integrand, unmodified.
     * @param string     $variable   The variable of integration.
     * @param string     $type       `indefinite` or `definite`.
     * @param float|null $lower      Lower limit, required when definite.
     * @param float|null $upper      Upper limit, required when definite.
     *
     * @return array{type: string, antiderivative: string|null, value: float|null, tex: string, verified: bool, samples: int}
     *
     * @throws \InvalidArgumentException When the engine cannot integrate or refuses the limits.
     * @throws \RuntimeException         When the engine is unavailable or fails.
     */
    public function integral(
        string $expression,
        string $variable,
        string $type,
        ?float $lower = null,
        ?float $upper = null
    ): array;
}
