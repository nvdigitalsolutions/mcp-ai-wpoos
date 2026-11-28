<?php

/**
 * Differentiate an expression symbolically.
 *
 * Port of `WP_MCP_AI_Tool_Calculate_Derivative` (Proposal 164 section 164.168).
 * Delegates to {@see MathEngineInterface}, which the Laravel runtime binds to the
 * media worker's `/api/math/derivative` route over mathjs.
 *
 * ## What was wrong with the WordPress tool
 *
 * It sanitised the function with `sanitize_text_field()`, an HTML filter, so
 * `a < b` and `\langle` lost characters before any parser saw them — the same
 * defect as `generate_truth_table` and `render_math_equation`, in a third
 * library. It read `order` with `absint()` and no bound, although its own schema
 * declares 1 to 5, so order 0 asked mathjs for a zeroth derivative and order 99
 * asked for ninety-nine passes over the tree. It read `simplify` with `(bool)`,
 * where the string `'false'` is true. And an unknown `format` was not refused;
 * it silently omitted the LaTeX.
 *
 * The port reads the expression unmodified, enforces the order the schema
 * promises, reads `simplify` through `boolParam()`, and refuses an unknown
 * format.
 *
 * ## The LaTeX is the derivative, not a copy of it
 *
 * WordPress built the LaTeX by pasting the filter's string between
 * `\frac{d}{dx}(` and `) =`, which was correct as far as it went. The port does
 * the same with the engine's `tex` spelling, so the notation is the caller's and
 * the expression is the engine's.
 *
 * Gated on `read`, matching the WordPress declaration and its handler.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Math;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathEngineInterface;
use Nvoos\Core\Tool\AbstractTool;
use RuntimeException;
use InvalidArgumentException;

class CalculateDerivativeTool extends AbstractTool
{
    /** The shapes the answer is rendered in. */
    public const FORMATS = array('latex', 'text', 'both');

    /** The largest expression accepted, in bytes. */
    public const MAX_EXPRESSION_LENGTH = 2000;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly MathEngineInterface $engine,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'calculate_derivative';
    }

    public function getName(): string
    {
        return 'Calculate Derivative';
    }

    public function getDescription(): string
    {
        return 'Differentiates a function symbolically with respect to one variable, up to the fifth order, and returns the derivative as plain text and, on request, as LaTeX.';
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
                'function' => array(
                    'type'        => 'string',
                    'description' => 'The function to differentiate, e.g. "x^2 + 3x + 5" or "sin(x) * e^x".',
                ),
                'variable' => array(
                    'type'        => 'string',
                    'description' => 'The variable to differentiate with respect to. Default x.',
                    'default'     => 'x',
                ),
                'order'    => array(
                    'type'        => 'integer',
                    'description' => 'Derivative order, 1 to 5. Default 1.',
                    'default'     => 1,
                    'minimum'     => 1,
                    'maximum'     => MathEngineInterface::MAX_ORDER,
                ),
                'simplify' => array(
                    'type'        => 'boolean',
                    'description' => 'Ask the engine to simplify the result. Default true.',
                    'default'     => true,
                ),
                'format'   => array(
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

        $function = $this->expressionParam($arguments);
        $variable = $this->variableParam($arguments);
        $order = $this->orderParam($arguments);
        $format = $this->formatParam($arguments);
        $simplify = $this->boolParam($arguments, 'simplify', true);
        $fields = array();

        if ('' === $function) {
            $fields['function'] = array('A function to differentiate is required.');
        } elseif (strlen($function) > self::MAX_EXPRESSION_LENGTH) {
            $fields['function'] = array(sprintf('At most %d characters.', self::MAX_EXPRESSION_LENGTH));
        }

        if ('' === $variable) {
            $fields['variable'] = array('A variable name.');
        }

        if ($order < 1 || $order > MathEngineInterface::MAX_ORDER) {
            $fields['order'] = array(sprintf('Between 1 and %d.', MathEngineInterface::MAX_ORDER));
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The derivative cannot be calculated.', $fields);
        }

        if (! $this->engine->isAvailable()) {
            return $this->errors->create(
                MathEngineInterface::ERROR_UNAVAILABLE,
                'No symbolic engine is configured for this runtime.',
                array('engine' => 'mathjs')
            );
        }

        try {
            $derived = $this->engine->derivative($function, $variable, $order, $simplify);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The expression cannot be differentiated.',
                array('function' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_math_engine_failed',
                $e->getMessage(),
                array('engine' => 'mathjs')
            );
        }

        $notation = 1 === $order
            ? '\\frac{d}{d' . $variable . '}'
            : '\\frac{d^{' . $order . '}}{d' . $variable . '^{' . $order . '}}';

        $data = array(
            'function' => $function,
            'variable' => $variable,
            'order' => $order,
            'simplified' => $simplify,
            'derivative' => $derived['text'],
            'text' => sprintf('d/d%1$s(%2$s) = %3$s', $variable, $function, $derived['text']),
        );

        if ('text' !== $format) {
            $data['latex'] = $notation . ' (' . $function . ') = ' . $derived['tex'];
        }

        return $this->success(
            sprintf('Differentiated %1$s with respect to %2$s, order %3$d.', $function, $variable, $order),
            $data
        );
    }

    /**
     * The expression, as a string.
     *
     * Not `stringParam()`: that helper strips tags, and an HTML filter corrupts
     * comparisons and angle brackets in a grammar.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function expressionParam(array $arguments): string
    {
        $value = $arguments['function'] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The variable, defaulting to x.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function variableParam(array $arguments): string
    {
        $variable = trim($this->stringParam($arguments, 'variable'));

        if ('' === $variable) {
            return 'x';
        }

        return 1 === preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $variable) ? $variable : '';
    }

    /**
     * The order, -1 when malformed.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function orderParam(array $arguments): int
    {
        $value = $arguments['order'] ?? 1;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return -1;
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
}
