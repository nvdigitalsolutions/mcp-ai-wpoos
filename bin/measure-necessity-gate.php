<?php

/**
 * Measure what the necessity gate decides across the servable corpus.
 *
 * Proposal 149 §155. Answers "can `oos.necessity_gate` be switched on?" with
 * numbers instead of intuition.
 *
 * Unlike `bin/audit-tool-readiness.php`, this cannot run on plain PHP: it needs
 * a booted application to resolve every tool from its real container bindings.
 * Run it inside the app container through tinker:
 *
 *     php artisan tinker --execute="require '/path/to/bin/measure-necessity-gate.php';"
 *
 * Two argument shapes are measured, because the verdict depends on them:
 *
 *   schema args   every required parameter filled from the tool's own schema —
 *                 what a real, well-formed call looks like
 *   empty args    no arguments at all — the pessimistic case, and the shape
 *                 §150.5 measured
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Domain\Service\Safety\NecessityGate;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;

$root = \dirname(__DIR__);

$manifest = require $root . '/lib/core/resources/tool-readiness.php';
$rows     = $manifest['tools'];
$servable = array(
	'ready'     => true,
	'pure'      => true,
	'delegated' => true,
);

$registry = app(ToolRegistry::class);

/**
 * Fill every required parameter from the tool's own schema.
 *
 * @param array<string, mixed> $schema JSON schema.
 * @return array<string, mixed>
 */
$schemaArgs = static function (array $schema): array {
	$properties = $schema['properties'] ?? array();
	$required   = $schema['required'] ?? array();
	$arguments  = array();

	foreach ($required as $key) {
		$type = $properties[$key]['type'] ?? 'string';

		if (\is_array($type)) {
			$type = $type[0] ?? 'string';
		}

		$arguments[$key] = match ($type) {
			'integer' => 1,
			'number'  => 1,
			'boolean' => true,
			'array'   => array('a'),
			'object'  => array('a' => 1),
			default   => 'x',
		};
	}

	return $arguments;
};

$bucket = static function (string $verdict): string {
	return match ($verdict) {
		'allow'             => 'allow',
		'skip'              => 'skip',
		'warn'              => 'warn',
		'approval_required' => 'approval',
		'block'             => 'block',
		default             => 'block',
	};
};

$stats = array(
	'schema args' => array('allow' => 0, 'skip' => 0, 'warn' => 0, 'approval' => 0, 'block' => 0),
	'empty args'  => array('allow' => 0, 'skip' => 0, 'warn' => 0, 'approval' => 0, 'block' => 0),
);

$measured     = 0;
$safe         = 0;
$noFlags      = array();
$unresolved   = array();
$approvalList = array();
$blockList    = array();
$reasonCodes  = array();
$readish      = array();

foreach ($rows as $slug => $row) {
	if (! isset($servable[$row['readiness']])) {
		continue;
	}

	++$measured;

	$tool = null;

	try {
		$tool = $registry->get($slug);
	} catch (\Throwable $e) {
		$tool = null;
	}

	if (null === $tool) {
		$unresolved[] = $slug;
	}

	$flags = $tool instanceof ToolCapabilityFlagsInterface
		? $tool->getCapabilityFlags()
		: array();

	// Same fallback order as NecessityGuard and ReadOnlyToolGuard.
	if (array() === $flags) {
		$flags = ToolCapabilityFlagCatalogue::for($slug);
	}

	if (array() === $flags) {
		$noFlags[] = $slug;
	}

	$normalised = CapabilityFlagVocabulary::normalise($flags);

	if (
		! \in_array('read-only', $normalised, true)
		&& array() !== \array_intersect($normalised, array('read', 'database-read', 'safe', 'readonly'))
	) {
		$readish[] = $slug . ' [' . \implode(',', $normalised) . ']';
	}

	if (NecessityGate::isSafeTool($flags)) {
		++$safe;
	}

	$schema     = $tool?->getParametersSchema() ?? array();
	$decisions  = array(
		'schema args' => NecessityGate::decide($flags, $slug, $schemaArgs($schema)),
		'empty args'  => NecessityGate::decide($flags, $slug, array()),
	);

	foreach ($decisions as $label => $decision) {
		++$stats[$label][$bucket($decision['verdict'])];

		if ('schema args' !== $label) {
			continue;
		}

		$reasonCodes[$decision['reason_code']] = ($reasonCodes[$decision['reason_code']] ?? 0) + 1;

		if ('approval' === $bucket($decision['verdict'])) {
			$approvalList[] = $slug . ' [' . \implode(',', $flags) . '] — ' . $decision['reason_code'];
		}

		if ('block' === $bucket($decision['verdict'])) {
			$blockList[] = $slug . ' [' . \implode(',', $flags) . '] — ' . $decision['reason_code'];
		}
	}
}

\printf("servable rows measured  : %d\n", $measured);
\printf("tier-1 safe (flags only) : %d (%.0f%%)\n", $safe, 100 * $safe / \max(1, $measured));
\printf("rows with no flags       : %d\n", \count($noFlags));
\printf("rows the registry could not resolve: %d\n", \count($unresolved));

if (array() !== $noFlags) {
	echo '  no flags: ' . \implode(', ', $noFlags) . "\n";
}

if (array() !== $unresolved) {
	echo '  unresolved: ' . \implode(', ', $unresolved) . "\n";
}

echo "\nverdicts\n";
\printf("  %-12s %7s %7s %6s %10s %6s\n", 'input', 'allow', 'skip', 'warn', 'approval', 'block');

foreach ($stats as $label => $row) {
	\printf(
		"  %-12s %7d %7d %6d %10d %6d\n",
		$label,
		$row['allow'],
		$row['skip'],
		$row['warn'],
		$row['approval'],
		$row['block']
	);
}

echo "\nreason codes (schema args)\n";
\arsort($reasonCodes);

foreach ($reasonCodes as $code => $count) {
	\printf("  %-32s %d\n", $code, $count);
}

echo "\napproval-required (schema args)\n";

foreach ($approvalList as $line) {
	echo '  ' . $line . "\n";
}

echo "\nblocked (schema args)\n";

foreach ($blockList as $line) {
	echo '  ' . $line . "\n";
}

echo "\nread-ish flags that are not `read-only` (" . \count($readish) . ")\n";

foreach ($readish as $line) {
	echo '  ' . $line . "\n";
}
