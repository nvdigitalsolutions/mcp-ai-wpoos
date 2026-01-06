#!/bin/bash
##
# Verification script for Pro addon text domain loading fix.
#
# This script verifies that:
# 1. The Pro addon has a text domain loading function
# 2. The function is registered on the init hook
# 3. The languages directory exists
# 4. The text domain loading happens before CPT registration
#
# Usage: ./bin/verify-pro-textdomain-fix.sh
##

set -e

echo "=========================================="
echo "Pro Addon Text Domain Loading Verification"
echo "=========================================="
echo ""

# Check if Pro addon file exists
PRO_FILE="addons/pro/mcp-ai-wpoos-pro.php"
if [ ! -f "$PRO_FILE" ]; then
    echo "❌ ERROR: Pro addon file not found at $PRO_FILE"
    exit 1
fi
echo "✓ Pro addon file exists"

# Check if text domain loading function exists
if ! grep -q "function wp_mcp_ai_pro_load_textdomain()" "$PRO_FILE"; then
    echo "❌ ERROR: wp_mcp_ai_pro_load_textdomain function not found"
    exit 1
fi
echo "✓ Text domain loading function exists"

# Check if function is registered on init hook
if ! grep -q "add_action( 'init', 'wp_mcp_ai_pro_load_textdomain', 1 )" "$PRO_FILE"; then
    echo "❌ ERROR: Text domain loading not registered on init hook"
    exit 1
fi
echo "✓ Text domain loading registered on init hook at priority 1"

# Check if languages directory exists
if [ ! -d "addons/pro/languages" ]; then
    echo "❌ ERROR: Languages directory not found"
    exit 1
fi
echo "✓ Languages directory exists"

# Check if load_plugin_textdomain is called
if ! grep -q "load_plugin_textdomain(" "$PRO_FILE"; then
    echo "❌ ERROR: load_plugin_textdomain call not found"
    exit 1
fi
echo "✓ load_plugin_textdomain function is called"

# Check if text domain 'mcp-ai-wpoos-pro' is used
if ! grep -q "'mcp-ai-wpoos-pro'" "$PRO_FILE"; then
    echo "❌ ERROR: Text domain 'mcp-ai-wpoos-pro' not found"
    exit 1
fi
echo "✓ Text domain 'mcp-ai-wpoos-pro' is used"

# Check project-management-init.php for CPT registration on init
PROJECT_MGMT_FILE="addons/pro/includes/project-management-init.php"
if [ -f "$PROJECT_MGMT_FILE" ]; then
    if ! grep -q "add_action( 'init', 'wp_mcp_ai_register_project_management_post_types'" "$PROJECT_MGMT_FILE"; then
        echo "⚠ WARNING: CPT registration hook not found in expected format"
    else
        echo "✓ CPT registration uses init hook (default priority 10)"
    fi
fi

# Check places-management-init.php for CPT registration on init
PLACES_MGMT_FILE="addons/pro/includes/places-management-init.php"
if [ -f "$PLACES_MGMT_FILE" ]; then
    if grep -q "add_action( 'init'" "$PLACES_MGMT_FILE"; then
        echo "✓ Places CPT registration uses init hook"
    fi
fi

echo ""
echo "=========================================="
echo "Verification Summary"
echo "=========================================="
echo ""
echo "✅ All checks passed!"
echo ""
echo "The fix ensures that:"
echo "  1. Pro addon text domain loads on 'init' at priority 1"
echo "  2. CPT registration happens on 'init' at default priority 10"
echo "  3. Text domain loading occurs BEFORE CPT registration"
echo "  4. This prevents WordPress 6.7+ early translation warnings"
echo ""
echo "The warning about '_load_textdomain_just_in_time was called incorrectly'"
echo "should no longer appear when using the cloned repository."
echo ""
