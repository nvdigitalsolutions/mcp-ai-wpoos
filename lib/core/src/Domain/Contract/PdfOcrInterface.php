<?php

/**
 * OCR a PDF: rasterise its pages and read the text out of the pixels.
 *
 * Proposal 164 §164.7. Distinct from `PdfTextExtractorInterface` because the
 * two are different costs and different failures: extraction reads a text layer
 * that may not exist and is effectively free, while OCR renders every page at
 * print resolution and spends seconds and possibly money doing it. A caller has
 * to choose that, so it is its own contract.
 *
 * The page budget is part of the contract rather than an implementation detail:
 * OCR is seconds per page, and a 200-page scan behind one request is a timeout
 * that reads like a crash. A truncated answer says `truncated` and carries
 * `total_pages`, so a caller can loop rather than guess.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface PdfOcrInterface
{
    /**
     * Recognise the text on a PDF's pages.
     *
     * @param string $localPath Absolute path to the PDF.
     * @param array<string, mixed> $options {
     *     @type int    $dpi       Rasterisation resolution. 300 is the working default.
     *     @type int    $max_pages Page budget for this call.
     *     @type string $language  Tesseract traineddata code, e.g. 'eng'.
     *     @type list<int> $pages  Explicit 1-based page numbers to read.
     * }
     *
     * @return array{
     *     text: string,
     *     pages: list<array{page: int, text: string, confidence: float}>,
     *     count: int,
     *     total_pages: int,
     *     truncated: bool,
     *     dpi: int,
     *     language: string
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the engine fails.
     */
    public function recognize(string $localPath, array $options = array()): array;

    /**
     * Whether OCR is bound and usable on this host.
     *
     * "Bound" here includes the worker advertising the route: OCR is newer than
     * the PDF family it belongs to, so a configured worker is not by itself
     * evidence that it can do this.
     */
    public function isAvailable(): bool;
}
