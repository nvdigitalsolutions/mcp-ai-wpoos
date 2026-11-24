<?php

/**
 * Discovers tool-contract classes in a source tree.
 *
 * The readiness auditor needs to know which classes declare a tool and which
 * slug each one answers to, because that is the only way to catch a manifest
 * row pointing at a class that declares a *different* slug. The scan is
 * source-level rather than reflective: a tool class may reference services the
 * auditor cannot construct, and loading every class just to read one string
 * would execute code the gate is meant to inspect.
 *
 * ## Why this is a class and not a function in the script
 *
 * It used to be `nvoos_audit_scan()` inside `bin/audit-tool-readiness.php`,
 * which is a script that runs on include — so it could not be tested. The
 * declaration match then drifted: it was anchored on `^class`, and every
 * `final class` tool was skipped, which made `slug_mismatch` silently
 * unable to fire for the newest tools (issue #8201). The scan is the part most
 * likely to drift again, so it lives here with tests.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

final class ToolClassScanner
{
    /**
     * Scan a directory for classes that declare a tool contract.
     *
     * Files are keyed by path so a caller can report where a class came from.
     * Only the first class declaration in a file is considered: the tool
     * contract is one class per file.
     *
     * @param string $directory Root of the source tree to scan.
     *
     * @return array<string, array{class: string, fqcn: string, file: string, slug: string, ctor: array<int, string>}>
     */
    public function scan(string $directory): array
    {
        $classes = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $declaration = self::declaration($source);

            if (null === $declaration || $declaration['abstract']) {
                continue;
            }

            if (!self::declaresTool($source)) {
                continue;
            }

            $namespace = '';
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatch)) {
                $namespace = trim($namespaceMatch[1]);
            }

            $slug = self::slug($source);

            $name = $declaration['name'];

            $classes[$file->getPathname()] = array(
                'class' => $name,
                'fqcn'  => ('' === $namespace ? '' : $namespace . '\\') . $name,
                'file'  => $file->getPathname(),
                'slug'  => $slug,
                'ctor'  => self::parameters($source, '__construct'),
            );
        }

        ksort($classes);

        return $classes;
    }

    /**
     * The class a source file declares, and whether it is abstract.
     *
     * The modifiers are part of the match on purpose. A file whose declaration
     * is `final class Foo extends AbstractTool` is a class; matching `^class`
     * directly skipped every one of them, and 25 tool classes are `final`.
     *
     * `readonly` is listed as well even though no tool uses it today, so the
     * pattern does not need revisiting the first time one does.
     *
     * Abstract is reported rather than filtered here: the caller decides, and a
     * test can pin both the name and the modifier.
     *
     * @param string $source File contents.
     *
     * @return array{name: string, abstract: bool}|null Null when no class is declared.
     */
    public static function declaration(string $source): ?array
    {
        if (!preg_match('/^(?P<modifiers>(?:(?:final|abstract|readonly)\s+)*)class\s+(?P<name>\w+)/m', $source, $match)) {
            return null;
        }

        return array(
            'name'     => $match['name'],
            'abstract' => 1 === preg_match('/\babstract\b/', $match['modifiers']),
        );
    }

    /**
     * Does the file declare something shaped like a tool contract?
     */
    private static function declaresTool(string $source): bool
    {
        return 1 === preg_match('/extends\s+AbstractTool/', $source)
            || 1 === preg_match('/implements\s+ToolInterface/', $source)
            || 1 === preg_match('/function\s+getSlug\s*\(/', $source);
    }

    /**
     * The slug a class answers to.
     *
     * The search is scoped to the `getSlug()` body. A lazy `/s` match over the
     * whole file runs straight past `return self::SLUG;` and picks up the next
     * quoted return — which belongs to `getName()`. That read
     * `list_mcp_tools` as answering to `List MCP Tools`.
     *
     * A body that returns a class constant is resolved through this file's
     * `const` declaration, because `return self::SLUG;` is otherwise opaque to
     * a source scan.
     *
     * An unrecognised shape yields an empty string rather than a guess: the
     * caller treats that as "no slug", and the audit's `unclassified` check is
     * the thing that reports it.
     */
    private static function slug(string $source): string
    {
        $body = self::methodBody($source, 'getSlug');

        if (null === $body) {
            return '';
        }

        if (preg_match("/return\s+(['\"])([^'\"]+)\\1\s*;/", $body, $literal)) {
            return $literal[2];
        }

        if (preg_match('/return\s+self::(\w+)\s*;/', $body, $constant)) {
            $pattern = "/const\\s+" . preg_quote($constant[1], '/') . "\\s*=\\s*(['\"])([^'\"]+)\\1/";

            if (preg_match($pattern, $source, $value)) {
                return $value[2];
            }
        }

        return '';
    }

    /**
     * The body of a method, found by balanced braces.
     *
     * Braces inside string literals in the body would miscount, so this is only
     * applied to accessors, whose bodies are a single return.
     */
    private static function methodBody(string $source, string $method): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = strpos($source, '{', (int) $match[0][1]);

        if (false === $start) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            if ('{' === $source[$i]) {
                $depth++;
            } elseif ('}' === $source[$i]) {
                $depth--;

                if (0 === $depth) {
                    return substr($source, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    /**
     * Read the parameters of a method, by balanced parentheses.
     *
     * @param string $source Class source.
     * @param string $method Method name.
     *
     * @return array<int, string> Short type names, in declaration order.
     */
    private static function parameters(string $source, string $method): array
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $open = strpos($source, '(', (int) $match[0][1]);
        $depth = 0;
        $end = $open;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ('(' === $source[$i]) {
                $depth++;
            } elseif (')' === $source[$i]) {
                $depth--;
                if (0 === $depth) {
                    $end = $i;
                    break;
                }
            }
        }

        $raw = substr($source, $open + 1, $end - $open - 1);
        $parts = array();
        $buffer = '';
        $depth = 0;

        foreach (str_split($raw) as $char) {
            if (in_array($char, array('(', '[', '<'), true)) {
                $depth++;
            } elseif (in_array($char, array(')', ']', '>'), true)) {
                $depth--;
            }

            if (',' === $char && 0 === $depth) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if ('' !== trim($buffer)) {
            $parts[] = $buffer;
        }

        $types = array();
        foreach ($parts as $part) {
            if (preg_match('/([\\\\\w?]+)\s+&?\$\w+/', $part, $type)) {
                $name = ltrim($type[1], '?');
                $types[] = substr($name, (int) strrpos('\\' . $name, '\\'));
            }
        }

        return $types;
    }
}
