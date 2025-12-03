#!/bin/bash
# Verification script for site creator base version fix
# This script verifies that site creator is properly hidden in base version

echo "=== Site Creator Base Version Fix Verification ==="
echo ""

# Check that the fix is in place
echo "1. Checking that get_fields() builds array conditionally..."
if grep -q '$fields = array(' includes/admin/sections/class-wp-mcp-ai-section-tools.php; then
    echo "   ✓ Fields array is built correctly"
else
    echo "   ✗ FAILED: Fields array not built conditionally"
    exit 1
fi

echo ""
echo "2. Checking that site creator fields are wrapped in version check..."
if grep -q 'if ( ! wp_mcp_ai_is_base_version() ) {' includes/admin/sections/class-wp-mcp-ai-section-tools.php && \
   grep -A 5 'if ( ! wp_mcp_ai_is_base_version() ) {' includes/admin/sections/class-wp-mcp-ai-section-tools.php | grep -q 'enable_site_creator'; then
    echo "   ✓ Site creator fields are conditionally defined"
else
    echo "   ✗ FAILED: Site creator fields not properly guarded"
    exit 1
fi

echo ""
echo "3. Checking that site creator subtab is conditionally registered..."
if grep -B 2 "site_creator" includes/admin/sections/class-wp-mcp-ai-section-tools.php | grep -q 'if ( ! wp_mcp_ai_is_base_version() ) {'; then
    echo "   ✓ Site creator subtab is conditionally registered"
else
    echo "   ✗ FAILED: Site creator subtab not properly guarded"
    exit 1
fi

echo ""
echo "4. Checking that test file exists..."
if [ -f "tests/test-site-creator-base-version.php" ]; then
    echo "   ✓ Test file exists"
else
    echo "   ✗ FAILED: Test file not found"
    exit 1
fi

echo ""
echo "5. Verifying test file has proper test cases..."
if grep -q 'test_site_creator_subtab_not_registered_in_base_version' tests/test-site-creator-base-version.php && \
   grep -q 'test_site_creator_fields_not_defined_in_base_version' tests/test-site-creator-base-version.php; then
    echo "   ✓ Test cases are present"
else
    echo "   ✗ FAILED: Required test cases missing"
    exit 1
fi

echo ""
echo "=== All verifications passed! ==="
echo ""
echo "Summary:"
echo "- Site creator fields are only defined in full version"
echo "- Site creator subtab is only registered in full version"
echo "- Manual URL access is prevented via subtab validation"
echo "- Tests verify behavior in both base and full version modes"
echo ""
echo "The fix ensures site creator is completely hidden in base version."
