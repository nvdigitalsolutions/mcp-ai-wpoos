#!/bin/bash
#
# Manual verification script for performance buttons fix
#
# This script helps verify that the fix is working correctly by checking:
# 1. JavaScript file exists
# 2. AJAX handlers are defined in the Performance section class
# 3. Pro addon instantiates the Performance section
# 4. Dashboard enqueues the JavaScript on the correct page
#

echo "=== Performance Buttons Fix Verification ==="
echo ""

# Check 1: JavaScript file exists
echo "✓ Checking if performance-admin.js exists..."
if [ -f "assets/js/performance-admin.js" ]; then
    echo "  ✓ File exists: assets/js/performance-admin.js"
    file_size=$(wc -c < assets/js/performance-admin.js)
    echo "  ✓ File size: $file_size bytes"
else
    echo "  ✗ File NOT found: assets/js/performance-admin.js"
    exit 1
fi
echo ""

# Check 2: AJAX handlers in Performance section
echo "✓ Checking AJAX handlers in Performance section..."
if grep -q "wp_ajax_wp_mcp_ai_run_performance_test" addons/pro/includes/admin/sections/class-wp-mcp-ai-section-performance.php; then
    echo "  ✓ Found: wp_ajax_wp_mcp_ai_run_performance_test"
else
    echo "  ✗ NOT found: wp_ajax_wp_mcp_ai_run_performance_test"
fi

if grep -q "wp_ajax_wp_mcp_ai_get_performance_metrics" addons/pro/includes/admin/sections/class-wp-mcp-ai-section-performance.php; then
    echo "  ✓ Found: wp_ajax_wp_mcp_ai_get_performance_metrics"
else
    echo "  ✗ NOT found: wp_ajax_wp_mcp_ai_get_performance_metrics"
fi

if grep -q "wp_ajax_wp_mcp_ai_export_test_results" addons/pro/includes/admin/sections/class-wp-mcp-ai-section-performance.php; then
    echo "  ✓ Found: wp_ajax_wp_mcp_ai_export_test_results"
else
    echo "  ✗ NOT found: wp_ajax_wp_mcp_ai_export_test_results"
fi
echo ""

# Check 3: Pro addon instantiates Performance section
echo "✓ Checking Pro addon instantiation..."
if grep -q "new WP_MCP_AI_Section_Performance()" addons/pro/mcp-ai-wpoos-pro.php; then
    echo "  ✓ Performance section is instantiated in Pro addon"
else
    echo "  ✗ Performance section NOT instantiated in Pro addon"
    exit 1
fi
echo ""

# Check 4: Dashboard enqueues JavaScript
echo "✓ Checking dashboard JavaScript enqueue..."
if grep -q "wp-mcp-ai-performance-admin" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "  ✓ Dashboard enqueues wp-mcp-ai-performance-admin script"
else
    echo "  ✗ Dashboard does NOT enqueue wp-mcp-ai-performance-admin script"
    exit 1
fi

if grep -q "performance_monitoring" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "  ✓ Dashboard checks for performance_monitoring subtab"
else
    echo "  ✗ Dashboard does NOT check for performance_monitoring subtab"
    exit 1
fi

if grep -q "wpMcpAiPerformance" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "  ✓ Dashboard localizes wpMcpAiPerformance object"
else
    echo "  ✗ Dashboard does NOT localize wpMcpAiPerformance object"
    exit 1
fi
echo ""

# Check 5: JavaScript looks for wpMcpAiPerformance
echo "✓ Checking JavaScript dependencies..."
if grep -q "wpMcpAiPerformance" assets/js/performance-admin.js; then
    echo "  ✓ JavaScript references wpMcpAiPerformance object"
else
    echo "  ✗ JavaScript does NOT reference wpMcpAiPerformance object"
    exit 1
fi

if grep -q "ajaxUrl" assets/js/performance-admin.js; then
    echo "  ✓ JavaScript uses ajaxUrl property"
else
    echo "  ✗ JavaScript does NOT use ajaxUrl property"
    exit 1
fi

if grep -q "nonce" assets/js/performance-admin.js; then
    echo "  ✓ JavaScript uses nonce property"
else
    echo "  ✗ JavaScript does NOT use nonce property"
    exit 1
fi
echo ""

echo "=== All Checks Passed! ==="
echo ""
echo "The fix should work correctly. To manually test:"
echo "1. Navigate to: /wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=performance_monitoring"
echo "2. Open browser console (F12)"
echo "3. Click any of the performance test buttons (Run Stress Test, Run Security Test, etc.)"
echo "4. Verify:"
echo "   - Button shows 'Running...' text while executing"
echo "   - AJAX request is sent to admin-ajax.php"
echo "   - Test results are displayed below the buttons"
echo "   - No JavaScript errors in console"
echo ""
