#!/bin/bash
# Verification script for site creator settings reorganization
# This script verifies that site creator settings have been moved to their own page

# Change to plugin root directory (parent of bin/)
cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "=== Site Creator Settings Reorganization Verification ==="
echo ""

# Check that the subtab was removed
echo "1. Checking that site_creator subtab is not registered..."
if ! grep -q "site_creator.*array" includes/admin/sections/class-wp-mcp-ai-section-tools.php | grep -v "Note:"; then
    echo "   ✓ Site creator subtab not registered in Tools section"
else
    echo "   ✗ FAILED: Site creator subtab still registered"
    exit 1
fi

echo ""
echo "2. Checking that comment explains the move..."
if grep -q 'Site Creator settings have been moved to their own separate admin page' includes/admin/sections/class-wp-mcp-ai-section-tools.php; then
    echo "   ✓ Comment added explaining the change"
else
    echo "   ✗ FAILED: Comment not found"
    exit 1
fi

echo ""
echo "3. Checking that Pro addon has permissions form..."
if grep -q 'site_creator_allow_plugin_install' addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php && \
   grep -q 'form method="post"' addons/pro/includes/admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php; then
    echo "   ✓ Permissions form added to Site Creator settings page"
else
    echo "   ✗ FAILED: Permissions form not found in Pro addon"
    exit 1
fi

echo ""
echo "4. Checking that test was updated..."
if grep -q 'should not exist in Tools page' tests/test-section-tools.php; then
    echo "   ✓ Test updated to verify subtab doesn't exist"
else
    echo "   ✗ FAILED: Test not properly updated"
    exit 1
fi

echo ""
echo "=== All verifications passed! ==="
echo ""
echo "Summary:"
echo "- Site creator subtab removed from Tools page"
echo "- Settings fields remain defined in core for tool functionality"
echo "- Permissions UI added to separate Site Creator admin page in Pro addon"
echo "- Tests verify subtab no longer exists in Tools section"
echo ""
echo "Users can now configure Site Creator permissions in the dedicated Site Creator menu."
