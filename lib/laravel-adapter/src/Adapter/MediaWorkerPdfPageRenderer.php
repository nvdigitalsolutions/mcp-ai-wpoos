<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\PdfPageRendererInterface;

/**
 * PDF page rendering over the media worker — Proposal 164 §164.44.
 *
 * `POST /api/pdf/render` rasterises pages with pdfjs and canvas, and until now
 * wrote them to the *worker's* filesystem and answered with their paths. A host
 * cannot read those paths, so the output was only usable by something running on
 * the worker itself — which is why vision OCR over a PDF had no route.
 *
 * The `inline` argument makes the route answer with the page images themselves,
 * base64-encoded. This adapter is that argument.
 *
 * ## Why `isAvailable()` asks for `pdf_render_inline`
 *
 * The route has existed for a while; the argument is new. `pdf_rendering` is
 * therefore true on workers that would answer an `inline` request with a list of
 * paths this host cannot use — a 200 that looks like success and is not. Asking
 * for the narrower key turns a version skew into a named refusal before a caller
 * spends a request, the same reasoning as `MediaWorkerPdfOcr`.
 *
 * ## Page budgets and byte ceilings
 *
 * The route refuses a set larger than its inline ceiling with a 413, which the
 * client surfaces as `MediaWorkerRefusedException` — a request the caller can fix
 * by asking for fewer pages, a lower dpi or jpeg, not a server fault.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

final class MediaWorkerPdfPageRenderer implements PdfPageRendererInterface
{
    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        if (!$this->worker->isConfigured()) {
            return false;
        }

        return $this->probe->report()->supports('pdf_render_inline');
    }

    /**
     * {@inheritDoc}
     */
    public function render(string $localPath, array $options = array()): array
    {
        if ('' === trim($localPath)) {
            throw new \InvalidArgumentException('A PDF path is required.');
        }

        if (!is_file($localPath)) {
            throw new \InvalidArgumentException('The PDF does not exist: '.$localPath);
        }

        $fields = array(
            // The route treats any of 1/true/yes/on as true, so the spelling is
            // explicit rather than relying on a truthy string.
            'inline' => '1',
            'dpi' => (string) $this->dpi($options),
        );

        $maxPages = (int) ($options['max_pages'] ?? 0);

        if ($maxPages > 0) {
            $fields['maxPages'] = (string) $maxPages;
        }

        $pages = $options['pages'] ?? null;

        if (is_array($pages) && array() !== $pages) {
            $fields['pages'] = implode(',', array_map('intval', $pages));
        }

        $format = strtolower(trim((string) ($options['format'] ?? '')));

        if ('jpeg' === $format || 'jpg' === $format) {
            $fields['format'] = 'jpeg';
        }

        $quality = (int) ($options['quality'] ?? 0);

        if ($quality > 0) {
            $fields['quality'] = (string) min(100, max(1, $quality));
        }

        $body = $this->worker->uploadFile(
            '/api/pdf/render',
            $localPath,
            basename($localPath),
            $fields,
            $this->timeout(),
        );

        $rendered = array();

        foreach ((array) ($body['pages'] ?? array()) as $page) {
            if (!is_array($page)) {
                continue;
            }

            $rendered[] = array(
                'page' => (int) ($page['page'] ?? 0),
                'mime_type' => is_string($page['mime_type'] ?? null) ? $page['mime_type'] : 'image/png',
                'data' => is_string($page['image'] ?? null) ? $page['image'] : '',
                'bytes' => (int) ($page['bytes'] ?? 0),
            );
        }

        if (array() === $rendered) {
            throw new \RuntimeException('The media worker returned no page images for this PDF.');
        }

        return array(
            'pages' => $rendered,
            'total_pages' => (int) ($body['total_pages'] ?? count($rendered)),
            'truncated' => (bool) ($body['truncated'] ?? false),
        );
    }

    /**
     * The requested resolution, clamped to the range the route accepts.
     *
     * The route clamps too; doing it here means the field always carries a value
     * the worker can use rather than a number it silently replaces.
     *
     * @param array<string, mixed> $options Render options.
     */
    private function dpi(array $options): int
    {
        $dpi = (int) ($options['dpi'] ?? PdfPageRendererInterface::DEFAULT_DPI);

        return min(600, max(72, $dpi));
    }

    /**
     * Rendering is fast per page, but the response carries base64 images.
     */
    private function timeout(): int
    {
        return max(1, (int) config('oos.media_worker.render_timeout', 120));
    }
}
