<?php

/**
 * Toolkit parity — what the Pro toolkits declare, and how much of it this
 * runtime can actually serve.
 *
 * Proposal 164 §164.6 Phase 6. Prints the §164.1 table straight from the tree,
 * so the number in a proposal cannot drift away from the number in the code.
 *
 * ## What this gate fails on, and what it deliberately does not
 *
 * It fails on **regression**: a slug that the checked-in baseline records as
 * served and that is no longer servable. It does **not** fail on a ratio, and
 * that is a decision rather than an omission — a ratio gate rewards porting a
 * tool badly to move a number. §164.4.8 already forbids the shape that would
 * produce; this gate exists to stop the opposite, silent loss.
 *
 * ## Where the two sides come from
 *
 * - **Declared**: `get_slug()` in `addons/pro/includes/tools/<toolkit>/*.php`,
 *   extracted statically (the classes cannot be loaded outside WordPress).
 * - **Served**: `lib/core/resources/tool-readiness.php`, counting only the
 *   servable readiness classes (`ready`, `pure`, `delegated`) — the same
 *   authority `bin/audit-tool-readiness.php` uses.
 *
 * A toolkit with no manifest still appears here if it has tools: the console
 * axis and the tool axis are separate, and `site-creator-toolkit` is the case
 * that is missing on both.
 *
 * ## Usage
 *
 *     php bin/toolkit-parity.php            # print the table
 *     php bin/toolkit-parity.php --json     # machine-readable
 *     php bin/toolkit-parity.php --check    # regression gate (exit 1 on loss)
 *     php bin/toolkit-parity.php --write    # rewrite the baseline
 *
 * @package Nvoos\Tools
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Read one option that takes a value, e.g. `--baseline=path`.
 */
function option_value(string $name): ?string
{
	foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
		if (str_starts_with($argument, '--'.$name.'=')) {
			return substr($argument, strlen($name) + 3);
		}
	}

	return null;
}

/**
 * The servable slugs, from the readiness manifest.
 *
 * @return array<string, bool>
 */
function servable_slugs(string $manifestPath): array
{
	$manifest = require $manifestPath;
	$servable = array();
	$counted  = array('ready', 'pure', 'delegated');

	foreach ((array) ($manifest['tools'] ?? array()) as $slug => $row) {
		$readiness = (string) ($row['readiness'] ?? 'unverified');

		if (in_array($readiness, $counted, true)) {
			$servable[$slug] = true;
		}
	}

	return $servable;
}

/**
 * Slugs a PHP file declares in `get_slug()`.
 *
 * Static on purpose: a Pro tool class extends WordPress types that are not
 * autoloadable from a CLI script. The regex accepts an optional return type
 * and requires a literal string, which is what the corpus uses; anything else
 * is reported as unreadable rather than silently skipped.
 *
 * @return array{slugs: list<string>, unreadable: bool}
 */
function declared_slugs(string $file): array
{
	$source = (string) file_get_contents($file);

	// Abstract bases and contracts declare the method without a slug. They are
	// not a tool, so an absent literal is correct rather than unreadable.
	if (1 === preg_match('/^\s*(?:abstract\s+class|interface)\s/mi', $source)) {
		return array('slugs' => array(), 'unreadable' => false);
	}

	if (false === strpos($source, 'function get_slug')) {
		return array('slugs' => array(), 'unreadable' => false);
	}

	$matched = preg_match_all(
		"/function\s+get_slug\s*\(\s*\)[^{;]*\{[^}]*?return\s+'([^']+)'/s",
		$source,
		$found
	);

	if (false === $matched || 0 === $matched) {
		return array('slugs' => array(), 'unreadable' => true);
	}

	return array('slugs' => array_values(array_unique($found[1])), 'unreadable' => false);
}

/**
 * Measure every toolkit directory.
 *
 * @param array<string, bool> $servable
 * @return array{toolkits: array<string, array{wp: int, ported: int, slugs: list<string>}>, unreadable: list<string>}
 */
function measure(string $toolkitRoot, array $servable): array
{
	$toolkits   = array();
	$unreadable = array();

	foreach (glob($toolkitRoot.'/*', GLOB_ONLYDIR) ?: array() as $directory) {
		$toolkit = basename($directory);
		$slugs   = array();

		foreach (glob($directory.'/*.php') ?: array() as $file) {
			$declared = declared_slugs($file);

			if ($declared['unreadable']) {
				$unreadable[] = $toolkit.'/'.basename($file);
				continue;
			}

			foreach ($declared['slugs'] as $slug) {
				$slugs[$slug] = true;
			}
		}

		if (array() === $slugs) {
			continue;
		}

		$all = array_keys($slugs);
		sort($all);

		$ported = array_values(array_filter($all, static fn (string $slug): bool => isset($servable[$slug])));

		$toolkits[$toolkit] = array(
			'wp'     => count($all),
			'ported' => count($ported),
			'slugs'  => $ported,
		);
	}

	ksort($toolkits);

	return array('toolkits' => $toolkits, 'unreadable' => $unreadable);
}

/**
 * Load a baseline document.
 *
 * @return array<string, list<string>>
 */
function load_baseline(string $path): array
{
	if (!is_file($path)) {
		return array();
	}

	$decoded = json_decode((string) file_get_contents($path), true);

	return is_array($decoded['toolkits'] ?? null) ? $decoded['toolkits'] : array();
}

/**
 * Slugs the baseline records as served but that are served no longer.
 *
 * @param array<string, list<string>> $baseline
 * @param array<string, array{wp: int, ported: int, slugs: list<string>}> $measured
 * @return list<string>
 */
function regressions(array $baseline, array $measured): array
{
	$lost = array();

	foreach ($baseline as $toolkit => $slugs) {
		if (!isset($measured[$toolkit])) {
			$lost[] = $toolkit.' (toolkit disappeared)';
			continue;
		}

		$served = array_flip($measured[$toolkit]['slugs']);

		foreach ((array) $slugs as $slug) {
			if (!isset($served[$slug])) {
				$lost[] = $toolkit.'/'.$slug;
			}
		}
	}

	return $lost;
}

$servable = servable_slugs($root.'/lib/core/resources/tool-readiness.php');
$result   = measure($root.'/addons/pro/includes/tools', $servable);
$baseline = load_baseline(option_value('baseline') ?? $root.'/lib/core/resources/toolkit-parity-baseline.json');

$totalDeclared = array_sum(array_column($result['toolkits'], 'wp'));
$totalPorted   = array_sum(array_column($result['toolkits'], 'ported'));

if (in_array('--json', $_SERVER['argv'] ?? array(), true)) {
	echo json_encode(
		array(
			'toolkits' => $result['toolkits'],
			'unreadable' => $result['unreadable'],
			'totals' => array('declared' => $totalDeclared, 'ported' => $totalPorted),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)."\n";
	exit(0);
}

if (in_array('--write', $_SERVER['argv'] ?? array(), true)) {
	$document = array(
		'$comment' => 'Generated by bin/toolkit-parity.php --write. Regenerate after a port; this gate fails only on a slug disappearing.',
		'toolkits' => array_map(
			static fn (array $row): array => $row['slugs'],
			$result['toolkits']
		),
	);

	$encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	if (false === $encoded) {
		fwrite(STDERR, "Could not encode the baseline.\n");
		exit(1);
	}

	file_put_contents($root.'/lib/core/resources/toolkit-parity-baseline.json', $encoded."\n");
	echo "Baseline written: {$totalPorted} ported of {$totalDeclared} across ".count($result['toolkits'])." toolkits.\n";
	exit(0);
}

$check = in_array('--check', $_SERVER['argv'] ?? array(), true);

if ($check) {
	$lost = regressions($baseline, $result['toolkits']);

	if (array() !== $result['unreadable']) {
		fwrite(STDERR, "Unreadable tool classes (get_slug is not a literal):\n");
		foreach ($result['unreadable'] as $file) {
			fwrite(STDERR, "  - {$file}\n");
		}

		exit(1);
	}

	if (array() !== $lost) {
		fwrite(STDERR, "Toolkit parity regression — these were served and are not:\n");
		foreach ($lost as $slug) {
			fwrite(STDERR, "  - {$slug}\n");
		}
		fwrite(STDERR, "\nRestore them, or run `php bin/toolkit-parity.php --write` if the loss is intended.\n");

		exit(1);
	}

	echo "Toolkit parity: no regression across ".count($result['toolkits'])." toolkits.\n";
	exit(0);
}

echo "\n| Toolkit | WP tools | Ported |\n";
echo "|---|---:|---:|\n";

foreach ($result['toolkits'] as $toolkit => $row) {
	printf("| `%s` | %d | %d |\n", $toolkit, $row['wp'], $row['ported']);
}

printf("| **Total** | **%d** | **%d** |\n\n", $totalDeclared, $totalPorted);

if (array() !== $result['unreadable']) {
	echo "Unreadable tool classes: ".implode(', ', $result['unreadable'])."\n\n";
}

echo "A ratio is not a gate: this table is measured, and `--check` fails only on regression.\n";
