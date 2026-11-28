<?php

/**
 * LaTeX rendering, as `lib/core` is allowed to see it.
 *
 * Proposal 164 section 164.167. `render_math_equation` is the third `math` tool
 * to arrive and the first that needs nothing new from the worker: `POST
 * /api/data/render-math` has existed since the sidecar's data group shipped and
 * answers `{ success, html, latex }` from `katex.renderToString()`.
 *
 * ## Narrow on purpose
 *
 * There is no `request( $route, $body )` passthrough and no macro map. The
 * worker's route reads exactly two options — `displayMode` and `output` — and
 * ignores the rest, so a contract that accepted macros would be a promise the
 * engine cannot keep. The tool refuses a non-empty macro map by name instead,
 * which is the same choice this family makes everywhere else: a named refusal
 * beats a silent drop, and it flips to support the day the route honours it.
 *
 * ## The output vocabulary is the tool's, not KaTeX's
 *
 * `html_and_mathml` is spelled here as the schemas spell it. The adapter owns
 * the translation to KaTeX's `htmlAndMathml`, because that is a wire detail and
 * not something a caller should have to know.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface MathRendererInterface
{
    /**
     * The WordPress error code for "no renderer is available".
     *
     * Carried here so both runtimes log the same string, the way
     * {@see MediaWorkerClientInterface::ERROR_NOT_CONFIGURED} is.
     */
    public const ERROR_UNAVAILABLE = 'wp_mcp_ai_engine_missing';

    /** The shapes a rendered equation can take. */
    public const OUTPUTS = array('html', 'mathml', 'html_and_mathml');

    /**
     * Whether this host has a renderer to talk to.
     *
     * Config presence, not liveness — a health probe per call would add a
     * round-trip to a question asked on every render.
     */
    public function isAvailable(): bool;

    /**
     * Render LaTeX to the requested shape.
     *
     * @param string $latex       The expression, unmodified. KaTeX is the
     *                            parser and the sanitiser; rewriting the input
     *                            first (as `wp_kses_post` did) corrupts
     *                            comparisons and angle brackets.
     * @param bool   $displayMode True for a centred block, false for inline.
     * @param string $output      One of {@see self::OUTPUTS}.
     *
     * @return array{markup: string, latex: string} The rendered markup, which is
     *         HTML or MathML depending on `$output`, and the expression that was
     *         rendered. The key is `markup` rather than `html` because the
     *         WordPress tool returned MathML under `html`, which made the key lie.
     *
     * @throws \InvalidArgumentException When `$output` is not a known shape.
     * @throws \RuntimeException         When the renderer is unavailable or refuses.
     */
    public function render(string $latex, bool $displayMode, string $output): array;
}
