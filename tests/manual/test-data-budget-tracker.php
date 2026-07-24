<?php
/**
 * Quick smoke test for DataBudgetTracker domain service v2.
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

use Nvoos\Core\Domain\Service\Budget\DataBudgetTracker;

$ok = 0;
$fail = 0;
function check(string $label, $expected, $actual): void {
    global $ok, $fail;
    if ($expected === $actual) { $ok++; } else {
        $fail++;
        printf("FAIL %s: expected=%s got=%s\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

// Defaults
$t = new DataBudgetTracker();
check('default_req', 1048576, $t->getRequestBudget());
check('default_pm',  65536, $t->getPerMessageBudget());

// Custom
$t = new DataBudgetTracker(500000, 32000);
check('custom_req', 500000, $t->getRequestBudget());
check('custom_pm',  32000, $t->getPerMessageBudget());

// Floor
$t = new DataBudgetTracker(500, 200);
check('floor_req', 1024, $t->getRequestBudget());
check('floor_pm',  512, $t->getPerMessageBudget());

// Accounting (use values above floor)
$t = new DataBudgetTracker(10000, 1000, 'test');
check('init_consumed', 0, $t->consumed());
check('init_remaining', 10000, $t->remaining());
check('init_exhausted', false, $t->isExhausted());

$t->record(3000);
check('after_record_consumed', 3000, $t->consumed());
check('after_record_remaining', 7000, $t->remaining());

check('spill_small', false, $t->shouldSpill(500));
check('spill_over_pm', true, $t->shouldSpill(2000));
check('spill_exhaust', true, $t->shouldSpill(8000));

// Exhaustion
$t2 = new DataBudgetTracker(10000, 5000);
$t2->record(10000);
check('exhausted_at_limit', true, $t2->isExhausted());
check('remaining_at_limit', 0, $t2->remaining());

// Spill counter
$t3 = new DataBudgetTracker(10000, 5000, 'test');
check('init_spills', 0, $t3->spillCount());
$t3->noteSpill();
$t3->noteSpill();
check('after_notes', 2, $t3->spillCount());

// Reset
$t3->reset('fresh');
check('reset_consumed', 0, $t3->consumed());
check('reset_spills', 0, $t3->spillCount());

printf("\n%d passed, %d failed\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
