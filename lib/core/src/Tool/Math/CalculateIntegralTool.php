<?php

/**
 * Integrate an expression, symbolically or over an interval.
 *
 * Port of `WP_MCP_AI_Tool_Calculate_Integral` (Proposal 164 section 164.171).
 * Delegates to {@see MathEngineInterface}, which the Laravel runtime binds to
 * the media worker's `/api/math/integral` route over nerdamer.
 *
 * ## It had no implementation at all
 *
 * The WordPress tool called `apply_filters( 'wp_mcp_ai_mathjs_integral', ... )`
 * and returned `wp_mcp_ai_engine_missing` whenever nothing was bound. Nothing
 * ever was, so the tool could only fail. Unlike `solve_equation`, which at least
 * had a fallback (a wrong one), this one had nothing to be wrong with.
 *
 * ## An antiderivative is proved before it is returned
 *
 * mathjs has no symbolic integrator, so the engine is a CAS. The one that is
 * maintained, nerdamer, demonstrably fails in two ways:
 *
 *     integrate('sin(x^2)', 'x')  ->  integrate(sin(x^2), x)     (unevaluated)
 *     integrate('e^(-x^2)', 'x')  ->  (1/2)*pi^(1/2)*erf*x       (malformed)
 *
 * The second looks like an answer. The worker therefore differentiates every
 * antiderivative and compares it against the integrand at several points, and
 * refuses anything that does not agree or cannot be checked. Nothing is returned
 * on the engine's word — the same rule that `simplify_expression` was ported
 * under in section 164.168.
 *
 * Definite integrals need no such proof: `defint` either yields a number or it
 * does not, and it integrates numerically where no elementary antiderivative
 * exists, so `e^(-x^2)` over `[0, 1]` comes back as a number rather than a
 * refusal.
 *
 * ## Corrections
 *
 *  - `sanitize_text_field()` was applied to the integrand, an HTML filter on a
 *    grammar (the fifth instance in this family);
 *  - `$arguments['function']` was read without `isset()`;
 *  - `lower_limit` and `upper_limit` went through `floatval()`, so `"abc"`
 *    became `0.0` and the integral was silently taken over the wrong interval;
 *  - `simplify` was accepted, passed to a filter nobody implemented, and ignored;
 *  - the LaTeX was assembled by concatenating the raw limits into a string, and
 *    the result was returned as HTML markup rather than data.
 *
 * Reversed limits are *not* rejected: `int_a^b = -int_b^a` is legitimate
 * mathematics, and the engine handles it.
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

class CalculateIntegralTool extends AbstractTool
{
    /** The shapes the answer is rendered in. */
    public const FORMATS = array('latex', 'text', 'both');

    /** The two modes. */
    public const TYPES = array('indefinite', 'definite');

    /** The largest integrand accepted, in bytes. */
    public const MAX_EXPRESSION_LENGTH = 2000;

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
        return 'calculate_integral';
    }

    public function getName(): string
    {
        return 'Calculate Integral';
    }

    public function getDescription(): string
    {
        return 'Integrates a function of one variable. Indefinite integrals return an antiderivative that has been differentiated and checked against the integrand; definite integrals return a value, computed numerically when no elementary antiderivative exists.';
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
                'function'    => array(
                    'type'        => 'string',
                    'description' => 'The function to integrate, e.g. "x^2 + 3x" or "sin(x)".',
                ),
                'variable'    => array(
                    'type'        => 'string',
                    'description' => 'The variable of integration. Default x.',
                ),
                'type'        => array(
                    'type'        => 'string',
                    'description' => 'indefinite or definite. Default indefinite.',
                    'enum'        => self::TYPES,
                ),
                'lower_limit' => array(
                    'type'        => 'number',
                    'description' => 'Lower limit. Required when type is definite.',
                ),
                'upper_limit' => array(
                    'type'        => 'number',
                    'description' => 'Upper limit. Required when type is definite.',
                ),
                'format'      => array(
                    'type'        => 'string',
                    'description' => 'Which renderings to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('function'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $expression = $this->rawString($arguments, 'function');
        $variable = $this->variableParam($arguments);
        $type = $this->typeParam($arguments);
        $format = $this->formatParam($arguments);
        $fields = array();

        if ('' === $expression) {
            $fields['function'] = array('A function to integrate is required.');
        } elseif (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            $fields['function'] = array(sprintf('At most %d characters.', self::MAX_EXPRESSION_LENGTH));
        }

        if (1 !== preg_match(self::VARIABLE_PATTERN, $variable)) {
            $fields['variable'] = array('A bare name such as x.');
        }

        if (! in_array($type, self::TYPES, true)) {
            $fields['type'] = array('One of: ' . implode(', ', self::TYPES) . '.');
        }

        $lower = null;
        $upper = null;

        if ('definite' === $type) {
            $lower = $this->limit($arguments, 'lower_limit', $fields);
            $upper = $this->limit($arguments, 'upper_limit', $fields);
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The integral cannot be computed.', $fields);
        }

        if (! $this->engine->isAvailable()) {
            return $this->errors->create(
                MathEngineInterface::ERROR_UNAVAILABLE,
                'No symbolic engine is configured for this runtime. The integral was not computed.',
                array('engine' => 'nerdamer')
            );
        }

        try {
            $solved = $this->engine->integral($expression, $variable, $type, $lower, $upper);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The integral cannot be computed.',
                array('function' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_math_engine_failed',
                $e->getMessage(),
                array('engine' => 'nerdamer')
            );
        }

        $data = array(
            'function' => $expression,
            'variable' => $variable,
            'type' => $type,
        );

        if ('definite' === $type) {
            $value = $solved['value'] ?? null;

            if (null === $value) {
                return $this->errors->create(
                    'wp_mcp_ai_math_engine_failed',
                    'The engine returned a definite integral without a value.'
                );
            }

            $data['value'] = (float) $value;
            $data['lower_limit'] = $lower;
            $data['upper_limit'] = $upper;
            $data['text'] = sprintf('Integral = %s', $this->number($value));
            $data['latex'] = $solved['tex'];
        } else {
            $antiderivative = $solved['antiderivative'] ?? null;

            if (null === $antiderivative) {
                return $this->errors->create(
                    'wp_mcp_ai_math_engine_failed',
                    'The engine returned an indefinite integral without an antiderivative.'
                );
            }

            $data['integral'] = $antiderivative;
            $data['verified'] = $solved['verified'];
            $data['samples'] = $solved['samples'];
            $data['text'] = sprintf('Integral = %s + C', $antiderivative);
            $data['latex'] = $solved['tex'];
        }

        if ('text' === $format) {
            unset($data['latex']);
        }

        return $this->success(
            sprintf('Integral calculated for: %s', $expression),
            $data
        );
    }

    /**
     * The integrand, as a string.
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
     * The mode, defaulting to indefinite.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function typeParam(array $arguments): string
    {
        $value = $this->stringParam($arguments, 'type');

        return '' === $value ? 'indefinite' : strtolower($value);
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
     * One limit, recording a field error when it is absent or unusable.
     *
     * `floatval('abc')` is `0.0`, which is how WordPress silently integrated
     * over the wrong interval. A non-numeric limit is an error here.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function limit(array $arguments, string $key, array &$fields): ?float
    {
        if (! array_key_exists($key, $arguments) || null === $arguments[$key]) {
            $fields[$key] = array('A definite integral needs both limits.');

            return null;
        }

        $raw = $arguments[$key];

        if (! is_numeric($raw)) {
            $fields[$key] = array('A finite number.');

            return null;
        }

        $value = (float) $raw;

        if (! is_finite($value)) {
            $fields[$key] = array('A finite number.');

            return null;
        }

        return $value;
    }

    /** Render a float without a trailing `.0`. */
    private function number(float $value): string
    {
        return floor($value) === $value && abs($value) < PHP_INT_MAX
            ? (string) (int) $value
            : (string) $value;
    }
}
