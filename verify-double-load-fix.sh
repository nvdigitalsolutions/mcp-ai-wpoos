#!/bin/bash
##
# Verification script for double-loading protection fix.
#
# This script verifies that the plugin has proper safeguards against
# double-loading, which would cause fatal errors from duplicate class
# and function declarations.
#
# Usage: ./verify-double-load-fix.sh
#
# @package WP_MCP_AI
##

# ANSI color codes for better output
COLOR_GREEN='\033[32m'
COLOR_RED='\033[31m'
COLOR_YELLOW='\033[33m'
COLOR_RESET='\033[0m'

echo "======================================================="
echo " Plugin Double-Loading Protection Verification"
echo "======================================================="
echo ""

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAIN_FILE="${PLUGIN_DIR}/mcp-ai-wpoos.php"
BASE_FILE="${PLUGIN_DIR}/mcp-ai-wpoos-base.php"
TESTS_PASSED=0
TESTS_FAILED=0

# Function to report test result
pass() {
    echo -e "${COLOR_GREEN}✓${COLOR_RESET} $1"
    ((TESTS_PASSED++))
}

fail() {
    echo -e "${COLOR_RED}✗ FAIL:${COLOR_RESET} $1"
    ((TESTS_FAILED++))
}

# Check files exist
if [ ! -f "$MAIN_FILE" ]; then
    fail "Main plugin file not found: $MAIN_FILE"
    exit 1
fi

if [ ! -f "$BASE_FILE" ]; then
    fail "Base version file not found: $BASE_FILE"
    exit 1
fi

pass "Files exist"

# Check for double-load protection in main file
if ! grep -q "function_exists( 'wp_mcp_ai_core_loaded' )" "$MAIN_FILE"; then
    fail "Main plugin file missing double-load protection"
    exit 1
fi

pass "Main plugin file has double-load protection"

# Check for double-load protection in base file
if ! grep -q "function_exists( 'wp_mcp_ai_core_loaded' )" "$BASE_FILE"; then
    fail "Base version file missing double-load protection"
    exit 1
fi

pass "Base version file has double-load protection"

# Verify the marker function is defined in main file
if ! grep -q "function wp_mcp_ai_core_loaded()" "$MAIN_FILE"; then
    fail "Marker function wp_mcp_ai_core_loaded() not found in main file"
    exit 1
fi

pass "Marker function wp_mcp_ai_core_loaded() exists"

# Test syntax of both files
if php -l "$MAIN_FILE" > /dev/null 2>&1; then
    pass "Main plugin file syntax is valid"
else
    fail "Main plugin file has syntax errors"
    php -l "$MAIN_FILE"
    exit 1
fi

if php -l "$BASE_FILE" > /dev/null 2>&1; then
    pass "Base version file syntax is valid"
else
    fail "Base version file has syntax errors"
    php -l "$BASE_FILE"
    exit 1
fi

# Functional test: Simulate double-load scenario
echo ""
echo "Running functional test..."

php << 'PHPTEST'
<?php
define('ABSPATH', '/tmp/wordpress/');

// Define marker function to simulate plugin already loaded
function wp_mcp_ai_core_loaded() {
    return true;
}

// Try to load main file
ob_start();
require __DIR__ . '/mcp-ai-wpoos.php';
$output = ob_get_clean();

if (!empty($output)) {
    echo "FAIL_MAIN\n";
    exit(1);
}

// Try to load base file
ob_start();
require __DIR__ . '/mcp-ai-wpoos-base.php';
$output = ob_get_clean();

if (!empty($output)) {
    echo "FAIL_BASE\n";
    exit(1);
}

echo "PASS\n";
exit(0);
?>
PHPTEST

TEST_RESULT=$?

if [ $TEST_RESULT -eq 0 ]; then
    pass "Main plugin file correctly prevents double-loading"
    pass "Base version file correctly prevents double-loading"
else
    fail "Functional test failed"
    exit 1
fi

echo ""
echo "======================================================="
echo -e "${COLOR_GREEN} ALL CHECKS PASSED! ✓${COLOR_RESET}"
echo "======================================================="
echo ""
echo "The plugin has proper double-loading protection and will"
echo "not cause fatal errors if accidentally loaded multiple times."
echo ""

exit 0
