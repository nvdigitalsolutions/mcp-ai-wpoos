<?php

/**
 * Change an existing PDF: merge several into one, or stamp text on every page.
 *
 * Proposal 164 §164.4.3. One contract for both because they share everything
 * that matters: the input is PDF bytes this host already holds, the output is a
 * new file, and the only failure mode that differs is the reason string.
 *
 * ## What the watermark can and cannot do
 *
 * The WordPress tool accepts `opacity` and `position` (`center`, `diagonal`,
 * `top`, `bottom`) and spends those options in its own `pdf-lib` code path. The
 * media worker's `/api/pdf/watermark` route draws a mark whose default is the
 * original fixed one — 48pt Helvetica, grey, 30% opacity, centred, rotated −45°
 * — and now accepts `opacity` and `position` too.
 *
 * The style arguments are **newer than the route**, so a worker may exist that
 * honours none of them. `watermarkStyleSupported()` is therefore part of this
 * contract: a caller that was asked for a non-default style must be able to
 * refuse with a named error rather than quietly stamp the default mark and
 * report success.
 *
 * `fontSize` is deliberately not offered. The route's mark size is part of the
 * mark's identity; a caller that could set it would be designing a watermark,
 * which is a template's job (§164.7).
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface PdfMutatorInterface
{
    /**
     * Concatenate PDFs, in the order given.
     *
     * @param string[] $localPaths Two or more absolute paths.
     *
     * @return array{path: string, mime_type: string, bytes: int, pages: int}
     *
     * @throws \InvalidArgumentException When fewer than two readable paths are given.
     * @throws \RuntimeException         When the engine fails.
     */
    public function merge(array $localPaths): array;

    /**
     * Stamp a text watermark on every page of one PDF.
     *
     * @param string $localPath Absolute path to the source PDF.
     * @param string $text      The mark, e.g. `CONFIDENTIAL`.
     * @param array<string, mixed> $options {
     *     @type float  $opacity  0.0–1.0. Default 0.3.
     *     @type string $position `diagonal` | `center` | `top` | `bottom`. Default `diagonal`.
     * }
     *
     * @return array{path: string, mime_type: string, bytes: int, pages: int}
     *
     * @throws \InvalidArgumentException When the path or the text is empty.
     * @throws \RuntimeException         When the engine fails or the style is not supported.
     */
    public function watermark(string $localPath, string $text, array $options = array()): array;

    /**
     * Whether this engine honours `opacity` and `position`.
     *
     * False means a non-default style cannot be honoured, and the caller must
     * say so rather than rendering the default mark under a different name.
     */
    public function watermarkStyleSupported(): bool;

    /**
     * Whether a PDF engine is bound and usable on this host.
     */
    public function isAvailable(): bool;
}
