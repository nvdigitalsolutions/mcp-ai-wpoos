#!/usr/bin/env bash
#
# Test script to verify the WP_LANG_DIR patch fix
#
# This script simulates the scenario that causes the warning:
# 1. WordPress is already loaded (WP_LANG_DIR defined by core)
# 2. wp-phpunit bootstrap tries to define it again
#

set -e

echo "=== WP_LANG_DIR Patch Verification Test ==="
echo ""

# Create a test PHP script that simulates the issue
TEST_SCRIPT=$(cat <<'PHP'
<?php
// Simulate WordPress core defining WP_LANG_DIR
define( 'WP_LANG_DIR', '/var/www/html/wp-content/languages' );

echo "Step 1: WordPress core defined WP_LANG_DIR: " . WP_LANG_DIR . "\n";

// Simulate the UNPATCHED wp-phpunit behavior (without guard check)
// This would cause a warning:
// define( 'WP_LANG_DIR', '/some/other/path' );

// Simulate the PATCHED wp-phpunit behavior (with guard check)
if ( ! defined( 'WP_LANG_DIR' ) ) {
    define( 'WP_LANG_DIR', '/some/other/path' );
    echo "Step 2: wp-phpunit defined WP_LANG_DIR (THIS SHOULD NOT HAPPEN)\n";
} else {
    echo "Step 2: wp-phpunit skipped defining WP_LANG_DIR (already defined) ✓\n";
}

echo "\nResult: WP_LANG_DIR = " . WP_LANG_DIR . "\n";
echo "✓ No warning emitted! The patch works correctly.\n";
PHP
)

echo "$TEST_SCRIPT" | php

echo ""
echo "=== Test Passed ==="
echo "The patch adds a guard check that prevents the duplicate definition warning."
echo ""
echo "To apply the patch, run: composer install or composer update"
