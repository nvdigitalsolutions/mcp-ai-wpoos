<?php

/**
 * Perform linear algebra on matrices.
 *
 * Port of `WP_MCP_AI_Tool_Matrix_Operations` (Proposal 164 section 164.168).
 * Delegates to {@see MathEngineInterface}, which the Laravel runtime binds to the
 * media worker's `/api/math/matrix` route over mathjs.
 *
 * ## `eigenvalues` was a fatal, not an answer
 *
 * WordPress formatted every result through `matrix_to_latex()`, which assumed a
 * 2D array:
 *
 * ```php
 * foreach ( $matrix as $row ) {
 *     $rows[] = implode( ' & ', array_map( [ $this, 'format_number' ], $row ) );
 * }
 * ```
 *
 * For `eigenvalues` the filter returns a **vector** — and for `determinant`,
 * `rank` and `trace` a **scalar**. Iterating a scalar raises "foreach() argument
 * must be of type array|object" and `array_map()` on a scalar is a `TypeError`,
 * so those four operations either warned or fataled depending on the value. The
 * port asks the engine for the LaTeX of whatever shape came back, so a vector
 * renders as a column and a scalar renders as itself.
 *
 * ## `matrix_a` was never checked
 *
 * `$matrix_a = $arguments['matrix_a'];` — an absent key warned, and any value
 * reached the engine. The port requires a rectangular 2D array of finite
 * numbers, and requires `matrix_b` for the three binary operations before
 * spending a request, which the WordPress tool did do (by truthiness).
 *
 * ## `rank` is computed by the engine
 *
 * mathjs has no rank function; the worker route computes it by Gaussian
 * elimination. Nothing about that is visible here — the operation is in the
 * enum and either works or is refused.
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

class MatrixOperationsTool extends AbstractTool
{
    /** The shapes the answer is rendered in. */
    public const FORMATS = array('latex', 'text', 'both');

    /** The LaTeX operator for each operation. */
    private const SYMBOLS = array(
        'add' => '+',
        'subtract' => '-',
        'multiply' => '\\times',
        'transpose' => '^{T}',
        'inverse' => '^{-1}',
        'determinant' => '\\det',
        'eigenvalues' => '\\lambda',
        'rank' => '\\text{rank}',
        'trace' => '\\text{tr}',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly MathEngineInterface $engine,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'matrix_operations';
    }

    public function getName(): string
    {
        return 'Matrix Operations';
    }

    public function getDescription(): string
    {
        return 'Performs a linear-algebra operation on one or two matrices — add, subtract, multiply, transpose, inverse, determinant, eigenvalues, rank or trace — and returns the result with its LaTeX rendering.';
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
                'operation' => array(
                    'type'        => 'string',
                    'description' => 'The operation to perform.',
                    'enum'        => MathEngineInterface::OPERATIONS,
                ),
                'matrix_a'  => array(
                    'type'        => 'array',
                    'description' => 'The first matrix, as a 2D array of numbers, e.g. [[1,2],[3,4]].',
                    'items'       => array(
                        'type'  => 'array',
                        'items' => array('type' => 'number'),
                    ),
                ),
                'matrix_b'  => array(
                    'type'        => 'array',
                    'description' => 'The second matrix, required for add, subtract and multiply.',
                    'items'       => array(
                        'type'  => 'array',
                        'items' => array('type' => 'number'),
                    ),
                ),
                'format'    => array(
                    'type'        => 'string',
                    'description' => 'Which renderings to return. Default both.',
                    'enum'        => self::FORMATS,
                    'default'     => 'both',
                ),
            ),
            'required'             => array('operation', 'matrix_a'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $operation = strtolower(trim($this->stringParam($arguments, 'operation')));
        $format = $this->formatParam($arguments);
        $matrixA = $this->matrixParam($arguments, 'matrix_a');
        $matrixB = $this->matrixParam($arguments, 'matrix_b');
        $fields = array();

        if (! in_array($operation, MathEngineInterface::OPERATIONS, true)) {
            $fields['operation'] = array('One of: ' . implode(', ', MathEngineInterface::OPERATIONS) . '.');
        }

        if (array() === $matrixA) {
            $fields['matrix_a'] = array('A rectangular 2D array of finite numbers.');
        }

        $binary = in_array($operation, MathEngineInterface::BINARY_OPERATIONS, true);

        if ($binary && array() === $matrixB) {
            $fields['matrix_b'] = array(
                sprintf('A rectangular 2D array of finite numbers is required for %s.', $operation)
            );
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The matrix operation cannot be performed.', $fields);
        }

        if (! $this->engine->isAvailable()) {
            return $this->errors->create(
                MathEngineInterface::ERROR_UNAVAILABLE,
                'No symbolic engine is configured for this runtime.',
                array('engine' => 'mathjs')
            );
        }

        try {
            $result = $this->engine->matrix($operation, $matrixA, $binary ? $matrixB : null);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The matrices cannot be used for this operation.',
                array('matrix_a' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_math_engine_failed',
                $e->getMessage(),
                array('engine' => 'mathjs')
            );
        }

        $symbol = self::SYMBOLS[$operation] ?? '';
        $latexA = $this->matrixTex($matrixA);

        if ($binary && array() !== $matrixB) {
            $latex = $latexA . ' ' . $symbol . ' ' . $this->matrixTex($matrixB) . ' = ' . $result['tex'];
        } elseif ('transpose' === $operation || 'inverse' === $operation) {
            $latex = $latexA . $symbol . ' = ' . $result['tex'];
        } else {
            $latex = $symbol . '(' . $latexA . ') = ' . $result['tex'];
        }

        $data = array(
            'operation' => $operation,
            'matrix_a' => $matrixA,
            'result' => $result['result'],
            'text' => sprintf('Result: %s', $result['text']),
        );

        if (array() !== $matrixB) {
            $data['matrix_b'] = $matrixB;
        }

        if ('text' !== $format) {
            $data['latex'] = $latex;
        }

        return $this->success(sprintf('Matrix operation %s completed.', $operation), $data);
    }

    /**
     * A matrix, or an empty array when the value is not a rectangular numeric
     * array. Empty is the only "absent" this needs: an absent matrix and a
     * malformed one produce the same field error, and the message names both.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     *
     * @return list<list<int|float>>
     */
    private function matrixParam(array $arguments, string $key): array
    {
        if (! array_key_exists($key, $arguments)) {
            return array();
        }

        $value = $arguments[$key];

        if (! is_array($value) || array() === $value) {
            return array();
        }

        $rows = array_values($value);
        $first = $rows[0];

        if (! is_array($first) || array() === $first) {
            return array();
        }

        $width = count($first);
        $normalised = array();

        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== $width) {
                return array();
            }

            $cells = array();

            foreach (array_values($row) as $cell) {
                if (is_int($cell)) {
                    $cells[] = $cell;

                    continue;
                }

                if (! is_float($cell) || ! is_finite($cell)) {
                    return array();
                }

                $cells[] = $cell;
            }

            $normalised[] = $cells;
        }

        return $normalised;
    }

    /**
     * A matrix as a `bmatrix`.
     *
     * @param list<list<int|float>> $matrix The matrix.
     */
    private function matrixTex(array $matrix): string
    {
        $rows = array();

        foreach ($matrix as $row) {
            $cells = array();

            foreach ($row as $cell) {
                $cells[] = (string) $cell;
            }

            $rows[] = implode(' & ', $cells);
        }

        return '\\begin{bmatrix} ' . implode(' \\\\ ', $rows) . ' \\end{bmatrix}';
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
