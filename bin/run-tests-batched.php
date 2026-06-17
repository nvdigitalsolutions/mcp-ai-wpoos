<?php
/**
 * Batch test runner - runs PHPUnit on groups of test files and collects results.
 * 
 * Usage: php bin/run-tests-batched.php [--batch-size=N] [--start=N] [--max=N]
 */

// Simple argv parsing (compatible with all PHP versions)
$batch_size = 20;
$start_from = 0;
$max_files = 0;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--batch-size' && isset($argv[$i+1])) { $batch_size = (int)$argv[++$i]; }
    elseif (str_starts_with($argv[$i], '--batch-size=')) { $batch_size = (int)explode('=', $argv[$i])[1]; }
    elseif ($argv[$i] === '--start' && isset($argv[$i+1])) { $start_from = (int)$argv[++$i]; }
    elseif (str_starts_with($argv[$i], '--start=')) { $start_from = (int)explode('=', $argv[$i])[1]; }
    elseif ($argv[$i] === '--max' && isset($argv[$i+1])) { $max_files = (int)$argv[++$i]; }
    elseif (str_starts_with($argv[$i], '--max=')) { $max_files = (int)explode('=', $argv[$i])[1]; }
}

$plugin_root = dirname(__DIR__);
$phpunit_bin = $plugin_root . '/vendor/bin/phpunit';
$config_file = $plugin_root . '/phpunit.xml.dist';

// Collect test files from the test suite directories
$test_dirs = [
    $plugin_root . '/tests',
    $plugin_root . '/addons/pro/tests',
    $plugin_root . '/addons/canvas-toolkit/tests',
    $plugin_root . '/addons/media-studio/tests',
    $plugin_root . '/addons/saas-controller/tests',
];

$test_files = [];
foreach ($test_dirs as $dir) {
    if (!is_dir($dir)) continue;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_starts_with($file->getFilename(), 'test-') && $file->getExtension() === 'php') {
            // Exclude manual tests, helpers, fixtures, bootstrap
            $relative = str_replace('\\', '/', $file->getPathname());
            if (str_contains($relative, '/manual/') || str_contains($relative, '/helpers/') 
                || str_contains($relative, '/fixtures/') || str_ends_with($relative, 'bootstrap.php')) {
                continue;
            }
            $test_files[] = $relative;
        }
    }
}

sort($test_files);
fwrite(STDERR, "Found " . count($test_files) . " test files.\n");

if ($max_files > 0 || $start_from > 0) {
    $test_files = array_slice($test_files, $start_from, $max_files > 0 ? $max_files : null);
}

$total = count($test_files);
fwrite(STDERR, "Running $total files in batches of $batch_size (starting from offset $start_from)...\n");

$batches = array_chunk($test_files, $batch_size);
$batch_num = $start_from > 0 ? (int)($start_from / $batch_size) + 1 : 1;

$all_results = [];
$failures = [];
$errors = [];
$warnings = [];
$skipped = [];
$passed = 0;

foreach ($batches as $batch_idx => $batch) {
    $current_batch = $start_from > 0 ? $batch_num + $batch_idx : $batch_idx + 1;
    fwrite(STDERR, "\n--- Batch $current_batch/" . count($batches) . " (" . count($batch) . " files) ---\n");
    
    // Set required environment variables for test bootstrap
    putenv('WP_CORE_DIR=C:/Users/rasta/AppData/Local/Temp/wordpress');
    putenv('WP_DB_HOST=127.0.0.1');
    putenv('WP_DB_NAME=wordpress_test');
    putenv('WP_DB_USER=wordpress');
    putenv('WP_DB_PASSWORD=wordpress');
    
    $cmd = sprintf(
        'php %s --configuration %s --no-coverage %s 2>&1',
        escapeshellarg($phpunit_bin),
        escapeshellarg($config_file),
        implode(' ', array_map('escapeshellarg', $batch))
    );
    
    $output = [];
    $exit_code = 0;
    exec($cmd, $output, $exit_code);
    
    $output_str = implode("\n", $output);
    
    // Extract summary line and failures
    $summary = '';
    $batch_failures = [];
    $batch_errors_count = 0;
    $batch_failures_count = 0;
    
    foreach ($output as $line) {
        if (str_contains($line, 'Tests:') && str_contains($line, 'Assertions:')) {
            $summary = $line;
            if (preg_match('/Failures:\s*(\d+)/', $line, $m)) $batch_failures_count = (int)$m[1];
            if (preg_match('/Errors:\s*(\d+)/', $line, $m)) $batch_errors_count = (int)$m[1];
        }
    }
    
    // Extract failure details
    $in_failure = false;
    $current_failure = '';
    foreach ($output as $line) {
        if (preg_match('/^\d+\)\s/', $line)) {
            if ($current_failure) $batch_failures[] = $current_failure;
            $current_failure = $line;
            $in_failure = true;
        } elseif ($in_failure) {
            if (trim($line) === '') {
                if ($current_failure) $batch_failures[] = $current_failure;
                $current_failure = '';
                $in_failure = false;
            } else {
                $current_failure .= "\n  " . $line;
            }
        }
    }
    if ($current_failure) $batch_failures[] = $current_failure;
    
    $status = 'PASS';
    if ($batch_errors_count > 0) $status = 'ERRORS';
    if ($batch_failures_count > 0) $status = 'FAILURES';
    
    fwrite(STDERR, "  Result: $status | $summary\n");
    
    foreach ($batch_failures as $f) {
        fwrite(STDERR, "  FAILURE: " . str_replace("\n", "\n    ", $f) . "\n");
    }
    
    $all_results[] = [
        'batch' => $current_batch,
        'files' => count($batch),
        'exit_code' => $exit_code,
        'status' => $status,
        'summary' => $summary,
        'failures' => $batch_failures,
    ];
    
    $failures = array_merge($failures, $batch_failures);
}

// Print final summary
echo "\n";
echo "========================================\n";
echo "  BATCH TEST RUNNER - FINAL SUMMARY\n";
echo "========================================\n";
echo "Total test files: $total\n";
echo "Batches: " . count($batches) . "\n";
echo "\n";

$total_errors = 0;
$total_failures = 0;
$total_passes = 0;

foreach ($all_results as $r) {
    echo sprintf("Batch %3d: %s (%d files)\n", $r['batch'], $r['status'], $r['files']);
    if ($r['summary']) echo "  " . $r['summary'] . "\n";
}

echo "\n";
echo "========================================\n";
echo "  ALL FAILURES (" . count($failures) . " total)\n";
echo "========================================\n";
foreach ($failures as $i => $f) {
    echo ($i + 1) . ") " . $f . "\n\n";
}
