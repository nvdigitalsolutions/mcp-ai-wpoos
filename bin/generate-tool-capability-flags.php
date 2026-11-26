<?php

/**
 * Generate the core tool capability-flag catalogue from the WordPress corpus.
 *
 * `lib/core` cannot load `includes/tools/*` at runtime: those classes extend a
 * base that needs `ABSPATH`, `__()` and the admin bootstrap. So the flags are
 * extracted once here and checked in as data.
 *
 * Extraction is static — `token_get_all()`, not reflection — because the source
 * cannot be loaded. It understands the three shapes the corpus actually uses:
 *
 *   1. `return array( 'read-only', 'cacheable' );`   literal list
 *   2. `return self::FLAGS;`                          class constant
 *   3. `return $this->original_tool->get_capability_flags();`   delegation
 *
 * Delegation is resolved by convention: a `*_validated` wrapper inherits the
 * flags of the slug it wraps, and only when that slug was itself extracted.
 * Guessing is not allowed here — an unresolved row is simply absent, and the
 * read-only guard's `block_undeclared` keeps such a tool denied.
 *
 * Usage:
 *
 *     php bin/generate-tool-capability-flags.php [--check]
 *
 * `--check` writes nothing and exits non-zero when the checked-in file is
 * stale, so CI can run it as a drift gate.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

const NV_FLAGS_TARGET = 'lib/core/src/Domain/Service/Safety/ToolCapabilityFlagCatalogue.php';
const NV_FLAGS_DIRS   = array( 'includes/tools', 'addons/pro/includes/tools' );

$root  = \dirname(__DIR__);
$check = \in_array('--check', $argv, true);

$tools = array();

foreach (NV_FLAGS_DIRS as $dir) {
    $path = $root . '/' . $dir;

    if (! \is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    $files = array();

    foreach ($iterator as $file) {
        if ('php' === \strtolower($file->getExtension())) {
            $files[] = $file->getPathname();
        }
    }

    // Directory order is not stable across platforms, and the first-declaration
    // rule below decides which of two same-slug files wins. The CI gate caught
    // exactly that: Windows and Linux picked different `when_to_use` winners
    // because the iteration order differed. Sort so the choice is the same
    // everywhere.
    \sort($files, SORT_STRING);

    foreach ($files as $file) {
        $row = nv_extract_tool((string) \file_get_contents($file));

        if (null === $row['slug'] || null === $row['flags']) {
            continue;
        }

        // First declaration wins; a later duplicate must not silently replace it.
        if (! isset($tools[$row['slug']])) {
            $tools[$row['slug']] = array(
                'flags'      => $row['flags'],
                'delegating' => $row['delegating'],
            );
        }
    }
}

$resolved = 0;

foreach ($tools as $slug => $row) {
    if (!$row['delegating'] || array() !== $row['flags']) {
        continue;
    }

    $base = \preg_replace('/_validated$/', '', $slug);

    if (null !== $base && $base !== $slug && isset($tools[$base]) && array() !== $tools[$base]['flags']) {
        $tools[$slug]['flags'] = $tools[$base]['flags'];
        ++$resolved;
    }
}

$catalogue = array();

foreach ($tools as $slug => $row) {
    if (array() !== $row['flags']) {
        $flags = $row['flags'];
        \sort($flags);
        $catalogue[$slug] = \array_values(\array_unique($flags));
    }
}

\ksort($catalogue);

$source = nv_render($catalogue);
$target = $root . '/' . NV_FLAGS_TARGET;

if ($check) {
    $current = \is_file($target) ? (string) \file_get_contents($target) : '';

    if ($current !== $source) {
        \fwrite(STDERR, NV_FLAGS_TARGET . " is stale. Run: php bin/generate-tool-capability-flags.php\n");
        exit(1);
    }

    echo 'Capability-flag catalogue is in step with the WordPress corpus ('
        . \count($catalogue) . " tools).\n";
    exit(0);
}

\file_put_contents($target, $source);

\printf(
    "Wrote %s\n  %d tools with flags (%d resolved by delegation, %d extracted)\n",
    NV_FLAGS_TARGET,
    \count($catalogue),
    $resolved,
    \count($catalogue) - $resolved
);

/**
 * Extract a slug and its flags from one tool class.
 *
 * @return array{slug: ?string, flags: ?array<int, string>, delegating: bool}
 */
function nv_extract_tool(string $src): array
{
    $tokens    = \token_get_all($src);
    $count     = \count($tokens);
    $constants = array();
    $slug      = null;
    $flags     = null;
    $delegating = false;

    // Pre-pass: string class constants, so `return self::SLUG;` resolves even
    // when the constant is declared after the method.
    for ($c = 0; $c < $count; ++$c) {
        if (! \is_array($tokens[$c]) || T_CONST !== $tokens[$c][0]) {
            continue;
        }

        $name = null;

        for ($j = $c + 1; $j < $count; ++$j) {
            if (\is_array($tokens[$j]) && T_STRING === $tokens[$j][0]) {
                $name = $tokens[$j][1];
                break;
            }

            if ('=' === $tokens[$j]) {
                break;
            }
        }

        if (null === $name) {
            continue;
        }

        for ($k = $j; $k < $count; ++$k) {
            if (\is_array($tokens[$k]) && T_CONSTANT_ENCAPSED_STRING === $tokens[$k][0]) {
                $constants[$name] = \trim($tokens[$k][1], "'\"");
                break;
            }

            if (';' === $tokens[$k]) {
                break;
            }
        }
    }

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];

        if (! \is_array($token)) {
            continue;
        }

        if (T_FUNCTION !== $token[0]) {
            continue;
        }

        $nameIndex = $i + 1;

        while ($nameIndex < $count) {
            if (\is_array($tokens[$nameIndex]) && T_STRING === $tokens[$nameIndex][0]) {
                break;
            }

            if ('{' === $tokens[$nameIndex] || ';' === $tokens[$nameIndex]) {
                break;
            }

            ++$nameIndex;
        }

        if ($nameIndex >= $count || ! \is_array($tokens[$nameIndex])) {
            continue;
        }

        $method = $tokens[$nameIndex][1];

        if ('get_slug' !== $method && 'get_capability_flags' !== $method) {
            continue;
        }

        $open = $nameIndex;

        // Stop at `;` as well as `{`: an abstract declaration
        // (`abstract public function get_slug();`) has no body, and walking
        // forward to the next brace read the FOLLOWING method's body — which
        // is how two Pro base classes both produced a slug of `when_to_use`
        // from their metadata array.
        while ($open < $count && '{' !== $tokens[$open] && ';' !== $tokens[$open]) {
            ++$open;
        }

        if ($open >= $count || ';' === $tokens[$open]) {
            continue;
        }

        $depth   = 0;
        $strings = array();
        $consts  = array();
        $delegate = false;

        for ($m = $open; $m < $count; ++$m) {
            $current = $tokens[$m];

            if ('{' === $current || (\is_array($current) && \in_array($current[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) {
                ++$depth;
                continue;
            }

            if ('}' === $current) {
                --$depth;

                if (0 === $depth) {
                    break;
                }

                continue;
            }

            if ($depth < 1) {
                continue;
            }

            if (\is_array($current) && T_CONSTANT_ENCAPSED_STRING === $current[0]) {
                $strings[] = \trim($current[1], "'\"");
                continue;
            }

            // `self::NAME` / `static::NAME`
            if (\is_array($current) && T_STRING === $current[0] && \in_array(\strtolower($current[1]), array('self', 'static'), true)) {
                for ($n = $m + 1; $n < $count; ++$n) {
                    if (\is_array($tokens[$n]) && T_STRING === $tokens[$n][0]) {
                        $consts[] = $tokens[$n][1];
                        break;
                    }

                    if (';' === $tokens[$n] || ')' === $tokens[$n]) {
                        break;
                    }
                }

                continue;
            }

            // `->get_capability_flags(` marks a delegating wrapper.
            if (\is_array($current) && T_STRING === $current[0] && 'get_capability_flags' === $current[1]) {
                $next = $m + 1;

                while ($next < $count && \is_array($tokens[$next]) && T_WHITESPACE === $tokens[$next][0]) {
                    ++$next;
                }

                if ($next < $count && '(' === $tokens[$next]) {
                    $prev = $m - 1;

                    while ($prev >= 0 && \is_array($tokens[$prev]) && \in_array($tokens[$prev][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                        --$prev;
                    }

                    if ($prev >= 0 && \is_array($tokens[$prev]) && T_OBJECT_OPERATOR === $tokens[$prev][0]) {
                        $delegate = true;
                    }
                }
            }
        }

        if ('get_slug' === $method) {
            if (null === $slug) {
                if (array() !== $strings) {
                    $slug = $strings[0];
                } elseif (array() !== $consts && isset($constants[$consts[0]])) {
                    $slug = $constants[$consts[0]];
                }
            }

            continue;
        }

        if (null === $flags) {
            if (array() !== $consts && array() === $strings) {
                // `return self::FLAGS;` — an array constant this extractor does
                // not read. Leave the row absent rather than half-populate it.
                $flags = array();
            } else {
                $flags = \array_values(\array_unique($strings));
            }

            $delegating = $delegate;
        }
    }

    return array('slug' => $slug, 'flags' => $flags, 'delegating' => $delegating);
}

/**
 * Render the catalogue as PHP source.
 *
 * @param array<string, array<int, string>> $catalogue Slug to flags.
 */
function nv_render(array $catalogue): string
{
    $lines   = array();
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = '/**';
    $lines[] = ' * Tool capability-flag catalogue — the WordPress corpus, as data.';
    $lines[] = ' *';
    $lines[] = ' * `lib/core` tool classes could not be loaded outside WordPress, so the flags';
    $lines[] = ' * a read-only profile and the necessity gate need were never ported: zero core';
    $lines[] = ' * classes implement `ToolCapabilityFlagsInterface`. Rather than restate the flags';
    $lines[] = ' * by hand, they are extracted from the corpus and checked in.';
    $lines[] = ' *';
    $lines[] = ' * **Absence means unknown, not safe.** A slug missing from this map returns an';
    $lines[] = ' * empty list, and `ReadOnlyToolGuard` then applies its `block_undeclared` rule.';
    $lines[] = ' * Tools whose WordPress counterpart does not exist — the Laravel-native cache,';
    $lines[] = ' * job, settings and utility families — are deliberately absent rather than';
    $lines[] = ' * guessed at.';
    $lines[] = ' *';
    $lines[] = ' * **Generated file.** Do not edit by hand. Regenerate with:';
    $lines[] = ' *';
    $lines[] = ' *     php bin/generate-tool-capability-flags.php';
    $lines[] = ' *';
    $lines[] = ' * @package Nvoos\Core';
    $lines[] = ' * @since   2.1.0';
    $lines[] = ' * @license MIT';
    $lines[] = ' *';
    $lines[] = ' * SPDX-License-Identifier: MIT';
    $lines[] = ' */';
    $lines[] = '';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = 'namespace Nvoos\Core\Domain\Service\Safety;';
    $lines[] = '';
    $lines[] = 'final class ToolCapabilityFlagCatalogue';
    $lines[] = '{';
    $lines[] = '    /**';
    $lines[] = '     * Slug to its declared capability flags.';
    $lines[] = '     *';
    $lines[] = '     * @var array<string, list<string>>';
    $lines[] = '     */';
    $lines[] = '    private const FLAGS = array(';

    foreach ($catalogue as $slug => $flags) {
        $rendered = array();

        foreach ($flags as $flag) {
            $rendered[] = "'" . $flag . "'";
        }

        $lines[] = "        '" . $slug . "' => array(" . \implode(', ', $rendered) . '),';
    }

    $lines[] = '    );';
    $lines[] = '';
    $lines[] = '    /**';
    $lines[] = '     * Flags declared for a slug, sorted, or an empty list when unknown.';
    $lines[] = '     *';
    $lines[] = '     * @return list<string>';
    $lines[] = '     */';
    $lines[] = '    public static function for(string $slug): array';
    $lines[] = '    {';
    $lines[] = '        return self::FLAGS[$slug] ?? array();';
    $lines[] = '    }';
    $lines[] = '';
    $lines[] = '    public static function has(string $slug): bool';
    $lines[] = '    {';
    $lines[] = '        return isset(self::FLAGS[$slug]);';
    $lines[] = '    }';
    $lines[] = '';
    $lines[] = '    /**';
    $lines[] = '     * @return array<string, list<string>>';
    $lines[] = '     */';
    $lines[] = '    public static function all(): array';
    $lines[] = '    {';
    $lines[] = '        return self::FLAGS;';
    $lines[] = '    }';
    $lines[] = '';
    $lines[] = '    public static function count(): int';
    $lines[] = '    {';
    $lines[] = '        return \count(self::FLAGS);';
    $lines[] = '    }';
    $lines[] = '}';
    $lines[] = '';

    return \implode("\n", $lines);
}
