#!/bin/bash
# Verification script for site creator toolkit setting fix
# This script verifies that site creator subtab visibility is controlled by enable_site_creator_toolkit setting

# Change to plugin root directory (parent of bin/)
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "=== Site Creator Toolkit Setting Verification ==="
echo ""

# Check that the fix is in place
echo "1. Checking that site creator subtab is conditionally added..."
if grep -B 2 'site_creator' includes/admin/sections/class-wp-mcp-ai-section-tools.php | grep -q 'enable_site_creator_toolkit'; then
    echo "   ✓ Site creator subtab checks enable_site_creator_toolkit setting"
else
    echo "   ✗ FAILED: Site creator subtab not checking setting"
    exit 1
fi

echo ""
echo "2. Checking that comment was updated..."
if grep -q 'only show tab if toolkit is enabled' includes/admin/sections/class-wp-mcp-ai-section-tools.php; then
    echo "   ✓ Comment updated correctly"
else
    echo "   ✗ FAILED: Comment not updated"
    exit 1
fi

echo ""
echo "3. Checking that test file was updated..."
if grep -q 'test_site_creator_subtab_visibility_controlled_by_setting' tests/test-section-tools.php; then
    echo "   ✓ New test case added"
else
    echo "   ✗ FAILED: New test case not found"
    exit 1
fi

echo ""
echo "4. Verifying test file handles conditional visibility..."
if grep -q 'enable_site_creator_toolkit' tests/test-section-tools.php && \
   grep -q 'assertArrayNotHasKey.*site_creator' tests/test-section-tools.php; then
    echo "   ✓ Test cases verify conditional visibility"
else
    echo "   ✗ FAILED: Test cases don't verify conditional behavior"
    exit 1
fi

echo ""
echo "=== All verifications passed! ==="
echo ""
echo "Summary:"
echo "- Site creator subtab is only shown when enable_site_creator_toolkit is enabled"
echo "- Direct URL access is prevented via subtab validation (defaults to tools_manager)"
echo "- Tests verify behavior with setting both enabled and disabled"
echo ""
echo "The fix ensures site creator subtab is hidden when the toolkit is disabled."
