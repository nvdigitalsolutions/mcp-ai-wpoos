<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\GraphRendererInterface;
use Nvoos\Core\Domain\ValueObject\GraphRequest;

/**
 * Function graphs from the media worker — Proposal 164 section 164.169.
 *
 * One route, `/api/math/graph`, which samples with mathjs and renders with
 * chartjs-node-canvas in the same process. That composition is the point: a
 * client that sampled in PHP and then posted 400 points for rendering would
 * make one round trip per sample, and a client that posted the expression to
 * `/api/data/render-chart` would be asking a renderer to evaluate mathematics
 * it cannot parse.
 *
 * ## Refusals are the caller's input
 *
 * The route answers `400` for an expression it cannot parse, a window that is
 * not two ascending finite numbers, or a symbol it does not know; `422` when
 * the window yields fewer than two finite values, because there is nothing to
 * draw. Both reach the tool as a validation failure through
 * `MediaWorkerRefusedException`. `503` means the native canvas binding is
 * absent — a deployment fact, not a caller mistake — and stays a runtime
 * failure.
 *
 * ## The drawn window is reported, not the requested one
 *
 * When `y_range` is omitted the worker derives it from the sampled values and
 * pads it by 10%. The adapter returns the window the worker says it drew, so a
 * caller reading `y_range` is never told about an axis that was not rendered.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

final class MediaWorkerGraphRenderer implements GraphRendererInterface
{
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
    public function graph(GraphRequest $request): array
    {
        $payload = array(
            'expression' => $request->expression,
            'x_range' => $request->xRange,
            'width' => $request->width,
            'height' => $request->height,
            'show_grid' => $request->showGrid,
            'line_color' => $request->lineColor,
        );

        if (null !== $request->yRange) {
            $payload['y_range'] = $request->yRange;
        }

        if ('' !== $request->title) {
            $payload['title'] = $request->title;
        }

        try {
            $body = $this->worker->postJson('/api/math/graph', $payload);
        } catch (MediaWorkerRefusedException $e) {
            // 400/413/422: the expression or the window cannot be plotted.
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $data = $body['data_base64'] ?? null;

        if (! \is_string($data) || '' === $data) {
            throw new \RuntimeException(
                'The media worker answered without image data for /api/math/graph.'
            );
        }

        return array(
            'data' => $data,
            'mime_type' => \is_string($body['mime_type'] ?? null) && '' !== $body['mime_type']
                ? $body['mime_type']
                : 'image/png',
            'width' => (int) ( $body['width'] ?? $request->width ),
            'height' => (int) ( $body['height'] ?? $request->height ),
            'samples' => (int) ( $body['samples'] ?? 0 ),
            'finite_samples' => (int) ( $body['finite_samples'] ?? 0 ),
            'x_range' => $this->window($body['x_range'] ?? null, $request->xRange),
            'y_range' => $this->window($body['y_range'] ?? null, $request->yRange),
        );
    }

    /**
     * A two-element numeric window, falling back when the answer omits it.
     *
     * @param mixed                       $value   Candidate from the worker.
     * @param array{0: float, 1: float}|null $fallback Value to use when unusable.
     *
     * @return array{0: float, 1: float}|null
     */
    private function window(mixed $value, ?array $fallback): ?array
    {
        if (! \is_array($value) || 2 !== count($value)) {
            return $fallback;
        }

        $window = array_values($value);

        if (! \is_numeric($window[0]) || ! \is_numeric($window[1])) {
            return $fallback;
        }

        return array((float) $window[0], (float) $window[1]);
    }
}
