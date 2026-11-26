#!/usr/bin/env php
<?php

/**
 * Proposal 149 — WordPress admin surface coverage audit.
 *
 * Answers one question: **which WordPress admin files have no disposition in
 * the parity proposal?**
 *
 * The proposal dispositions the admin tree in Markdown tables
 * ("Legend: **P** ported …" through the next top-level heading), naming each
 * surface by a backticked stem (`admin-test-model`, `provider-diagnostics`) or
 * by a glob for a grouped family (`*-toolkit-settings-page`). That table is the
 * only record that a screen was *considered*; a file it does not name is a
 * screen nobody decided about.
 *
 * Three scopes, because a page can live outside the unit the inventory counted:
 *
 *   1. `includes/admin/*.php`          — the inventory unit (85 files).
 *   2. `includes/admin/**` (nested)     — outside the count, still in the tree.
 *   3. `includes/**` minus `admin/`     — a page registered here is invisible to
 *                                         an inventory that only walks admin/.
 *
 * Matching rules, and why they are loose in one direction only:
 *
 *   - A file matches a token when the stems are equal, or when the file stem
 *     starts with `<token>-` **and** the token looks like a file stem
 *     (`isFileStem()`). So a family row `pro-dashboard` covers
 *     `pro-dashboard-helper`, while a console key `settings` cannot silently
 *     absorb `settings-registry`.
 *   - A file is an orphan only when **no** token matches it. Tokens are matched
 *     against both the full stem and the `pro-`-stripped one.
 *
 * What it cannot know:
 *
 *   - A file named only in prose counts as named ("stem matching, not content
 *     matching"). The report says which set matched, so the distinction is
 *     visible.
 *   - Menu detection is heuristic: comments are stripped and method
 *     *definitions* are rewritten before the call search, but a dynamically
 *     built menu slug will not be seen.
 *   - Pro is dispositioned by family. Its orphans are reported for information;
 *     only core orphans fail the script.
 *
 * Usage:
 *   php bin/audit-wp-admin-surfaces.php [--json] [--report-only]
 *
 * Exit codes:
 *   0  every file in scope 1 is named (or --report-only was passed)
 *   1  at least one file in scope 1 has no disposition
 */

$root = dirname(__DIR__);
$reportOnly = in_array('--report-only', $argv, true);
$asJson = in_array('--json', $argv, true);

$proposalPath = $root.'/docs/project/proposals/149-laravel-admin-parity-proposal.md';

if (! is_file($proposalPath)) {
    fwrite(STDERR, 'missing proposal: '.$proposalPath.PHP_EOL);
    exit(1);
}

$proposal = (string) file_get_contents($proposalPath);
$dispositions = parseDispositions($proposal);

if (null === $dispositions) {
    fwrite(STDERR, 'could not locate the disposition block in the proposal'.PHP_EOL);
    exit(1);
}

$core = scanTree($root.'/includes/admin', false);
$coreNested = scanTree($root.'/includes/admin', true);
$outside = scanOutside($root.'/includes', $root.'/includes/admin');
$pro = scanTree($root.'/addons/pro/includes/admin', false);

$coreResult = matchFiles($core, $dispositions, $root);
$nestedResult = matchFiles($coreNested, $dispositions, $root);
$outsideResult = matchFiles($outside, $dispositions, $root);
$proResult = matchFiles($pro, $dispositions, $root);

// Outside the inventory unit, an "orphan" is not a meaningful category: those
// trees are full of infrastructure the proposal groups by family. The only
// question worth asking there is whether a *page* registers a menu and, if so,
// whether the proposal names it.
$nestedPages = pagesWithStatus($coreNested, $dispositions, $root);
$outsidePages = pagesWithStatus($outside, $dispositions, $root);

$unmatched = unmatchedTokens(
    $dispositions['names'],
    array_merge($coreResult['matched'], $nestedResult['matched'], $outsideResult['matched'], $proResult['matched'])
);

$report = array(
    'proposal' => relative($root, $proposalPath),
    'core' => array(
        'scope' => 'includes/admin/*.php',
        'files' => count($core),
        'named' => count($core) - count($coreResult['orphans']),
        'orphans' => $coreResult['orphans'],
    ),
    'core_nested' => array(
        'scope' => 'includes/admin/** (excluding top level)',
        'files' => count($coreNested),
        'pages' => $nestedPages,
    ),
    'outside' => array(
        'scope' => 'includes/** minus includes/admin/',
        'files' => count($outside),
        'pages' => $outsidePages,
    ),
    'pro' => array(
        'scope' => 'addons/pro/includes/admin/*.php',
        'files' => count($pro),
        'named' => count($pro) - count($proResult['orphans']),
        'orphans' => $proResult['orphans'],
        'grouped_by_design' => true,
    ),
    'unmatched_tokens' => $unmatched,
    'count_drift' => array(
        'core_claimed' => $dispositions['claimed']['core'],
        'core_actual' => count($core),
        'pro_claimed' => $dispositions['claimed']['pro'],
        'pro_actual' => count($pro),
    ),
);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    render($report);
}

$failed = array() !== $report['core']['orphans'] && ! $reportOnly;

exit($failed ? 1 : 0);

// ── Parsing ─────────────────────────────────────────────────────────────

/**
 * Pull the disposition tables out of the proposal.
 *
 * @return array{names: array<string, string>, globs: list<string>, claimed: array{core: int|null, pro: int|null}}|null
 */
function parseDispositions(string $proposal): ?array
{
    $start = strpos($proposal, 'Legend: **P** ported');

    if (false === $start) {
        return null;
    }

    // The tables end at the next top-level heading after the Pro table.
    // Stopping at an appendix would include the console-key table, whose rows
    // are keys rather than files, and every one would read as a stale entry.
    $proStart = strpos($proposal, '### Pro (`addons/pro/includes/admin/`');
    $end = false === $proStart ? false : strpos($proposal, "\n## ", $proStart);

    if (false === $end) {
        $end = strlen($proposal);
    }

    $block = substr($proposal, $start, $end - $start);

    preg_match_all('/`([^`\n]+)`/', $block, $matches);

    $names = array();
    $globs = array();
    $previous = '';

    foreach ($matches[1] as $raw) {
        $token = trim($raw);

        // Headings and prose carry paths in backticks (`includes/admin/`);
        // a path is not a disposition token.
        if ('' === $token || str_contains($token, '/')) {
            continue;
        }

        // Table shorthand: `admin-test-assistant`, `-model` … The suffix form
        // inherits the previous token's prefix, which is how the proposal
        // lists four sibling pages without repeating the whole stem.
        if (str_starts_with($token, '-') && '' !== $previous) {
            $cut = strrpos($previous, '-');
            $token = (false === $cut ? $previous : substr($previous, 0, $cut)).$token;
        }

        $previous = $token;

        if (str_contains($token, '*')) {
            $globs[] = normalise($token);

            continue;
        }

        foreach (variants($token) as $variant) {
            $names[$variant] = $token;
        }
    }

    return array(
        'names' => $names,
        'globs' => array_values(array_unique($globs)),
        'claimed' => array(
            'core' => claim($proposal, 'includes/admin/'),
            'pro' => claim($proposal, 'addons/pro/includes/admin/'),
        ),
    );
}

/**
 * The "85 files" figure the proposal claims for a tree, if it states one.
 */
function claim(string $proposal, string $path): ?int
{
    if (preg_match('/`'.preg_quote($path, '/').'`, (\d+) files/', $proposal, $m)) {
        return (int) $m[1];
    }

    return null;
}

// ── Scanning and matching ───────────────────────────────────────────────

/**
 * @return list<array{path: string, stem: string, menu: bool}>
 */
function scanTree(string $directory, bool $nested): array
{
    return collect($directory, static function (string $path) use ($directory, $nested): bool {
        $isTopLevel = dirname($path) === rtrim($directory, '/\\');

        return $nested !== $isTopLevel;
    });
}

/**
 * Every admin-ish file under `includes/` except the `includes/admin/` tree.
 *
 * A page registered here is invisible to an inventory that only walks
 * `includes/admin/` — which is exactly how the Measurement dashboard went
 * uncounted for a year.
 *
 * @return list<array{path: string, stem: string, menu: bool}>
 */
function scanOutside(string $includes, string $adminDir): array
{
    $adminPrefix = rtrim(str_replace('\\', '/', $adminDir), '/').'/';

    return collect($includes, static function (string $path) use ($adminPrefix): bool {
        return ! str_starts_with(str_replace('\\', '/', $path), $adminPrefix);
    });
}

/**
 * @param callable(string): bool $include
 *
 * @return list<array{path: string, stem: string, menu: bool}>
 */
function collect(string $directory, callable $include): array
{
    if (! is_dir($directory)) {
        return array();
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    $files = array();

    foreach ($iterator as $file) {
        if (! $file->isFile() || 'php' !== strtolower($file->getExtension())) {
            continue;
        }

        $path = $file->getPathname();

        if (! $include($path)) {
            continue;
        }

        $files[] = array(
            'path' => $path,
            'stem' => normalise(basename($path)),
            'menu' => registersMenu($path),
        );
    }

    usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

    return $files;
}

/**
 * Does this file register an admin menu?
 *
 * Comments are stripped and the handler *definitions* are rewritten first: a
 * class that defines `add_menu_page()` as a callback is not itself a page, and
 * a docblock that mentions the function is not a call.
 */
function registersMenu(string $path): bool
{
    $code = (string) file_get_contents($path);
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
    $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;
    $code = preg_replace(
        '/function\s+(add_menu_page|add_submenu_page)\s*\(/',
        'function __menu_handler(',
        $code
    ) ?? $code;

    return str_contains($code, 'add_menu_page(') || str_contains($code, 'add_submenu_page(');
}

/**
 * @param list<array{path: string, stem: string, menu: bool}> $files
 * @param array{names: array<string, string>, globs: list<string>} $dispositions
 *
 * @return array{orphans: list<array{path: string, menu: bool}>, matched: array<string, true>}
 */
function matchFiles(array $files, array $dispositions, string $root): array
{
    $orphans = array();
    $matchedTokens = array();

    foreach ($files as $file) {
        $fileMatched = false;

        foreach (variants($file['stem'], true) as $candidate) {
            foreach ($dispositions['names'] as $variant => $original) {
                if ($candidate === $variant) {
                    $matchedTokens[$original] = true;
                    $fileMatched = true;

                    continue;
                }

                // A family row covers its suffix variants: `pro-status-dashboard`
                // disposes `pro-status-dashboard-page`. Prefix matching is
                // limited to file-ish tokens — see isFileStem().
                if (isFileStem($variant) && str_starts_with($candidate, $variant.'-')) {
                    $matchedTokens[$original] = true;
                    $fileMatched = true;
                }
            }

            foreach ($dispositions['globs'] as $glob) {
                if (fnmatch($glob, $candidate)) {
                    $matchedTokens[$glob] = true;
                    $fileMatched = true;
                }
            }
        }

        if (! $fileMatched) {
            $orphans[] = array(
                'path' => relative($root, $file['path']),
                'menu' => $file['menu'],
            );
        }
    }

    return array(
        'orphans' => $orphans,
        'matched' => $matchedTokens,
    );
}

/**
 * Menu-registering files with a named/unnamed flag.
 *
 * @param list<array{path: string, stem: string, menu: bool}> $files
 * @param array{names: array<string, string>, globs: list<string>} $dispositions
 *
 * @return list<array{path: string, named: bool}>
 */
function pagesWithStatus(array $files, array $dispositions, string $root): array
{
    $pages = array();

    foreach ($files as $file) {
        if ($file['menu']) {
            $pages[] = $file;
        }
    }

    $result = matchFiles($pages, $dispositions, $root);
    $orphaned = array_column($result['orphans'], 'path');
    $out = array();

    foreach ($pages as $page) {
        $path = relative($root, $page['path']);
        $out[] = array(
            'path' => $path,
            'named' => ! in_array($path, $orphaned, true),
        );
    }

    return $out;
}

/**
 * Disposition tokens that named no file in any scope.
 *
 * Calculated once across all scopes: a Pro-only token is not "stale" merely
 * because it did not match a core file.
 *
 * @param array<string, string> $names Token variants => original token.
 * @param array<string, true> $matched Tokens that matched at least one file.
 *
 * @return list<string>
 */
function unmatchedTokens(array $names, array $matched): array
{
    $unmatched = array();

    foreach (array_unique($names) as $original) {
        if (isset($matched[$original])) {
            continue;
        }

        // Console keys and domain names are not file claims; listing them as
        // "stale" would bury the ones that are.
        if (! isFileStem($original)) {
            continue;
        }

        $unmatched[] = $original;
    }

    sort($unmatched);

    return $unmatched;
}

/**
 * Is this token a file stem rather than a console key or a domain name?
 *
 * Only file stems take part in prefix matching. Function-local static, not an
 * inline const: inline const is executed in file order, and this script's main
 * body runs before the declarations below it.
 */
function isFileStem(string $token): bool
{
    static $suffixes = array(
        'page', 'pages', 'settings', 'dashboard', 'diagnostic', 'diagnostics',
        'manager', 'monitor', 'builder', 'editor', 'timeline', 'import',
        'telemetry', 'research', 'metabox', 'metaboxes', 'columns', 'handler',
        'handlers', 'renderer', 'helper', 'rest', 'base', 'init', 'registry',
        'validator', 'saver', 'wizard', 'admin', 'model', 'assistant', 'profession',
        'team', 'tools', 'pool', 'license', 'licence', 'database', 'commands',
        'rotation', 'ajax', 'status', 'creator', 'overview', 'loader', 'menu',
        'queue',
    );

    $cut = strrpos($token, '-');

    if (false === $cut) {
        return false;
    }

    return in_array(substr($token, $cut + 1), $suffixes, true);
}

// ── Naming ──────────────────────────────────────────────────────────────

/**
 * Every form a file or token can take before comparison.
 *
 * `class-wp-mcp-ai-admin-test-model.php` must match `admin-test-model`, and
 * `class-wp-mcp-ai-pro-schedule-manager-page.php` must match both
 * `pro-schedule-manager-page` and `schedule-manager-page`.
 *
 * @return list<string>
 */
function variants(string $value, bool $alreadyNormalised = false): array
{
    $base = $alreadyNormalised ? $value : normalise($value);

    $out = array($base);

    if (str_starts_with($base, 'pro-')) {
        $out[] = substr($base, 4);
    }

    return array_values(array_unique($out));
}

function normalise(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\.php$/', '', $value) ?? $value;
    $value = preg_replace('/^class-/', '', $value) ?? $value;
    $value = preg_replace('/^(wp-mcp-ai|nvoos)-/', '', $value) ?? $value;
    $value = preg_replace('/^(pro-)?class-/', '', $value) ?? $value;

    return $value;
}

function relative(string $root, string $path): string
{
    $base = rtrim(str_replace('\\', '/', $root), '/').'/';

    return str_replace($base, '', str_replace('\\', '/', $path));
}

// ── Output ──────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $report
 */
function render(array $report): void
{
    $core = $report['core'];
    $nested = $report['core_nested'];
    $outside = $report['outside'];
    $pro = $report['pro'];
    $drift = $report['count_drift'];

    echo 'WP admin surface coverage — Proposal 149'.PHP_EOL;
    echo 'proposal: '.$report['proposal'].PHP_EOL;
    echo PHP_EOL;

    echo sprintf('CORE  (%s)', $core['scope']).PHP_EOL;
    echo sprintf('  files            : %d', $core['files']).PHP_EOL;
    echo sprintf('  named            : %d', $core['named']).PHP_EOL;
    echo sprintf('  ORPHANS          : %d', count($core['orphans'])).PHP_EOL;

    foreach ($core['orphans'] as $orphan) {
        echo '    - '.$orphan['path'].($orphan['menu'] ? '  (registers an admin menu)' : '').PHP_EOL;
    }

    echo PHP_EOL;
    echo sprintf('NESTED  (%s)', $nested['scope']).PHP_EOL;
    echo sprintf('  files scanned    : %d', $nested['files']).PHP_EOL;
    echo sprintf('  pages registered : %d', count($nested['pages'])).PHP_EOL;

    foreach ($nested['pages'] as $page) {
        echo '    '.($page['named'] ? '[named]  ' : '[UNNAMED]').$page['path'].PHP_EOL;
    }

    echo PHP_EOL;
    echo sprintf('OUTSIDE THE TREE  (%s)', $outside['scope']).PHP_EOL;
    echo sprintf('  files scanned    : %d', $outside['files']).PHP_EOL;
    echo sprintf('  pages registered : %d', count($outside['pages'])).PHP_EOL;

    foreach ($outside['pages'] as $page) {
        echo '    '.($page['named'] ? '[named]  ' : '[UNNAMED]').$page['path'].PHP_EOL;
    }

    echo PHP_EOL;
    echo sprintf('PRO  (%s)', $pro['scope']).PHP_EOL;
    echo sprintf('  files            : %d', $pro['files']).PHP_EOL;
    echo sprintf('  named            : %d', $pro['named']).PHP_EOL;
    echo sprintf('  orphans          : %d   [grouped by design — informational]', count($pro['orphans'])).PHP_EOL;

    echo PHP_EOL;
    echo 'COUNT DRIFT (heading claim vs files on disk)'.PHP_EOL;
    echo '  core : claimed '.(null === $drift['core_claimed'] ? '—' : (string) $drift['core_claimed'])
        .' / actual '.$drift['core_actual']
        .($drift['core_claimed'] === $drift['core_actual'] ? '  OK' : '  DRIFT').PHP_EOL;
    echo '  pro  : claimed '.(null === $drift['pro_claimed'] ? '—' : (string) $drift['pro_claimed'])
        .' / actual '.$drift['pro_actual']
        .($drift['pro_claimed'] === $drift['pro_actual'] ? '  OK' : '  DRIFT').PHP_EOL;

    if (array() !== $report['unmatched_tokens']) {
        echo PHP_EOL.'NAMED BUT NO FILE (stale inventory entries)'.PHP_EOL;

        foreach ($report['unmatched_tokens'] as $token) {
            echo '  ? '.$token.PHP_EOL;
        }
    }

    echo PHP_EOL;

    if (array() === $core['orphans']) {
        echo 'RESULT: every file in the inventory unit is named by the proposal.'.PHP_EOL;

        return;
    }

    echo sprintf(
        'RESULT: %d file(s) in the inventory unit have no disposition. Name them (port, not-applicable, retired) before building a wave around them.%s',
        count($core['orphans']),
        PHP_EOL
    );
}
