<?php

/**
 * Read the text out of a PDF.
 *
 * Proposal 164 §164.4.3. Narrow on purpose: a path in, the text and the page
 * count out. OCR is deliberately **not** part of this contract — the worker
 * owns `/api/ocr/recognize` as a separate operation with its own providers and
 * cost, and folding it in here would make a cheap read look like a paid vision
 * call (§164.7 defers OCR to its own slice).
 *
 * The WordPress tool it ports also accepts a provider file id and a URL. Neither
 * is meaningful here: a provider file id names bytes in someone else's storage,
 * and a URL names bytes this contract would have to fetch and vet. A stored
 * file id is the supported entry, because `FileStoreInterface::getPath()`
 * already resolves it to bytes on this host with its own authorisation check.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface PdfTextExtractorInterface
{
    /**
     * Extract text from a PDF on disk.
     *
     * @param string $localPath Absolute path to the PDF.
     * @param int    $maxPages  Stop after this many pages; 0 reads the document.
     *
     * @return array{text: string, pages: int, info: array<string, mixed>}
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the engine fails.
     */
    public function extract(string $localPath, int $maxPages = 0): array;

    /**
     * Whether an extractor is bound and usable on this host.
     */
    public function isAvailable(): bool;
}
