<?php

/**
 * Renders the graph of a function of one variable.
 *
 * Separate from {@see MathEngineInterface} on purpose. That contract is bound by
 * mathjs, which cannot draw; this one is bound by chartjs-node-canvas, which
 * cannot evaluate. A runtime can satisfy either without the other, and
 * `isAvailable()` has to answer for each independently — a single "math engine
 * available" flag would advertise a plot on a server that has no canvas.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\ValueObject\GraphRequest;

interface GraphRendererInterface
{
    /**
     * The error code a tool reports when no renderer is configured.
     *
     * Same value as {@see MathEngineInterface::ERROR_UNAVAILABLE}: both mean
     * "this deployment does not carry the dependency", and a caller that
     * already handles one should not have to learn a second code.
     */
    public const ERROR_UNAVAILABLE = 'wp_mcp_ai_engine_missing';

    /** Whether a renderer is configured and its dependency is present. */
    public function isAvailable(): bool;

    /**
     * Sample the function across the window and render it to a PNG.
     *
     * `data` is base64 so the answer survives JSON. `x_range` and `y_range` are
     * the windows actually drawn, which matters when `y_range` was derived: the
     * caller is told the axis it got, not the axis it asked for.
     *
     * @return array{
     *     data: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     samples: int,
     *     finite_samples: int,
     *     x_range: array{0: float, 1: float},
     *     y_range: array{0: float, 1: float}
     * }
     *
     * @throws \InvalidArgumentException When the expression or the window cannot be plotted.
     * @throws \RuntimeException         When the renderer itself fails.
     */
    public function graph(GraphRequest $request): array;
}
