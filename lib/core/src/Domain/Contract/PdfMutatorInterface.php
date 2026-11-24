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
 * media worker's `/api/pdf/watermark` route draws one fixed mark — 48pt
 * Helvetica, grey, 30% opacity, centred, rotated −45° — and takes no style
 * arguments. This contract therefore takes **text only**: accepting `opacity`
 * and `position` here and ignoring them would be a rendered control with no
 * reader, and silently rendering a different watermark than the caller asked
 * for is worse than refusing to offer the knob.
 *
 * When the worker route grows style arguments, they become an `$options` array
 * on this method and the tool's schema grows with it.
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
     *
     * @return array{path: string, mime_type: string, bytes: int, pages: int}
     *
     * @throws \InvalidArgumentException When the path or the text is empty.
     * @throws \RuntimeException         When the engine fails.
     */
    public function watermark(string $localPath, string $text): array;

    /**
     * Whether a PDF engine is bound and usable on this host.
     */
    public function isAvailable(): bool;
}
