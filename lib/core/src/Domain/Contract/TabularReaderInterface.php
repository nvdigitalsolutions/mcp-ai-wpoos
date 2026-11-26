<?php

/**
 * Read a tabular file — a spreadsheet, a delimited export, a JSON array — into
 * a column/row matrix.
 *
 * Proposal 164 §164.4.3, and the counterpart to `OfficeWriterInterface`: that
 * writes OOXML, this reads it back. It exists because `excel_data_import` on
 * WordPress carries PhpSpreadsheet, and adding a spreadsheet library to this
 * runtime to read one file is a dependency the worker already pays for.
 *
 * ## What a caller may rely on
 *
 * `columns` and `rows` are both **positional**. `rows` is a list of lists of
 * strings, because the worker's matrix is deliberately untyped: a phone number
 * and a quantity look identical once a spreadsheet has had its way with them,
 * and a type guessed wrong is worse than a string. A caller that wants numbers
 * coerces them with the column name in hand.
 *
 * `meta` carries what the engine knows: `engine`, `sheet`, `source_total` (rows
 * before any cap) and `truncated`. A matrix with `truncated: true` is a partial
 * answer that says so — never a silent subset.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TabularReaderInterface
{
    /**
     * Parse a file into a matrix.
     *
     * @param string $localPath Absolute path to the file.
     * @param array<string, mixed> $options {
     *     @type string $sheet   Worksheet name or 1-based index. Ignored by non-spreadsheet engines.
     *     @type string $format Force a format instead of inferring it from the extension.
     *     @type int    $max_rows Stop after this many rows; 0 reads the file.
     * }
     *
     * @return array{
     *     columns: list<string>,
     *     rows: list<list<string>>,
     *     meta: array<string, mixed>
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the engine fails or the format is unsupported.
     */
    public function read(string $localPath, array $options = array()): array;

    /**
     * Whether a reader is bound and usable on this host.
     */
    public function isAvailable(): bool;

    /**
     * Formats this reader accepts, lower-case and without the dot.
     *
     * @return list<string>
     */
    public function getSupportedFormats(): array;
}
