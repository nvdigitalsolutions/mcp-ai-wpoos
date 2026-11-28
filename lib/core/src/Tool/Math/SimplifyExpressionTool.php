<?php

/**
 * Simplify an expression algebraically.
 *
 * Port of `WP_MCP_AI_Tool_Simplify_Expression` (Proposal 164 section 164.168).
 * Delegates to {@see MathEngineInterface}, which the Laravel runtime binds to the
 * media worker's `/api/math/simplify` route over mathjs.
 *
 * ## It reported success for work it never did
 *
 * When the filter had no implementation, WordPress fell back to
 * `simple_simplify()`, which collapsed runs of whitespace and returned the input:
 *
 * ```php
 * if ( false === $math_result || isset( $math_result['error'] ) ) {
 *     $simplified = $this->simple_simplify( $expression );
 * }
 * ```
 *
 * and then returned `success => true` with that string as `simplified`. So
 * `2x + 3x - 5 + 2` came back as `2x + 3x - 5 + 2` — unchanged — under a success
 * envelope and a message saying it had been simplified. A caller had no way to
 * tell. This is the same class of defect the readiness manifest was built to
 * catch: a tool reporting work it did not do. The port never falls back; an
 * unavailable engine is named, and an unparseable expression is a validation
 * failure.
 *
 * ## `latex` was the plain-text result
 *
 * WordPress set `latex` to the simplified string, which for anything but the
 * simplest expression is not LaTeX — mathjs prints `2 * x + 5`, and a quotient
 * prints as `x / 2`. The port asks the engine for both spellings of one node, so
 * `latex` contains LaTeX.
 *
 * ## The `rules` argument is gone
 *
 * WordPress offered `default`, `all`, `collect` and `distribute`, and passed them
 * to a filter nobody implemented, so no value ever did anything. They are not
 * mathjs rule names either. The engine performs its standard simplification,
 * which is what `default` meant, so the argument is omitted rather than accepted
 * and ignored.
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

use InvalidArgumentException;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathEngineInterface;
use Nvoos\Core\Tool\AbstractTool;
use RuntimeException;

class SimplifyExpressionTool extends AbstractTool
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
        return 'simplify_expression';
    }

    public function getName(): string
    {
        return 'Simplify Expression';
    }

    public function getDescription(): string
    {
        return 'Rewrites an algebraic expression in simplest form, combining like terms and applying standard simplification, and returns the result as plain text and, on request, as LaTeX.';
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
                'expression' => array(
                    'type'        => 'string',
                    'description' => 'The expression to simplify, e.g. "2x + 3x - 5 + 2" or "(x + 2)(x - 3)".',
                ),
                'format'     => array(
                    'type'        => 'string',
                    'description' => 'Which renderings to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('expression'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $expression = $this->expressionParam($arguments);
        $format = $this->formatParam($arguments);
        $fields = array();

        if ('' === $expression) {
            $fields['expression'] = array('An expression to simplify is required.');
        } elseif (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            $fields['expression'] = array(sprintf('At most %d characters.', self::MAX_EXPRESSION_LENGTH));
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The expression cannot be simplified.', $fields);
        }

        if (! $this->engine->isAvailable()) {
            return $this->errors->create(
                MathEngineInterface::ERROR_UNAVAILABLE,
                'No symbolic engine is configured for this runtime. The expression was not simplified.',
                array('engine' => 'mathjs')
            );
        }

        try {
            $simplified = $this->engine->simplify($expression);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The expression cannot be parsed.',
                array('expression' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_math_engine_failed',
                $e->getMessage(),
                array('engine' => 'mathjs')
            );
        }

        $data = array(
            'original_expression' => $expression,
            'simplified' => $simplified['text'],
            'changed' => $simplified['text'] !== $expression,
            'text' => sprintf('Simplified: %s', $simplified['text']),
        );

        if ('text' !== $format) {
            $data['latex'] = $simplified['tex'];
        }

        return $this->success(sprintf('Simplified %s.', $expression), $data);
    }

    /**
     * The expression, as a string.
     *
     * Not `stringParam()`: an HTML filter corrupts a grammar.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function expressionParam(array $arguments): string
    {
        $value = $arguments['expression'] ?? '';

        return is_string($value) ? $value : '';
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
