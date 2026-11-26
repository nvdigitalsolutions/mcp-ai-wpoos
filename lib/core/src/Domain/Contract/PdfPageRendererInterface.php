<?php

/**
 * Render a PDF's pages to images.
 *
 * Proposal 164 §164.44. Distinct from `PdfOcrInterface` because the two answer
 * different questions: OCR decides what the words are on *this* host, while
 * rendering hands the pixels to something else — a vision model, most often —
 * and the caller has to choose that, because it costs a model call per page.
 *
 * ## Bytes, not paths
 *
 * The worker's render route has always written PNGs to its own filesystem and
 * returned their paths. That is useless to a host that cannot read them, which
 * is every host that is not the worker. This contract therefore carries the
 * image data itself.
 *
 * ## Base64 rather than raw bytes
 *
 * The data crosses a JSON boundary in the adapter, and every vision provider
 * accepts base64 data URIs. One representation at the contract edge avoids a
 * second encoding rule inside each tool: `data` is base64, and `mime_type` says
 * what it decodes to.
 *
 * ## The page budget is part of the contract
 *
 * A page image at 150 DPI is a few hundred kilobytes, and a vision call is billed
 * per image, so a 200-page document behind one request is both a timeout and a
 * bill. `truncated` and `total_pages` let a caller loop rather than guess, for
 * the same reason `PdfOcrInterface` reports them.
 *
 * ## Why 150 DPI
 *
 * Vision models downsample to their own patch grid long before 300 DPI pays for
 * itself, so the default here is lower than the OCR contract's: the accuracy
 * ceiling is the model's, not the scanner's, and the cost is bytes per page.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface PdfPageRendererInterface
{
    /**
     * The working default for handing a page to a vision model.
     */
    public const DEFAULT_DPI = 150;

    /**
     * Render the selected pages to images.
     *
     * @param string $localPath Absolute path to the PDF.
     * @param array<string, mixed> $options {
     *     @type int       $dpi       Rasterisation resolution. 150 is the working default for vision.
     *     @type list<int> $pages     Explicit 1-based page numbers to render.
     *     @type int       $max_pages Page budget for this call.
     *     @type string    $format    'png' or 'jpeg'. Default png.
     *     @type int       $quality   JPEG quality, 1–100.
     * }
     *
     * @return array{
     *     pages: list<array{page: int, mime_type: string, data: string, bytes: int}>,
     *     total_pages: int,
     *     truncated: bool
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the renderer fails, or the images do not fit one response.
     */
    public function render(string $localPath, array $options = array()): array;

    /**
     * Whether a renderer is bound and usable on this host.
     *
     * "Usable" includes the worker advertising the route: rendering is older than
     * the OCR family, but the inline argument this contract needs is newer than
     * the route, so a configured worker is not by itself evidence.
     */
    public function isAvailable(): bool;
}
