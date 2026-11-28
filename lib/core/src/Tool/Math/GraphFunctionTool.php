<?php

/**
 * Plot a function of one variable.
 *
 * Port of `WP_MCP_AI_Tool_Graph_Function` (Proposal 164 section 164.169).
 * Delegates sampling and rendering to {@see GraphRendererInterface}, which the
 * Laravel runtime binds to the media worker's `/api/math/graph` route, and
 * persists the PNG through {@see FileStoreInterface}.
 *
 * ## It never drew anything
 *
 * WordPress implemented this tool as a filter and nothing else:
 *
 * ```php
 * $graph_result = apply_filters( 'wp_mcp_ai_graph_function', false, array( ... ) );
 *
 * if ( false === $graph_result || isset( $graph_result['error'] ) ) {
 *     return new WP_Error( 'wp_mcp_ai_engine_missing', ... );
 * }
 * ```
 *
 * No filter was registered anywhere in the plugin, so on every install the tool
 * could only return the error branch. It is the same shape as the
 * `simplify_expression` defect (section 164.168) but with no fallback at all:
 * the tool advertised a capability, required `upload_files` for it, and could
 * not produce an image. The port carries the operation rather than the hook.
 *
 * ## Six input defects, all fixed here
 *
 *  - the expression went through `sanitize_text_field()`, which is an HTML
 *    filter applied to a grammar (the third such instance in this family);
 *  - `$arguments['function']` was read without `isset()`, so a missing required
 *    argument raised an undefined-key warning before the error branch;
 *  - `width` and `height` were `absint()`ed with no bound, though the schema
 *    declares 400–2000 and 300–1500 — a caller could ask for a million pixels;
 *  - `show_grid` was read with `(bool)`, for which the string `'false'` is true;
 *  - `sanitize_hex_color()` returns null for an invalid colour and the null was
 *    passed straight through to the renderer;
 *  - `x_range` was indexed as `[0]` and `[1]` with no shape check, so a short
 *    array produced warnings in the message string.
 *
 * Capability is `upload_files`, exactly as WordPress declared it: the tool
 * writes a file into the store.
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
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\GraphRendererInterface;
use Nvoos\Core\Domain\ValueObject\GraphRequest;
use Nvoos\Core\Tool\AbstractTool;
use RuntimeException;
use Throwable;

class GraphFunctionTool extends AbstractTool
{
    /** The largest expression accepted, in bytes. Matches the other math tools. */
    public const MAX_EXPRESSION_LENGTH = 2000;

    /** Width bounds, matching the WordPress schema. */
    public const MIN_WIDTH = 400;
    public const MAX_WIDTH = 2000;

    /** Height bounds, matching the WordPress schema. */
    public const MIN_HEIGHT = 300;
    public const MAX_HEIGHT = 1500;

    /** The colour the WordPress tool defaulted to. */
    public const DEFAULT_LINE_COLOR = '#0066cc';

    /** The x window used when none is supplied. */
    public const DEFAULT_X_RANGE = array(-10.0, 10.0);

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly GraphRendererInterface $renderer,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'graph_function';
    }

    public function getName(): string
    {
        return 'Graph Function';
    }

    public function getDescription(): string
    {
        return 'Draws the graph of a function of one variable over a chosen x window and returns it as a PNG image. Handles poles and domain edges as gaps rather than failing the whole plot.';
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'function'   => array(
                    'type'        => 'string',
                    'description' => 'The function to graph, e.g. "x^2" or "sin(x)/x".',
                ),
                'x_range'    => array(
                    'type'        => 'array',
                    'description' => 'X-axis window as [min, max]. Default [-10, 10].',
                    'items'       => array('type' => 'number'),
                    'minItems'    => 2,
                    'maxItems'    => 2,
                ),
                'y_range'    => array(
                    'type'        => 'array',
                    'description' => 'Y-axis window as [min, max]. Derived from the sampled values when omitted.',
                    'items'       => array('type' => 'number'),
                    'minItems'    => 2,
                    'maxItems'    => 2,
                ),
                'title'      => array(
                    'type'        => 'string',
                    'description' => 'Heading drawn into the image.',
                ),
                'width'      => array(
                    'type'        => 'integer',
                    'description' => 'Image width in pixels. Default 800.',
                    'minimum'     => self::MIN_WIDTH,
                    'maximum'     => self::MAX_WIDTH,
                ),
                'height'     => array(
                    'type'        => 'integer',
                    'description' => 'Image height in pixels. Default 600.',
                    'minimum'     => self::MIN_HEIGHT,
                    'maximum'     => self::MAX_HEIGHT,
                ),
                'show_grid'  => array(
                    'type'        => 'boolean',
                    'description' => 'Whether to draw grid lines. Default true.',
                ),
                'line_color' => array(
                    'type'        => 'string',
                    'description' => 'Line colour as hex, e.g. "#0066cc".',
                ),
            ),
            'required'             => array('function'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'upload_files');

        $expression = $this->rawString($arguments, 'function');
        $fields = array();

        if ('' === $expression) {
            $fields['function'] = array('A function to graph is required.');
        } elseif (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            $fields['function'] = array(sprintf('At most %d characters.', self::MAX_EXPRESSION_LENGTH));
        }

        		$xRange = $this->range($arguments['x_range'] ?? null);

        		if (null === $xRange) {
        			if (array_key_exists('x_range', $arguments)) {
        				$fields['x_range'] = array('An array of two finite numbers with max greater than min.');
        			}

        			// Also the placeholder for the invalid case: the value is never read
        			// because a non-empty $fields returns below, and keeping the variable a
        			// plain array is what lets the construction below be type-checked.
        			$xRange = self::DEFAULT_X_RANGE;
        		}

        $yRange = null;

        if (array_key_exists('y_range', $arguments) && null !== $arguments['y_range']) {
            $yRange = $this->range($arguments['y_range']);

            if (null === $yRange) {
                $fields['y_range'] = array('An array of two finite numbers with max greater than min.');
            }
        }

        $width = $this->intParam($arguments, 'width', 800);

        if ($width < self::MIN_WIDTH || $width > self::MAX_WIDTH) {
            $fields['width'] = array(sprintf('%d-%d pixels.', self::MIN_WIDTH, self::MAX_WIDTH));
        }

        $height = $this->intParam($arguments, 'height', 600);

        if ($height < self::MIN_HEIGHT || $height > self::MAX_HEIGHT) {
            $fields['height'] = array(sprintf('%d-%d pixels.', self::MIN_HEIGHT, self::MAX_HEIGHT));
        }

        $lineColor = $this->lineColor($arguments, $fields);

        if (array() !== $fields) {
            return $this->errors->validationFailed('The graph cannot be drawn.', $fields);
        }

        if (! $this->renderer->isAvailable()) {
            return $this->errors->create(
                GraphRendererInterface::ERROR_UNAVAILABLE,
                'No graph renderer is configured for this runtime. The function was not plotted.',
                array('engine' => 'chartjs')
            );
        }

        $request = new GraphRequest(
            expression: $expression,
            xRange: $xRange,
            yRange: $yRange,
            width: $width,
            height: $height,
            showGrid: $this->boolParam($arguments, 'show_grid', true),
            lineColor: $lineColor,
            title: $this->stringParam($arguments, 'title'),
        );

        try {
            $rendered = $this->renderer->graph($request);
        } catch (InvalidArgumentException $e) {
            return $this->errors->validationFailed(
                'The function cannot be plotted.',
                array('function' => array($e->getMessage()))
            );
        } catch (RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_graph_failed',
                $e->getMessage(),
                array('engine' => 'chartjs')
            );
        }

        return $this->persist($rendered, $request, $context);
    }

    /**
     * Store the rendered PNG and describe it the way WordPress did.
     *
     * `attachment_id`, `url` and `prompt` keep their WordPress names so an
     * exported assistant keeps working; `prompt` was a description rather than
     * an image prompt in the original too.
     *
     * @param array<string, mixed> $rendered Renderer answer.
     * @param array<string, mixed> $context  Execution context.
     */
    private function persist(array $rendered, GraphRequest $request, array $context): mixed
    {
        $data = $rendered['data'] ?? null;

        if (! is_string($data) || '' === $data) {
            return $this->errors->create(
                'wp_mcp_ai_graph_failed',
                'The graph renderer returned no image data.'
            );
        }

        $userId = (int) ( $context['user_id'] ?? $context['owner_id'] ?? 0 );

        if (null === $this->files || $userId <= 0) {
            return $this->errors->create(
                'wp_mcp_ai_graph_storage_unavailable',
                'No file store is bound for this runtime, so the graph cannot be attached.'
            );
        }

        $bytes = base64_decode($data, true);

        if (false === $bytes) {
            return $this->errors->create(
                'wp_mcp_ai_graph_failed',
                'The graph renderer returned image data that is not base64.'
            );
        }

        $temp = tempnam(sys_get_temp_dir(), 'nvoos-graph-');

        if (false === $temp) {
            return $this->errors->create(
                'wp_mcp_ai_graph_failed',
                'Could not create a temporary file for the graph.'
            );
        }

        try {
            if (false === file_put_contents($temp, $bytes)) {
                return $this->errors->create(
                    'wp_mcp_ai_graph_failed',
                    'Could not write the graph image.'
                );
            }

            $file = $this->files->store(
                $temp,
                sprintf('graph-%s.png', gmdate('Ymd-His')),
                'image/png',
                $userId
            );
        } catch (Throwable $error) {
            return $this->errors->create('wp_mcp_ai_graph_failed', $error->getMessage());
        } finally {
            @unlink($temp);
        }

        $data = array(
            'function'       => $request->expression,
            'attachment_id'  => $file->id,
            'url'            => $file->publicUrl,
            'width'          => (int) ( $rendered['width'] ?? $request->width ),
            'height'         => (int) ( $rendered['height'] ?? $request->height ),
            'x_range'        => $rendered['x_range'] ?? $request->xRange,
            'y_range'        => $rendered['y_range'] ?? $request->yRange,
            'samples'        => (int) ( $rendered['samples'] ?? 0 ),
            'finite_samples' => (int) ( $rendered['finite_samples'] ?? 0 ),
            'prompt'         => sprintf(
                'Graph of %s from x=%s to x=%s',
                $request->expression,
                $this->number($request->xRange[0]),
                $this->number($request->xRange[1])
            ),
            'text'           => sprintf('Function graphed: %s', $request->expression),
        );

        return $this->success(
            sprintf('Graph generated for function: %s', $request->expression),
            $data
        );
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
     * A validated [min, max] pair, or null.
     *
     * @param mixed $value Raw range.
     *
     * @return array{0: float, 1: float}|null
     */
    private function range(mixed $value): ?array
    {
        if (! is_array($value) || 2 !== count($value)) {
            return null;
        }

        $values = array_values($value);
        $min = is_numeric($values[0]) ? (float) $values[0] : null;
        $max = is_numeric($values[1]) ? (float) $values[1] : null;

        if (null === $min || null === $max || ! is_finite($min) || ! is_finite($max)) {
            return null;
        }

        return $max > $min ? array($min, $max) : null;
    }

    /**
     * The line colour as six-digit hex, recording a field error when unusable.
     *
     * An absent colour takes the default. A colour that is present but is not
     * hex is reported rather than replaced: WordPress passed `null` through to
     * the renderer, which drew in whatever the library chose.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     * @param array<string, mixed> $fields    Field errors, by reference.
     */
    private function lineColor(array $arguments, array &$fields): string
    {
        if (! array_key_exists('line_color', $arguments) || null === $arguments['line_color']) {
            return self::DEFAULT_LINE_COLOR;
        }

        $raw = is_string($arguments['line_color']) ? trim($arguments['line_color']) : '';

        if (1 !== preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw, $match)) {
            $fields['line_color'] = array('A hex colour such as #0066cc.');

            return self::DEFAULT_LINE_COLOR;
        }

        $hex = $match[1];

        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /** Render a float without a trailing `.0` in the prompt string. */
    private function number(float $value): string
    {
        return floor($value) === $value && abs($value) < PHP_INT_MAX
            ? (string) (int) $value
            : (string) $value;
    }
}
