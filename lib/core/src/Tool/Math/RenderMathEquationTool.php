<?php

/**
 * Render a LaTeX equation to HTML or MathML.
 *
 * Port of `WP_MCP_AI_Tool_Render_Math_Equation` (Proposal 164 section 164.167).
 * The third `math` tool to arrive, and the first that needed nothing new on the
 * worker: `POST /api/data/render-math` has existed since the sidecar's data
 * group shipped.
 *
 * ## The sidecar was sent the wrong option names
 *
 * WordPress built its payload as `array( 'latex' => $latex, 'options' => $arguments )`
 * — the raw tool arguments, whose rendering options are spelled `display_mode`
 * and `output_format`. The route reads `options.displayMode` and
 * `options.output`, so every request fell through to the route's defaults:
 * always display mode, always HTML. A caller asking for inline rendering got a
 * centred block; a caller asking for MathML got `html`; nobody was told. Worse,
 * passing `html_and_mathml` through unchanged would have failed the route
 * outright, because KaTeX calls the combined shape `htmlAndMathml` and rejects
 * anything else with a settings error that `throwOnError` does not catch.
 *
 * ## `wp_kses_post` rewrote the expression
 *
 * The WordPress tool sanitised the input with `wp_kses_post()`, which is an HTML
 * filter: it escapes angle brackets, so `a < b` and `\langle` were corrupted
 * before a parser saw them, and `&` became an entity. LaTeX is a grammar, not
 * markup. The port passes the expression through untouched and lets KaTeX be
 * the parser and the sanitiser — its `trust` option defaults to false, which
 * leaves `\href` and `\includegraphics` inert.
 *
 * ## The availability gate refused before the sidecar was asked
 *
 * `check_katex_availability()` required a vendored `katex.min.js` **and** the
 * `node` command on the host, and ran before `render_with_katex()` was called.
 * A site that routes rendering to the worker — the whole point of the sidecar —
 * therefore refused with `wp_mcp_ai_engine_missing` unless it also shipped a
 * local copy it would never use. The port asks the renderer whether it is
 * available, and never inspects the filesystem.
 *
 * ## Four smaller corrections
 *
 * The tool was gated on `enable_quiz_system`, although rendering does not depend
 * on the quiz toolkit; the quiz association is now opt-in through `quiz_id`.
 * `throw_on_error` is gone from the schema, because the route hard-codes
 * `throwOnError: false` and an option that can only be false is not an option. A
 * non-empty `macros` map is refused by name rather than passed to a route that
 * ignores it. And the rendered string is returned as `markup` rather than
 * `html`, because it held MathML whenever MathML was requested — the old key
 * lied about its contents.
 *
 * Two response-shape notes. `render_time` was `"1.23ms"` on a render and the
 * integer `0` on a cache hit; it is now `render_time_ms`, a float, always. The
 * cache itself is not reproduced — WordPress keyed it on
 * `md5( $latex . serialize( $arguments ) )`, so `quiz_id` and `save_rendered`
 * fragmented the cache and a caller-supplied object would have warned during
 * `serialize()`. `cached` stays in the response and is always false: caching is
 * a host concern.
 *
 * The quiz association wrote `_katex_equations` meta on whatever id it was
 * given, without checking that the item existed, which is how orphaned meta
 * rows are made. The port requires the item, and reports how many equations the
 * quiz now holds.
 *
 * Gated on `edit_posts`, matching the WordPress declaration and its handler.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Math;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MathRendererInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\AbstractTool;

class RenderMathEquationTool extends AbstractTool
{
    /** The shapes an equation can be rendered to. */
    public const FORMATS = MathRendererInterface::OUTPUTS;

    /** The row key the quiz association appends to. */
    public const QUIZ_META_KEY = '_katex_equations';

    /**
     * The largest expression accepted, in bytes.
     *
     * KaTeX is CPU-bound and the route has no per-expression bound of its own,
     * so this is the caller-side limit that keeps one request from becoming a
     * denial of service.
     */
    public const MAX_LATEX_LENGTH = 20000;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly MathRendererInterface $renderer,
        private readonly ?ContentStoreInterface $content = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'render_math_equation';
    }

    public function getName(): string
    {
        return 'Render Math Equation';
    }

    public function getDescription(): string
    {
        return 'Renders a LaTeX expression to HTML, MathML or both, in display or inline mode, and optionally appends the result to a quiz record. The expression is passed to the renderer unmodified.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'latex'         => array(
                    'type'        => 'string',
                    'description' => 'The LaTeX expression to render, e.g. "x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}".',
                ),
                'display_mode'  => array(
                    'type'        => 'boolean',
                    'description' => 'True for a centred block, false for inline. Default false.',
                    'default'     => false,
                ),
                'output_format' => array(
                    'type'        => 'string',
                    'description' => 'Which markup to return. Default html.',
                    'enum'        => self::FORMATS,
                    'default'     => 'html',
                ),
                'macros'        => array(
                    'type'        => 'object',
                    'description' => 'Custom LaTeX macros as key-value pairs. The configured renderer applies no macros, so a non-empty map is refused rather than ignored.',
                ),
                'quiz_id'       => array(
                    'type'        => 'integer',
                    'description' => 'Optional content item to append the rendered equation to.',
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('latex'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $latex = $this->latexParam($arguments);
        $format = $this->formatParam($arguments);
        $displayMode = $this->boolParam($arguments, 'display_mode', false);
        $quizId = $this->quizIdParam($arguments);
        $fields = array();

        if ('' === $latex) {
            $fields['latex'] = array('A LaTeX expression is required.');
        } elseif (strlen($latex) > self::MAX_LATEX_LENGTH) {
            $fields['latex'] = array(
                sprintf('At most %d characters.', self::MAX_LATEX_LENGTH),
            );
        }

        if (! in_array($format, self::FORMATS, true)) {
            $fields['output_format'] = array('One of: ' . implode(', ', self::FORMATS) . '.');
        }

        $fields = $this->macroProblems($arguments, $fields);

        if ($quizId < 0) {
            $fields['quiz_id'] = array('A positive content item id.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The equation cannot be rendered.', $fields);
        }

        if (! $this->renderer->isAvailable()) {
            return $this->errors->create(
                MathRendererInterface::ERROR_UNAVAILABLE,
                'No LaTeX renderer is configured for this runtime.',
                array('engine' => 'katex')
            );
        }

        $store = $this->content;
        $quiz = null;

        if ($quizId > 0) {
            if (null === $store) {
                return $this->errors->create(
                    'wp_mcp_ai_quiz_store_unavailable',
                    'This runtime cannot attach a rendered equation to a content item.',
                    array('quiz_id' => $quizId)
                );
            }

            $quiz = $store->find($quizId);

            if (null === $quiz) {
                return $this->errors->notFound(
                    sprintf('No content item with id %d exists.', $quizId),
                    array('quiz_id' => $quizId)
                );
            }
        }

        $started = microtime(true);

        try {
            $rendered = $this->renderer->render($latex, $displayMode, $format);
        } catch (\RuntimeException $e) {
            return $this->errors->create(
                'wp_mcp_ai_render_failed',
                $e->getMessage(),
                array('latex' => $latex)
            );
        }

        $elapsed = round((microtime(true) - $started) * 1000, 2);

        $data = array(
            'latex' => $latex,
            'markup' => $rendered['markup'],
            'output_format' => $format,
            'display_mode' => $displayMode,
            'render_time_ms' => $elapsed,
            'cached' => false,
        );

        if (null !== $store && null !== $quiz) {
            $data['quiz_id'] = $quizId;
            $data['quiz_equations'] = $this->associate(
                $store,
                $quiz,
                $latex,
                $rendered['markup'],
                (int) ( $context['user_id'] ?? 0 )
            );
        }

        return $this->success(
            sprintf('Rendered the equation to %1$s in %2$s ms.', $format, $elapsed),
            $data
        );
    }

    /**
     * The expression, as a string.
     *
     * Deliberately not `stringParam()`: that helper strips tags, and
     * `wp_kses_post()` did the same before it — both corrupt LaTeX comparisons
     * and angle brackets before the renderer sees them.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function latexParam(array $arguments): string
    {
        $value = $arguments['latex'] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The requested output shape, lower-cased for a case-insensitive match.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function formatParam(array $arguments): string
    {
        $format = $this->stringParam($arguments, 'output_format');

        return '' === $format ? 'html' : strtolower($format);
    }

    /**
     * The quiz id, 0 when absent, -1 when malformed.
     *
     * @param array<string, mixed> $arguments Raw tool arguments.
     */
    private function quizIdParam(array $arguments): int
    {
        $value = $arguments['quiz_id'] ?? null;

        if (null === $value) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return -1;
    }

    /**
     * Refuse a macro map the renderer would ignore.
     *
     * @param array<string, mixed>    $arguments Raw tool arguments.
     * @param array<string, string[]> $fields    Field errors accumulated so far.
     *
     * @return array<string, string[]>
     */
    private function macroProblems(array $arguments, array $fields): array
    {
        if (! array_key_exists('macros', $arguments)) {
            return $fields;
        }

        $macros = $arguments['macros'];

        if (! is_array($macros)) {
            $fields['macros'] = array('A macro map.');

            return $fields;
        }

        if (array() !== $macros) {
            $fields['macros'] = array(
                'The configured renderer applies no custom macros, so this map would be ignored.',
            );
        }

        return $fields;
    }

    /**
     * Append the rendered equation to the quiz record.
     *
     * @return int The number of equations the quiz now holds.
     */
    private function associate(
        ContentStoreInterface $store,
        ContentItem $quiz,
        string $latex,
        string $markup,
        int $userId
    ): int {
        $existing = $quiz->getMetaValue(self::QUIZ_META_KEY, array());
        $entries = is_array($existing) ? array_values($existing) : array();

        $entries[] = array(
            'latex' => $latex,
            'markup' => $markup,
            'created_at' => gmdate('Y-m-d H:i:s'),
        );

        $store->update(
            $quiz->id,
            new UpdateContentCommand(
                userId: $userId,
                meta: array(self::QUIZ_META_KEY => $entries),
            )
        );

        return count($entries);
    }
}
