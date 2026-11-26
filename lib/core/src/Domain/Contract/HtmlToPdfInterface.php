<?php

/**
 * Render HTML to a PDF.
 *
 * Proposal 164 §164.4.3. The contract is deliberately narrow — HTML in, a file
 * out — because the interesting difference between engines is *fidelity*, and
 * that is something a caller has to state rather than have guessed.
 *
 * ## Why availability is asked, not assumed
 *
 * A host without a worker and without a PHP renderer cannot produce a PDF. The
 * tool that uses this asks `isAvailable()` and refuses with a named capability
 * error rather than returning a blank document. That is the honesty rule in
 * 164.4.8: a tool whose engine is unbound is not servable.
 *
 * @package Nvoos\Core
 * @since   2.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface HtmlToPdfInterface
{
    /**
     * Render an HTML document to a PDF on disk.
     *
     * The caller owns the returned path only until it hands the file to the
     * file registry ({@see FileStoreInterface::store()}); a renderer may reuse
     * the same path for an identical request.
     *
     * @param string               $html    A complete HTML document (or a fragment with a `<body>`).
     * @param array<string, mixed> $options {
     *     @type string $format           Page size, e.g. 'A4'. Case-insensitive.
     *     @type bool   $landscape        Landscape orientation.
     *     @type bool   $print_background Keep CSS backgrounds. Default true.
     * }
     *
     * @return array{path: string, mime_type: string, bytes: int, format: string}
     *
     * @throws \InvalidArgumentException When the document is empty or the page size is unknown.
     * @throws \RuntimeException         When the engine fails or returns no bytes.
     */
    public function render(string $html, array $options = array()): array;

    /**
     * Whether a renderer is bound and usable on this host.
     */
    public function isAvailable(): bool;

    /**
     * Page sizes this renderer accepts.
     *
     * @return string[] Canonical names, e.g. ['A4', 'Letter'].
     */
    public function getSupportedPageFormats(): array;
}
