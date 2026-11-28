<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\MathRendererInterface;

/**
 * LaTeX rendering over the media worker — Proposal 164 section 164.167.
 *
 * `POST /api/data/render-math` calls `katex.renderToString()` and answers
 * `{ success, html, latex }`. It is the only route this adapter needs, and it
 * already existed, so the port costs one class rather than a worker release.
 *
 * ## The WordPress caller sent the wrong field names
 *
 * The Pro tool built its payload as `{ latex, options: $arguments }` — the raw
 * tool arguments, whose rendering options are spelled `display_mode` and
 * `output_format`. The route reads `options.displayMode` and `options.output`,
 * so every sidecar request fell through to its defaults: always display mode,
 * always HTML. A caller asking for inline rendering got a centred block and a
 * caller asking for MathML got HTML, and nothing said so.
 *
 * This adapter sends the route's own names, and translates the schema's
 * `html_and_mathml` into KaTeX's `htmlAndMathml`, which the route would
 * otherwise reject as an unknown output and fail.
 *
 * ## What the route does not honour
 *
 * It hard-codes `throwOnError: false` and never reads a macro map. The tool
 * therefore omits `throw_on_error` from its schema and refuses a non-empty
 * `macros` argument by name, rather than accepting either and ignoring it.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

final class MediaWorkerMathRenderer implements MathRendererInterface
{
    /**
     * The schema's output shapes, in KaTeX's spelling.
     *
     * `html_and_mathml` is the one that has to be translated: KaTeX calls the
     * combined output `htmlAndMathml`, and anything else throws a settings
     * error that `throwOnError` does not catch, because it is not a parse error.
     */
    private const KATEX_OUTPUT = array(
        'html' => 'html',
        'mathml' => 'mathml',
        'html_and_mathml' => 'htmlAndMathml',
    );

    public function __construct(private readonly MediaWorkerClient $worker)
    {
    }

    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * {@inheritDoc}
     */
    public function render(string $latex, bool $displayMode, string $output): array
    {
        $mapped = self::KATEX_OUTPUT[ $output ] ?? null;

        if (null === $mapped) {
            throw new \InvalidArgumentException(
                'A render output must be one of: ' . \implode(', ', MathRendererInterface::OUTPUTS) . '. Got: ' . $output . '.'
            );
        }

        $body = $this->worker->postJson(
            '/api/data/render-math',
            array(
                'latex' => $latex,
                'options' => array(
                    'displayMode' => $displayMode,
                    'output' => $mapped,
                ),
            )
        );

        $markup = $body['html'] ?? null;

        if (! \is_string($markup) || '' === $markup) {
            throw new \RuntimeException(
                'The media worker answered without rendered markup for /api/data/render-math.'
            );
        }

        return array(
            'markup' => $markup,
            'latex' => \is_string($body['latex'] ?? null) ? $body['latex'] : $latex,
        );
    }
}
