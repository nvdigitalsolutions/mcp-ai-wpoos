<?php

/**
 * GraphRequest — the validated input of the graph_function tool.
 *
 * Built by the tool *after* validation rather than parsed from the raw
 * arguments, unlike {@see FormatBytesRequest}. The difference is deliberate: a
 * range is either two finite ascending numbers or it is not a range at all, and
 * there is no value this type could carry that would let the tool report which
 * field the caller got wrong. So the tool narrows and reports, and the renderer
 * downstream receives a value that cannot be malformed.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final readonly class GraphRequest
{
    /**
     * @param string                        $expression The function, in mathjs syntax.
     * @param array{0: float, 1: float}     $xRange     Inclusive x window.
     * @param array{0: float, 1: float}|null $yRange    Inclusive y window, or null to derive it.
     * @param int                           $width      Image width in pixels.
     * @param int                           $height     Image height in pixels.
     * @param bool                          $showGrid   Whether to draw grid lines.
     * @param string                        $lineColor  Six-digit hex colour.
     * @param string                        $title      Heading drawn into the image, or ''.
     */
    public function __construct(
        public string $expression,
        public array $xRange,
        public ?array $yRange = null,
        public int $width = 800,
        public int $height = 600,
        public bool $showGrid = true,
        public string $lineColor = '#0066cc',
        public string $title = '',
    ) {
    }
}
