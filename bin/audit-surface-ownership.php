#!/usr/bin/env php
<?php

/**
 * Proposal 149 — surface ownership burn-down.
 *
 * Reads docker/laravel/app-skeleton/contracts/surface-ownership.json and
 * reports what exists on which presentation. Structural problems exit 1;
 * a legitimately `planned` surface does not, so this can run in CI.
 *
 * Usage: php bin/audit-surface-ownership.php [--json]
 */

$root = dirname(__DIR__);
$path = $root.'/docker/laravel/app-skeleton/contracts/surface-ownership.json';

if (! is_file($path)) {
    fwrite(STDERR, 'missing contract: '.$path.PHP_EOL);
    exit(1);
}

$contract = json_decode((string) file_get_contents($path), true);

if (! is_array($contract) || ! isset($contract['surfaces']) || ! is_array($contract['surfaces'])) {
    fwrite(STDERR, 'invalid contract: surfaces missing'.PHP_EOL);
    exit(1);
}

$builtStates = ['full', 'read-only'];
$servedStates = ['full', 'subset', 'read-only'];

$report = [
    'total' => 0,
    'filament_built' => ['total' => 0, 'ops' => [], 'admin' => []],
    'console_served' => [],
    'console_only' => [],
    'planned_only' => ['total' => 0, 'ops' => [], 'admin' => [], 'both' => []],
];

foreach ($contract['surfaces'] as $surface) {
    $id = (string) ($surface['id'] ?? '');

    if ('' === $id) {
        fwrite(STDERR, 'invalid contract: a row has no id'.PHP_EOL);
        exit(1);
    }

    ++$report['total'];

    $ops = $surface['ops']['state'] ?? null;
    $admin = $surface['admin']['state'] ?? null;
    $console = $surface['console']['state'] ?? null;

    if (in_array($ops, $builtStates, true)) {
        $report['filament_built']['ops'][] = $id;
    }

    if (in_array($admin, $builtStates, true)) {
        $report['filament_built']['admin'][] = $id;
    }

    $consoleServed = in_array($console, $servedStates, true);

    if ($consoleServed) {
        $report['console_served'][] = $id;
    }

    $filamentBuilt = in_array($ops, $builtStates, true) || in_array($admin, $builtStates, true);

    if ($consoleServed && ! $filamentBuilt) {
        $report['console_only'][] = $id;
    }

    $states = array_filter([$ops, $admin, $console], static fn (?string $state): bool => null !== $state);

    if ([] !== $states && array_values(array_unique($states)) === ['planned']) {
        ++$report['planned_only']['total'];

        if ('planned' === $ops) {
            $report['planned_only']['ops'][] = $id;
        }

        if ('planned' === $admin) {
            $report['planned_only']['admin'][] = $id;
        }

        if ('planned' === $ops && 'planned' === $admin) {
            $report['planned_only']['both'][] = $id;
        }
    }
}

$report['filament_built']['total'] = count(array_unique(array_merge(
    $report['filament_built']['ops'],
    $report['filament_built']['admin'],
)));

if (in_array('--json', $argv ?? [], true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$list = static fn (array $ids): string => [] === $ids ? '—' : implode(', ', $ids);

echo 'surfaces total      : '.$report['total'].PHP_EOL;
echo 'filament-built      : '.$report['filament_built']['total'].PHP_EOL;
echo '  /ops              : '.count($report['filament_built']['ops']).'  ('.$list($report['filament_built']['ops']).')'.PHP_EOL;
echo '  /admin            : '.count($report['filament_built']['admin']).'  ('.$list($report['filament_built']['admin']).')'.PHP_EOL;
echo 'console-served      : '.count($report['console_served']).PHP_EOL;
echo 'console-only        : '.count($report['console_only']).PHP_EOL;
echo '  '.$list($report['console_only']).PHP_EOL;
echo 'planned only        : '.$report['planned_only']['total'].PHP_EOL;
echo '  /ops              : '.count($report['planned_only']['ops']).'  ('.$list($report['planned_only']['ops']).')'.PHP_EOL;
echo '  /admin            : '.count($report['planned_only']['admin']).'  ('.$list($report['planned_only']['admin']).')'.PHP_EOL;
