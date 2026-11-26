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
 * ## Where the three sides come from
 *
 * - **Declared**: `get_slug()` in `addons/pro/includes/tools/<toolkit>/*.php`,
 *   extracted statically (the classes cannot be loaded outside WordPress).
 * - **Served**: `lib/core/resources/tool-readiness.php`, counting only the
 *   servable readiness classes (`ready`, `pure`, `delegated`) — the same
 *   authority `bin/audit-tool-readiness.php` uses.
 * - **Decided**: `lib/core/resources/toolkit-parity-decisions.json`, the slugs
 *   this programme has chosen not to port. `declined` is subtracted from
 *   `remaining` because it is not work; `deferred` stays because it is work
 *   once a named prerequisite lands.
 *
 * A toolkit with no manifest still appears here if it has tools: the console
 * axis and the tool axis are separate, and `site-creator-toolkit` is the case
 * that is missing on both.
 *
 * ## Read `remaining`, not `wp − ported`
 *
 * Without the register the only way to shrink this count was to port a tool,
 * so "not built yet" and "will never be built" read identically. A register is
 * only worth having if it is checked, so `--check` also fails when a decision
 * is inconsistent with the tree: an unknown decision word, a missing reason, a
 * slug no toolkit declares, or a slug recorded as not-ported that is served.
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

use Nvoos\Core\Application\Tool\ToolkitParityDecisions;

$root = dirname(__DIR__);

// The register has no dependencies of its own, so it is required directly
// rather than through an autoloader this script does not otherwise need
// (`lib/core/vendor` is a dev dependency and absent in CI's checkout).
require_once $root . '/lib/core/src/Application/Tool/ToolkitParityDecisions.php';

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
 * A slug is counted once, in this order: served (the manifest decides), then
 * declined (never to be ported), then deferred (blocked). `remaining` is what
 * is left over, and it is the column to read.
 *
 * @param array<string, bool> $servable
 * @return array{
 *     toolkits: array<string, array{wp: int, ported: int, declined: int, deferred: int, remaining: int, slugs: list<string>, declined_slugs: list<string>, deferred_slugs: list<string>}>,
 *     declared: array<string, bool>,
 *     unreadable: list<string>
 * }
 */
function measure(string $toolkitRoot, array $servable, ToolkitParityDecisions $decisions): array
{
	$toolkits    = array();
	$unreadable  = array();
	$declaredAll = array();

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

		$ported   = array();
		$declined = array();
		$deferred = array();

		foreach ($all as $slug) {
			$declaredAll[$slug] = true;

			if (isset($servable[$slug])) {
				$ported[] = $slug;
				continue;
			}

			if ($decisions->isDeclined($slug)) {
				$declined[] = $slug;
				continue;
			}

			if ($decisions->isDeferred($slug)) {
				$deferred[] = $slug;
			}
		}

		$toolkits[$toolkit] = array(
			'wp'             => count($all),
			'ported'         => count($ported),
			'declined'       => count($declined),
			'deferred'       => count($deferred),
			'remaining'      => count($all) - count($ported) - count($declined) - count($deferred),
			'slugs'          => $ported,
			'declined_slugs' => $declined,
			'deferred_slugs' => $deferred,
		);
	}

	ksort($toolkits);

	return array('toolkits' => $toolkits, 'declared' => $declaredAll, 'unreadable' => $unreadable);
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
 * @param array<string, array{slugs: list<string>}> $measured Only `slugs` is read.
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

$decisions = new ToolkitParityDecisions();
$servable  = servable_slugs($root.'/lib/core/resources/tool-readiness.php');
$result    = measure($root.'/addons/pro/includes/tools', $servable, $decisions);
$baseline  = load_baseline(option_value('baseline') ?? $root.'/lib/core/resources/toolkit-parity-baseline.json');

$totalDeclared  = array_sum(array_column($result['toolkits'], 'wp'));
$totalPorted    = array_sum(array_column($result['toolkits'], 'ported'));
$totalDeclined  = array_sum(array_column($result['toolkits'], 'declined'));
$totalDeferred  = array_sum(array_column($result['toolkits'], 'deferred'));
$totalRemaining = array_sum(array_column($result['toolkits'], 'remaining'));

$violations = $decisions->violations($result['declared'], $servable);

if (in_array('--json', $_SERVER['argv'] ?? array(), true)) {
	echo json_encode(
		array(
			'toolkits' => $result['toolkits'],
			'unreadable' => $result['unreadable'],
			'totals' => array(
				'declared'  => $totalDeclared,
				'ported'    => $totalPorted,
				'declined'  => $totalDeclined,
				'deferred'  => $totalDeferred,
				'remaining' => $totalRemaining,
			),
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

	if (array() !== $violations) {
		fwrite(STDERR, "Toolkit parity decisions are inconsistent with the tree:\n");
		foreach ($violations as $violation) {
			fwrite(STDERR, "  - {$violation}\n");
		}
		fwrite(STDERR, "\nFix lib/core/resources/toolkit-parity-decisions.json. A register that lies is worse than none.\n");

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

	echo "Toolkit parity: no regression across ".count($result['toolkits'])." toolkits ({$totalPorted} ported, {$totalDeclined} declined, {$totalDeferred} deferred, {$totalRemaining} remaining).\n";
	exit(0);
}

echo "\n| Toolkit | WP tools | Ported | Declined | Deferred | Remaining |\n";
echo "|---|---:|---:|---:|---:|---:|\n";

foreach ($result['toolkits'] as $toolkit => $row) {
	printf("| `%s` | %d | %d | %d | %d | %d |\n", $toolkit, $row['wp'], $row['ported'], $row['declined'], $row['deferred'], $row['remaining']);
}

printf("| **Total** | **%d** | **%d** | **%d** | **%d** | **%d** |\n\n", $totalDeclared, $totalPorted, $totalDeclined, $totalDeferred, $totalRemaining);

if (array() !== $violations) {
	echo "Decision register inconsistencies (the `--check` gate fails on these):\n";

	foreach ($violations as $violation) {
		echo "  - {$violation}\n";
	}

	echo "\n";
}

if (array() !== $result['unreadable']) {
	echo "Unreadable tool classes: ".implode(', ', $result['unreadable'])."\n\n";
}

echo "A ratio is not a gate: this table is measured and `--check` fails only on regression. `Remaining` subtracts what the register says will never be ported and keeps what is merely blocked.\n";
