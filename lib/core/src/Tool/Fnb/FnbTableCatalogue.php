<?php

/**
 * The Food & Beverage toolkit's table catalogue and cell helpers.
 *
 * Port of `WP_MCP_AI_Fnb_Data_Source`'s static half (§164.71): the 18-table
 * registry, the header-alias dictionary, header normalisation, column
 * resolution, and the cell/date/float coercions the metric engine is built on.
 *
 * ## Where the data lives
 *
 * The registry and the alias dictionary are **generated** into
 * `lib/core/resources/fnb-table-catalogue.php` from the Pro data-source class
 * by `bin/generate-fnb-table-catalogue.php`. They are the toolkit's contract
 * with an operator's spreadsheets — a column that stops resolving turns a
 * metric into zero — so transcribing 18 tables and 77 aliases by hand is how a
 * silent drift starts. `--check` fails when the core copy and the Pro class
 * disagree.
 *
 * ## What a "column" is
 *
 * A reader returns positional rows and a header list. `resolveColumn()` maps a
 * canonical key to an index in three passes, in the Pro order:
 *
 * 1. the canonical key itself;
 * 2. the table's own aliases (`price_lkr` for `price`);
 * 3. the header-alias dictionary, applied to each normalised header — which is
 *    what resolves an operator's `inv no` to `invoice`.
 *
 * ## Two coercions worth naming
 *
 * `dateOnly()` disambiguates a numeric cell by magnitude: a Sheets/Excel serial
 * date is days since 1899-12-30 and sits far below any modern Unix timestamp,
 * so `< 100000` is a serial and anything larger is seconds. `floatCell()`
 * strips thousands separators, so `"1,234.5"` is a number rather than zero.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

final class FnbTableCatalogue
{
    /** The `assumptions` sheet, addressable like a numbered table. */
    public const ASSUMPTIONS = 'assumptions';

    /**
     * The loaded catalogue, once per process.
     *
     * @var array{tables: array<string, array<string, mixed>>, header_aliases: array<string, string>}|null
     */
    private static ?array $data = null;

    /**
     * @return array{tables: array<string, array<string, mixed>>, header_aliases: array<string, string>}
     */
    public static function data(): array
    {
        if (null === self::$data) {
            /** @var array{tables: array<string, array<string, mixed>>, header_aliases: array<string, string>} $loaded */
            $loaded     = require \dirname(__DIR__, 3) . '/resources/fnb-table-catalogue.php';
            self::$data = $loaded;
        }

        return self::$data;
    }

    /**
     * @return array<string, array<string, mixed>> Table id → definition.
     */
    public static function tables(): array
    {
        return self::data()['tables'];
    }

    /**
     * @return list<string> Table ids in spec order.
     */
    public static function ids(): array
    {
        return \array_keys(self::tables());
    }

    public static function has(string $tableId): bool
    {
        return isset(self::tables()[ $tableId ]);
    }

    /**
     * @return array<string, mixed>|null Table definition, or null when unknown.
     */
    public static function definition(string $tableId): ?array
    {
        return self::tables()[ $tableId ] ?? null;
    }

    public static function label(string $tableId): string
    {
        $definition = self::definition($tableId);

        return null === $definition ? '' : (string) ( $definition['label'] ?? '' );
    }

    /**
     * @return list<string> The canonical keys the metric engine reads.
     */
    public static function canonicalColumns(string $tableId): array
    {
        $definition = self::definition($tableId);

        return null === $definition ? array() : \array_values((array) ( $definition['canonical'] ?? array() ));
    }

    /**
     * Lowercase, transliterate superscript digits, then one `_` per run of
     * non-alphanumerics — WordPress's `normalise_header()`.
     */
    public static function normaliseHeader(string $header): string
    {
        $normalised = \strtolower($header);
        $normalised = \str_replace(array('³', '²'), array('3', '2'), $normalised);
        $normalised = (string) \preg_replace('/[^a-z0-9]+/', '_', $normalised);

        return \trim($normalised, '_');
    }

    /**
     * Map a header onto a canonical key through the curated dictionary.
     *
     * The dictionary is keyed by space-separated spellings, so the incoming
     * header is folded the same way first; the snake-case form is tried second
     * so an underscore-style header still resolves.
     */
    public static function resolveHeaderAlias(string $header): string
    {
        $aliases = self::data()['header_aliases'];

        $key = \strtolower(\trim($header));
        $key = \str_replace(array('_', '-'), ' ', $key);
        $key = (string) \preg_replace('/\s+/', ' ', $key);

        if (isset($aliases[ $key ])) {
            return $aliases[ $key ];
        }

        $snake = self::normaliseHeader($header);

        if (isset($aliases[ $snake ])) {
            return $aliases[ $snake ];
        }

        return $header;
    }

    /**
     * The column index for a canonical key, or false.
     *
     * @param list<string> $headers Normalised headers (the table's `header_index`).
     */
    public static function resolveColumn(string $tableId, array $headers, string $canonical): int|false
    {
        $definition = self::definition($tableId);
        $aliasMap   = (array) ( $definition['aliases'][ $canonical ] ?? array() );
        $candidates = \array_merge(array($canonical), $aliasMap);

        $flipped = \array_flip($headers);

        foreach ($candidates as $candidate) {
            if (isset($flipped[ $candidate ])) {
                return (int) $flipped[ $candidate ];
            }
        }

        $wanted = \array_flip($candidates);

        foreach ($headers as $index => $header) {
            if (isset($wanted[ self::resolveHeaderAlias($header) ])) {
                return (int) $index;
            }
        }

        return false;
    }

    /**
     * A canonical column's value from a positional row.
     *
     * An empty string counts as absent, as it does in the Pro helper: a
     * spreadsheet's blank cell is not a value.
     *
     * @param list<string>             $headerIndex Normalised headers.
     * @param array<int|string, mixed> $row         Positional row.
     */
    public static function cell(string $tableId, array $headerIndex, array $row, string $canonical, mixed $fallback = ''): mixed
    {
        $index = self::resolveColumn($tableId, $headerIndex, $canonical);

        if (false === $index) {
            return $fallback;
        }

        return isset($row[ $index ]) && '' !== $row[ $index ] ? $row[ $index ] : $fallback;
    }

    /**
     * A date as `Y-m-d` (or `Y-m` for a month column), or `''`.
     *
     * Numeric cells are either epoch seconds or a spreadsheet serial date; the
     * magnitude decides, as in the Pro helper.
     */
    public static function dateOnly(mixed $value): string
    {
        $value = (string) $value;

        if ('' === $value) {
            return '';
        }

        if (\is_numeric($value)) {
            $numeric = (float) $value;

            if ($numeric <= 0) {
                return '';
            }

            if ($numeric < 100000) {
                // Sheets/Excel serial: days since 1899-12-30 (25569 = 1970-01-01).
                return (string) \gmdate('Y-m-d', (int) \round(( $numeric - 25569 ) * 86400));
            }

            return (string) \gmdate('Y-m-d', (int) $numeric);
        }

        $date = \substr(\trim($value), 0, 10);

        if (\preg_match('/^\d{4}-\d{2}$/', $date)) {
            return $date;
        }

        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    /**
     * The `Y-m` prefix of a date value, or `''`.
     */
    public static function monthOf(mixed $value): string
    {
        $date = self::dateOnly($value);

        return '' === $date ? '' : \substr($date, 0, 7);
    }

    /**
     * A cell as a float, with thousands separators removed.
     */
    public static function floatCell(mixed $value): float
    {
        if (\is_numeric($value)) {
            return (float) $value;
        }

        $clean = \str_replace(array(',', ' '), '', (string) $value);

        return \is_numeric($clean) ? (float) $clean : 0.0;
    }
}
