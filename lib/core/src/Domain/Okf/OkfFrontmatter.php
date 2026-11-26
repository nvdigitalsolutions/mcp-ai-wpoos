<?php

/**
 * Frontmatter serialisation for OKF concept documents.
 *
 * The core parser reads frontmatter; nothing in this package wrote it until now,
 * so a writer had to either duplicate the format or re-implement YAML badly. This
 * class owns one direction only, and deliberately supports the *subset* the
 * format actually uses:
 *
 *  - scalars (string, int, float, bool, null),
 *  - lists of scalars,
 *  - nested maps,
 *  - lists of flat maps (the `sources:` / `verified:` shape).
 *
 * Anything deeper raises `OkfWriteException` rather than emitting something the
 * reader would silently mis-parse. A writer that quietly corrupts a bundle is
 * worse than one that refuses.
 *
 * ## Quoting
 *
 * OKF v0.3 says frontmatter is YAML 1.2 core schema, and that values which look
 * like numbers or booleans must be quoted. That rule is not cosmetic: an unquoted
 * `title: 2026` reads back as an int, and an unquoted `status: null` reads back as
 * null. `quote()` applies it, plus the YAML indicators that cannot start a plain
 * scalar (`-?:,[]{}#&*!|>'"%@``).
 *
 * ## Escaping
 *
 * Double-quoted values escape `\`, `"` and newline, and the parser's
 * `castScalar()` unescapes the same three. Without that pair a description
 * containing a quote would round-trip with the backslash still in it.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Okf;

final class OkfFrontmatter
{
    /** Characters that cannot start a plain YAML scalar. */
    private const INDICATORS = "-?:,[]{}#&*!|>'\"%@`";

    /** Values YAML 1.2 would read as something other than this string. */
    private const RESERVED_WORDS = array(
        'true', 'false', 'yes', 'no', 'on', 'off', 'null', '~',
        'nan', 'inf', '-inf',
    );

    /**
     * Render a frontmatter block, delimiters included.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @throws OkfWriteException When a value is outside the supported subset.
     */
    public static function serialize(array $frontmatter): string
    {
        $lines = array('---');

        self::emit($frontmatter, '', $lines);

        $lines[] = '---';

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $lines
     *
     * @throws OkfWriteException
     */
    private static function emit(array $map, string $indent, array &$lines): void
    {
        foreach ($map as $key => $value) {
            $name = self::key((string) $key);

            if (! \is_array($value)) {
                $lines[] = $indent . $name . ': ' . self::scalar($value);
                continue;
            }

            if (array() === $value) {
                $lines[] = $indent . $name . ': ' . (self::isList($value) ? '[]' : '{}');
                continue;
            }

            $lines[] = $indent . $name . ':';

            if (self::isList($value)) {
                self::emitList($value, $indent, $lines);
                continue;
            }

            self::emit($value, $indent . '  ', $lines);
        }
    }

    /**
     * @param array<mixed> $list
     * @param list<string> $lines
     *
     * @throws OkfWriteException
     */
    private static function emitList(array $list, string $indent, array &$lines): void
    {
        foreach ($list as $item) {
            if (! \is_array($item)) {
                $lines[] = $indent . '  - ' . self::scalar($item);
                continue;
            }

            if (array() === $item || ! self::isMap($item)) {
                throw new OkfWriteException(
                    'okf_unsupported_shape',
                    'Nested lists are not supported in frontmatter; use a flat list or a map.',
                );
            }

            $first = true;

            foreach ($item as $key => $value) {
                if (\is_array($value)) {
                    throw new OkfWriteException(
                        'okf_unsupported_shape',
                        'A list item may contain scalars only; nest it as a map instead.',
                    );
                }

                $lines[] = $indent . '  ' . ($first ? '- ' : '  ')
                    . self::key((string) $key) . ': ' . self::scalar($value);

                $first = false;
            }
        }
    }

    private static function key(string $key): string
    {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key)) {
            return $key;
        }

        return self::quote($key);
    }

    private static function scalar(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            // Never let a float render as scientific notation the reader cannot
            // round-trip.
            return \rtrim(\rtrim(\sprintf('%.10F', $value), '0'), '.') ?: '0';
        }

        return self::quote((string) $value);
    }

    private static function quote(string $value): string
    {
        if ('' === $value) {
            return "''";
        }

        $needs = \in_array(\strtolower($value), self::RESERVED_WORDS, true)
            || \preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $value) === 1
            || \str_contains(self::INDICATORS, $value[0])
            || \preg_match('/\s$|^\s|\n|\s#|:\s/', $value) === 1;

        if (! $needs) {
            return $value;
        }

        return '"' . \str_replace(
            array('\\', '"', "\n", "\r"),
            array('\\\\', '\\"', '\\n', ''),
            $value
        ) . '"';
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return \array_is_list($value);
    }

    /**
     * @param array<mixed> $value
     */
    private static function isMap(array $value): bool
    {
        return ! \array_is_list($value);
    }
}
