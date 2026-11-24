<?php

/**
 * Write a Word or Excel file from structured content.
 *
 * Proposal 164 §164.4.3. The content shapes are the media worker's own
 * (`docx` and `exceljs` argument objects), not a second dialect invented here,
 * for the same reason the PDF adapter sends `printBackground` rather than
 * `print_background`: two runtimes that send different payloads render
 * different documents from the same input.
 *
 * `docx` accepts the worker's `content` object — `title`, `paragraphs` (a string,
 * a `{heading, level}` object, a `{runs}` object or a `{text, bullet}` object),
 * `table: {headers, rows}`, `header`, `footer`.
 *
 * `xlsx` accepts the worker's `sheets` array — each `{name, columns, rows,
 * style}`, where `columns` are `{header, key, width}` and `rows` are arrays or
 * objects keyed by column key.
 *
 * Neither contract takes a template. Template rendering is a different job
 * (variable substitution into an existing document) and the worker has no route
 * for it; accepting a `template` argument that was ignored would be the defect
 * this project keeps finding.
 *
 * `docx()` takes no options because the worker's word route reads none — it
 * derives document properties from the content itself. `xlsx()` does take
 * `creator`/`title`, because the excel route reads them.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface OfficeWriterInterface
{
    /**
     * Render a Word document.
     *
     * @param array<string, mixed> $content The worker's `content` object.
     *
     * @return array{path: string, mime_type: string, bytes: int}
     *
     * @throws \InvalidArgumentException When the content is empty.
     * @throws \RuntimeException         When the engine fails.
     */
    public function docx(array $content): array;

    /**
     * Render an Excel workbook.
     *
     * @param array<int, array<string, mixed>> $sheets  The worker's `sheets` array.
     * @param array<string, mixed>             $options `creator`, `title` for workbook properties.
     *
     * @return array{path: string, mime_type: string, bytes: int}
     *
     * @throws \InvalidArgumentException When no sheet is given.
     * @throws \RuntimeException         When the engine fails.
     */
    public function xlsx(array $sheets, array $options = array()): array;

    /**
     * Whether an office writer is bound and usable on this host.
     */
    public function isAvailable(): bool;
}
