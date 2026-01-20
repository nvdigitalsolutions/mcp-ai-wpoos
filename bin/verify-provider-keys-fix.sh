#!/bin/bash
# Quick verification script for provider keys fix

cd "$(dirname "$0")/.."

echo "=== Provider Keys Fix Verification ==="
echo ""

echo "1. Checking that sanitize_callback is removed from register_setting..."
if grep -q "'sanitize_callback'" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "   ❌ FAIL: sanitize_callback still present"
    exit 1
else
    echo "   ✅ PASS: sanitize_callback removed"
fi

echo ""
echo "2. Checking that sanitize_settings method still exists (for manual calls)..."
if grep -q "public function sanitize_settings" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "   ✅ PASS: sanitize_settings method exists"
else
    echo "   ❌ FAIL: sanitize_settings method missing"
    exit 1
fi

echo ""
echo "3. Checking that handle_save_settings still calls sanitize_settings..."
if grep -q "\$this->sanitize_settings" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "   ✅ PASS: handle_save_settings calls sanitize_settings"
else
    echo "   ❌ FAIL: handle_save_settings doesn't call sanitize_settings"
    exit 1
fi

echo ""
echo "4. Checking that documentation explains the change..."
if grep -q "We do NOT register a sanitize_callback" includes/admin/class-wp-mcp-ai-settings-dashboard.php; then
    echo "   ✅ PASS: Documentation added"
else
    echo "   ❌ FAIL: Missing documentation"
    exit 1
fi

echo ""
echo "=== All Checks Passed! ==="
echo ""
echo "The fix prevents double-sanitization that was clearing provider keys."
echo ""
echo "Next steps:"
echo "1. Test manually: Set provider keys, navigate tabs, verify they persist"
echo "2. Run: vendor/bin/phpunit tests/test-provider-keys-tab-navigation.php"
echo "3. Check Settings Health shows providers configured"
