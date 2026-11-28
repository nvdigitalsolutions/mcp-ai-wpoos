<?php

/**
 * Solve a polynomial equation for one variable.
 *
 * Port of `WP_MCP_AI_Tool_Solve_Equation` (Proposal 164 section 164.170).
 * Delegates to {@see MathEngineInterface}, which the Laravel runtime binds to
 * the media worker's `/api/math/solve` route over mathjs.
 *
 * ## The fallback answered questions that were not asked
 *
 * WordPress called a filter, and when no filter was registered it fell back to
 * `solve_simple_linear()`. That fallback matched `([+-]?\d*\.?\d*)\s*x\s*([+-]?\d+\.?\d*)?`
 * against the left-hand side and ignored everything it did not match. So
 * `3x^2 + 2 = 5` was read as `3x + 2 = 5` and answered `x = 1` under a success
 * envelope, with no indication that the exponent had been discarded. A caller
 * had no way to tell a correct answer from a silently truncated one.
 *
 * The port has no fallback. A non-polynomial, a second symbol, or a variable
 * that does not appear in the equation is a typed refusal naming the reason.
 *
 * ## `show_steps` is gone
 *
 * The parameter promised "step-by-step explanations when available" and no
 * implementation anywhere produced one. Like the `rules` argument dropped from
 * `simplify_expression` in section 164.168, it was accepted and ignored, so it
 * is omitted rather than accepted and ignored again. Producing real steps is a
 * separate feature, not a parameter.
 *
 * ## Smaller corrections
 *
 *  - `sanitize_text_field()` was applied to the equation, an HTML filter on a
 *    grammar (the fourth instance in this family);
 *  - `$arguments['equation']` was read without `isset()`;
 *  - `variable` was sanitised but never checked to be an identifier, though it
 *    is interpolated into expressions by every downstream engine;
 *  - `explode( '=', ... )` accepted `x = 2 = 3` as two parts when the split
 *    produced three, because only the count was checked;
 *  - `format_number()` used `number_format( $num, 4 )`, truncating every answer
 *    to four decimals and losing exactness for anything but small rationals.
 *
 * Capability is `read`, as WordPress declared it: solving writes nothing.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Math;

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathEngineInterface;
use Nvoos\Core\Tool\AbstractTool;
use RuntimeException;

class SolveEquationTool extends AbstractTool
{
    /** The shapes the answer is rendered in. */
    public const FORMATS = array('latex', 'text', 'both');

    /** The largest equation accepted, in bytes. */
    public const MAX_EQUATION_LENGTH = 2000;

    /** A bare identifier: the variable is interpolated into expressions. */
    public const VARIABLE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly MathEngineInterface $engine,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'solve_equation';
    }

    public function getName(): string
    {
        return 'Solve Equation';
    }

    public function getDescription(): string
    {
        return 'Solves a polynomial equation in one variable, of any degree, and returns every root including complex ones. Equations that are not polynomials are refused rather than approximated.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'equation' => array(
                    'type'        => 'string',
                    'description' => 'The equation, with one equals sign, e.g. "2x + 5 = 15" or "x^2 - 4 = 0".',
                ),
                'variable' => array(
                    'type'        => 'string',
                    'description' => 'The variable to solve for. Default x.',
                ),
                'format'   => array(
                    'type'        => 'string',
                    'description' => 'Which renderings to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('equation'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $equation = $this->rawString($arguments, 'equation');
        $variable = $this->variableParam($arguments);
        $format = $this->formatParam($arguments);
        $fields = array();

        if ('' === $equation) {
            $fields['equation'] = array('An equation to solve is required.');
        } elseif (strlen($equation) > self::MAX_EQUATION_LENGTH) {
            $fields['equation'] = array(sprintf('At most %d characters.', self::MAX_EQUATION_LENGTH));
        }

        		$sides = $this->sides($equation);

        		if (null === $sides) {
        			$fields['equation'] = array('One equals sign with an expression on each side, e.g. "2x + 5 = 15".');

        			// Placeholder: never read, because a non-empty $fields returns below,
        			// and keeping the variable a plain array is what lets the call below
        			// be type-checked.
        			$sides = array('', '');
        		}

        if (1 !== preg_match(self::VARIABLE_PATTERN, $variable)) {
            $fields['variable'] = array('A bare name such as x.');
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The equation cannot be solved.', $fields);
        }

        if (! $this->engine->isAvailable()) {
            return $this->errors->create(
                MathEngineInterface::ERROR_UNAVAILABLE,
                'No symbolic engine is configured for this runtime. The equation was not solved.',
                array('engine' => 'mathjs')
            );
        }

        try {
            $solved = $this->engine->solve($sides[0], $sides[1], $variable);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The equation cannot be solved.',
                array('equation' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_math_engine_failed',
                $e->getMessage(),
                array('engine' => 'mathjs')
            );
        }

        $data = array(
            'equation' => $equation,
            'variable' => $variable,
            'solutions' => $solved['solutions'],
            'degree' => $solved['degree'],
            'infinite_solutions' => $solved['infinite'],
            'solution_text' => $solved['text'],
            'text' => sprintf('Solutions: %s', $solved['text']),
        );

        if ('text' !== $format) {
            $data['latex'] = $solved['tex'];
        }

        return $this->success(sprintf('Equation solved: %s', $equation), $data);
    }

    /**
     * The expression, as a string.
     *
     * Not `stringParam()`: an HTML filter corrupts a grammar.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function rawString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The variable, defaulting to x.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function variableParam(array $arguments): string
    {
        $value = $this->stringParam($arguments, 'variable');

        return '' === $value ? 'x' : $value;
    }

    /**
     * The requested format, lower-cased.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function formatParam(array $arguments): string
    {
        $format = $this->stringParam($arguments, 'format');

        return '' === $format ? 'both' : strtolower($format);
    }

    /**
     * The two sides of the equation, or null when it is not shaped like one.
     *
     * `explode()` without a limit accepts `x = 2 = 3` and hands back three
     * parts; requiring exactly two and rejecting empties is what makes the
     * shape check real.
     *
     * @return array{0: string, 1: string}|null
     */
    private function sides(string $equation): ?array
    {
        $parts = explode('=', $equation);

        if (2 !== count($parts)) {
            return null;
        }

        $left = trim($parts[0]);
        $right = trim($parts[1]);

        if ('' === $left || '' === $right) {
            return null;
        }

        return array($left, $right);
    }
}
