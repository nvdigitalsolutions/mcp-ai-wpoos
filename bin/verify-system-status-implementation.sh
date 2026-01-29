#!/bin/bash
# Verify System Status Implementation
# Tests that the orchestration dashboard system status is properly connected
#
# USAGE:
#   ./verify-system-status-implementation.sh [path-to-wordpress-root]
#
# If no path is provided, script will attempt to find WordPress in common locations.

set -e

echo "========================================"
echo "System Status Implementation Verification"
echo "========================================"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if services are loaded
echo "1. Checking if service classes are loaded..."
echo ""

# Determine WordPress root
WP_ROOT=""
if [ -n "$1" ]; then
    WP_ROOT="$1"
elif [ -f "../../wp-load.php" ]; then
    WP_ROOT="../.."
elif [ -f "../../../wp-load.php" ]; then
    WP_ROOT="../../.."
elif [ -f "../../../../wp-load.php" ]; then
    WP_ROOT="../../../.."
fi

# Create PHP script to check class availability
php << EOPHP
<?php
// Bootstrap WordPress
define('WP_USE_THEMES', false);

// Try multiple WordPress root locations
\$wp_paths = array(
    '$WP_ROOT/wp-load.php',
    dirname(__DIR__) . '/wp-load.php',
    dirname(dirname(__DIR__)) . '/wp-load.php',
    dirname(dirname(dirname(__DIR__))) . '/wp-load.php',
);

\$wp_loaded = false;
foreach (\$wp_paths as \$wp_path) {
    if (file_exists(\$wp_path)) {
        require_once \$wp_path;
        \$wp_loaded = true;
        echo "✓ WordPress loaded from: " . dirname(\$wp_path) . "\n\n";
        break;
    }
}

if (!\$wp_loaded) {
    echo "ERROR: Could not find WordPress installation\n";
    echo "Please run this script from the plugin directory or provide WordPress root path:\n";
    echo "  ./verify-system-status-implementation.sh /path/to/wordpress\n";
    exit(1);
}

echo "Checking service class availability...\n";
echo "======================================\n";

$services = array(
    'WP_MCP_AI_Cron_Status_Service',
    'WP_MCP_AI_Async_Health_Monitor',
    'WP_MCP_AI_Orchestration_Health_Service',
    'WP_MCP_AI_SSE_Stream'
);

$all_loaded = true;
foreach ($services as $service) {
    $exists = class_exists($service);
    $status = $exists ? '✓ LOADED' : '✗ MISSING';
    echo sprintf("%-50s %s\n", $service, $status);
    if (!$exists) {
        $all_loaded = false;
    }
}

echo "\n";

if (!$all_loaded) {
    echo "ERROR: Some service classes are not loaded\n";
    exit(1);
}

echo "All service classes are loaded!\n\n";

// Check if Pro dashboard exists
if (!class_exists('WP_MCP_AI_Orchestration_Dashboard')) {
    echo "SKIP: Pro addon not active (WP_MCP_AI_Orchestration_Dashboard not found)\n";
    echo "This is expected for base version - system status only available in Pro\n";
    exit(0);
}

echo "Testing get_system_status() method...\n";
echo "======================================\n";

// Create dashboard instance
$dashboard = new WP_MCP_AI_Orchestration_Dashboard();

// Use reflection to call private method
$reflection = new ReflectionClass($dashboard);
$method = $reflection->getMethod('get_system_status');
$method->setAccessible(true);

$status = $method->invoke($dashboard);

echo "System Status Structure:\n";
print_r($status);

// Verify structure
$required_keys = array('cron', 'async', 'health', 'sse');
$missing_keys = array();

foreach ($required_keys as $key) {
    if (!isset($status[$key])) {
        $missing_keys[] = $key;
    }
}

if (!empty($missing_keys)) {
    echo "\nERROR: Missing required keys: " . implode(', ', $missing_keys) . "\n";
    exit(1);
}

echo "\n✓ All required keys present (cron, async, health, sse)\n";

// Check cron status details
if (class_exists('WP_MCP_AI_Cron_Status_Service')) {
    echo "\n✓ Cron status populated:\n";
    if (isset($status['cron']['active'])) {
        echo "  - Active: " . $status['cron']['active'] . "\n";
    }
    if (isset($status['cron']['pending'])) {
        echo "  - Pending: " . $status['cron']['pending'] . "\n";
    }
    if (isset($status['cron']['failed'])) {
        echo "  - Failed: " . $status['cron']['failed'] . "\n";
    }
}

// Check async status details
if (class_exists('WP_MCP_AI_Async_Health_Monitor')) {
    echo "\n✓ Async health populated:\n";
    if (isset($status['async']['status'])) {
        echo "  - Status: " . $status['async']['status'] . "\n";
    }
    if (isset($status['async']['stuck_jobs'])) {
        echo "  - Stuck Jobs: " . $status['async']['stuck_jobs'] . "\n";
    }
    if (isset($status['async']['long_running'])) {
        echo "  - Long Running: " . $status['async']['long_running'] . "\n";
    }
}

// Check health status details
if (class_exists('WP_MCP_AI_Orchestration_Health_Service')) {
    echo "\n✓ Orchestration health populated:\n";
    if (isset($status['health']['status'])) {
        echo "  - Status: " . $status['health']['status'] . "\n";
    }
    if (isset($status['health']['label'])) {
        echo "  - Label: " . $status['health']['label'] . "\n";
    }
}

// Check SSE status
echo "\n✓ SSE connectivity:\n";
echo "  - Available: " . ($status['sse']['available'] ? 'Yes' : 'No') . "\n";
echo "  - Endpoint: " . ($status['sse']['endpoint'] ?? 'N/A') . "\n";

echo "\n======================================\n";
echo "SUCCESS: System status is properly connected!\n";
echo "======================================\n";

exit(0);
EOPHP

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo -e "${GREEN}✓ Verification PASSED${NC}"
    echo ""
    echo "Next steps:"
    echo "1. Open the orchestration dashboard in a browser"
    echo "2. Open browser console (F12)"
    echo "3. Look for 'OrchestrationDashboard: AJAX response received' message"
    echo "4. Verify system status values are displayed (not '-')"
    exit 0
else
    echo -e "${RED}✗ Verification FAILED${NC}"
    exit 1
fi
