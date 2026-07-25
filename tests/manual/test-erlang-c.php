<?php
/**
 * Quick smoke test for ErlangC domain service.
 */

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Nvoos\\Core\\'      => __DIR__ . '/../../lib/core/src/',
        'Nvoos\\WordPress\\' => __DIR__ . '/../../lib/wordpress-adapter/src/',
    ];
    foreach ($prefixes as $prefix => $base) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $file = $base . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }
});

use Nvoos\Core\Domain\Service\Optimization\ErlangC;

$ok = 0;
$fail = 0;
function check(string $label, $expected, $actual, float $epsilon = 0.0001): void {
    global $ok, $fail;
    $match = is_float($expected)
        ? abs($expected - $actual) < $epsilon
        : $expected === $actual;
    if ($match) { $ok++; } else {
        $fail++;
        printf("FAIL %s: expected=%s got=%s\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

$ec = new ErlangC();

// toErlangs: 100 calls/hr * 180s AHT = 5 Erlangs
check('to_erlangs', 5.0, $ec->toErlangs(100.0, 180.0));

// utilisation: 5 Erlangs / 10 agents = 0.5
check('utilisation', 0.5, $ec->utilisation(5.0, 10));

// probability_wait: 5 Erlangs, 10 agents => should be < 1.0 and > 0.0
$pw = $ec->probabilityWait(5.0, 10);
check('pw_range', true, $pw > 0.0 && $pw < 1.0);

// Unstable: N <= A => wait probability = 1.0
check('pw_unstable', 1.0, $ec->probabilityWait(10.0, 5));

// probability_wait: 0 traffic => 0
check('pw_zero', 0.0, $ec->probabilityWait(0.0, 5));

// service_level: should be between 0 and 1
$sl = $ec->serviceLevel(5.0, 10, 180.0, 20.0);
check('sl_range', true, $sl >= 0.0 && $sl <= 1.0);

// service_level: unstable => 0
check('sl_unstable', 0.0, $ec->serviceLevel(10.0, 5, 180.0, 20.0));

// service_level: zero params => 0
check('sl_zero_traffic', 0.0, $ec->serviceLevel(0.0, 10, 180.0, 20.0));

// average_wait_time: should be finite for stable system
$awt = $ec->averageWaitTime(5.0, 10, 180.0);
check('awt_finite', true, $awt >= 0.0 && $awt < PHP_FLOAT_MAX);

// average_wait_time: unstable => PHP_FLOAT_MAX
check('awt_unstable', PHP_FLOAT_MAX, $ec->averageWaitTime(10.0, 5, 180.0));

// min_agents_for_service_level: should return reasonable value
$min = $ec->minAgentsForServiceLevel(5.0, 180.0, 0.8, 20.0);
check('min_agents_reasonable', true, $min > 5 && $min <= 505);

// Verify result: the returned agent count should actually meet the SL
$sl2 = $ec->serviceLevel(5.0, $min, 180.0, 20.0);
check('min_agents_meets_sl', true, $sl2 >= 0.8);

printf("\n%d passed, %d failed\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
