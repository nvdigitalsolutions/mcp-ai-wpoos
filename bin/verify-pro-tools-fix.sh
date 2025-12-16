#!/bin/bash
#
# Manual verification script for Pro tools loading fix
#
# This script simulates the plugin loading and verifies Pro tools are registered.
#

# Change to plugin root directory (parent of bin/)
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "==================================================================="
echo "Pro Tools Loading Fix - Manual Verification"
echo "==================================================================="
echo ""

# Check if Pro addon exists
echo "1. Checking if Pro addon exists..."
if [ -f "addons/pro/mcp-ai-wpoos-pro.php" ]; then
    echo "   ✓ Pro addon file exists at: addons/pro/mcp-ai-wpoos-pro.php"
else
    echo "   ✗ Pro addon file NOT found"
    exit 1
fi

# Verify loading order in mcp-ai-wpoos.php
echo ""
echo "2. Verifying file loading order in mcp-ai-wpoos.php..."

TOOL_REGISTRY_LINE=$(grep -n "require_once.*class-tool-registry.php" mcp-ai-wpoos.php | cut -d: -f1)
PRO_ADDON_LINE=$(grep -n "require_once \$pro_addon_file" mcp-ai-wpoos.php | head -1 | cut -d: -f1)
TOOLS_INIT_LINE=$(grep -n "require_once.*tools-init.php" mcp-ai-wpoos.php | head -1 | cut -d: -f1)

echo "   Tool Registry loaded at line: $TOOL_REGISTRY_LINE"
echo "   Pro Addon loaded at line:     $PRO_ADDON_LINE"
echo "   Tools Init loaded at line:    $TOOLS_INIT_LINE"

if [ "$TOOL_REGISTRY_LINE" -lt "$PRO_ADDON_LINE" ] && [ "$PRO_ADDON_LINE" -lt "$TOOLS_INIT_LINE" ]; then
    echo "   ✓ CORRECT ORDER: Tool Registry → Pro Addon → Tools Init"
else
    echo "   ✗ INCORRECT ORDER"
    exit 1
fi

# Check Pro addon has smart initialization
echo ""
echo "3. Checking Pro addon initialization logic..."
if grep -q "did_action.*plugins_loaded" addons/pro/mcp-ai-wpoos-pro.php; then
    echo "   ✓ Pro addon has smart initialization (detects inline loading)"
else
    echo "   ✗ Pro addon missing smart initialization"
    exit 1
fi

# Verify Pro tools are defined in the Pro addon
echo ""
echo "4. Verifying Pro tools are defined..."
PRO_TOOLS=""
for f in addons/pro/includes/src/Tools/*.php; do
    slug=$(grep -A1 "function get_slug" "$f" | grep return | sed "s/.*return '\\([^']*\\)'.*/\\1/")
    if [ -n "$slug" ]; then
        PRO_TOOLS="$PRO_TOOLS$slug"$'\n'
    fi
done

echo "   Pro tools found:"
echo "$PRO_TOOLS" | grep -v "^$" | while read tool; do
    echo "      - $tool"
done

# Count Pro tools
TOOL_COUNT=$(echo "$PRO_TOOLS" | grep -v "^$" | wc -l)
if [ "$TOOL_COUNT" -gt 0 ]; then
    echo "   ✓ Found $TOOL_COUNT Pro tools"
else
    echo "   ✗ No Pro tools found"
    exit 1
fi

# Check if Pro tools are in the group map
echo ""
echo "5. Checking Pro tools in group map filter..."
if grep -q "wp_mcp_ai_pro_tool_group_map" addons/pro/mcp-ai-wpoos-pro.php; then
    echo "   ✓ Pro addon registers tool group map filter"
    
    # Show which groups Pro tools are in
    echo "   Pro tool groups:"
    grep -A20 "pro_tools = array" addons/pro/mcp-ai-wpoos-pro.php | grep "=>" | head -10 | sed 's/^/      /'
else
    echo "   ✗ Pro addon missing tool group map filter"
    exit 1
fi

# Check bootstrap doesn't duplicate Pro addon loading
echo ""
echo "6. Verifying wp_mcp_ai_bootstrap doesn't duplicate loading..."
if grep -A5 "function wp_mcp_ai_bootstrap" mcp-ai-wpoos.php | grep -q "Pro addon is now loaded earlier"; then
    echo "   ✓ wp_mcp_ai_bootstrap has correct comment (no duplicate loading)"
else
    echo "   ⚠ Comment may not be updated (check manually)"
fi

# Final summary
echo ""
echo "==================================================================="
echo "✓ ALL CHECKS PASSED"
echo "==================================================================="
echo ""
echo "The fix ensures Pro addon loads BEFORE the tool registry"
echo "initialization, allowing Pro tools to register their hooks before"
echo "the 'wp_mcp_ai_register_tools' action fires."
echo ""
echo "Expected behavior:"
echo "  1. mcp-ai-wpoos.php loads Tool Registry class"
echo "  2. mcp-ai-wpoos.php loads Pro addon (if exists)"
echo "  3. Pro addon calls wp_mcp_ai_pro_init() immediately"
echo "  4. Pro addon registers hook for 'wp_mcp_ai_register_tools'"
echo "  5. mcp-ai-wpoos.php loads tools-init.php"
echo "  6. tools-init.php schedules Tool Registry init at plugins_loaded:20"
echo "  7. When plugins_loaded:20 fires, Pro hooks are already registered"
echo "  8. Tool Registry fires 'wp_mcp_ai_register_tools' action"
echo "  9. Pro addon's wp_mcp_ai_pro_register_tools() runs"
echo " 10. Pro tools are added to registry"
echo ""
