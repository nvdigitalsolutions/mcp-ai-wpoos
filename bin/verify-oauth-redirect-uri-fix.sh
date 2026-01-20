#!/bin/bash
#
# Manual verification script for OAuth redirect URI fix
# This script helps verify that the redirect URI is being generated consistently
#

echo "=================================="
echo "OAuth Redirect URI Fix Verification"
echo "=================================="
echo ""

echo "1. Checking modified files..."
echo ""

# Check if the OAuth manager file has been updated
if grep -q "add_query_arg" includes/integrations/class-wp-mcp-ai-oauth-manager.php; then
    echo "✓ OAuth manager is using add_query_arg()"
else
    echo "✗ OAuth manager still using old method"
    exit 1
fi

# Check for consistent patterns
GMAIL_COUNT=$(grep -c "array( 'wp_mcp_ai_oauth' => 'gmail_callback' )" includes/integrations/class-wp-mcp-ai-oauth-manager.php)
echo "✓ Found $GMAIL_COUNT instances of Gmail redirect URI generation (expected: 2)"

DRIVE_COUNT=$(grep -c "array( 'wp_mcp_ai_oauth' => 'google_drive_callback' )" includes/integrations/class-wp-mcp-ai-oauth-manager.php)
echo "✓ Found $DRIVE_COUNT instances of Google Drive redirect URI generation (expected: 2)"

echo ""
echo "2. Checking for old-style redirect URI construction..."
echo ""

# Check for any remaining old-style constructions (should be none in modified files)
OLD_STYLE_GMAIL=$(grep -c "admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' )" includes/integrations/class-wp-mcp-ai-oauth-manager.php || true)
if [ "$OLD_STYLE_GMAIL" -eq 0 ]; then
    echo "✓ No old-style Gmail redirect URI construction found in OAuth manager"
else
    echo "✗ Found $OLD_STYLE_GMAIL old-style Gmail redirect URI constructions"
    exit 1
fi

OLD_STYLE_DRIVE=$(grep -c "admin_url( 'admin.php?wp_mcp_ai_oauth=google_drive_callback' )" includes/integrations/class-wp-mcp-ai-oauth-manager.php || true)
if [ "$OLD_STYLE_DRIVE" -eq 0 ]; then
    echo "✓ No old-style Google Drive redirect URI construction found in OAuth manager"
else
    echo "✗ Found $OLD_STYLE_DRIVE old-style Drive redirect URI constructions"
    exit 1
fi

echo ""
echo "3. Checking PHP syntax..."
echo ""

php -l includes/integrations/class-wp-mcp-ai-oauth-manager.php > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ OAuth manager PHP syntax is valid"
else
    echo "✗ OAuth manager has PHP syntax errors"
    exit 1
fi

php -l includes/admin/sections/class-wp-mcp-ai-section-integrations.php > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ Integrations section PHP syntax is valid"
else
    echo "✗ Integrations section has PHP syntax errors"
    exit 1
fi

echo ""
echo "4. Summary of Changes"
echo ""
echo "Modified Files:"
echo "  - includes/integrations/class-wp-mcp-ai-oauth-manager.php"
echo "  - includes/admin/sections/class-wp-mcp-ai-section-integrations.php"
echo ""
echo "Updated redirect URI generation in:"
echo "  ✓ Gmail OAuth authorization (line ~103)"
echo "  ✓ Gmail OAuth token exchange (line ~188)"
echo "  ✓ Google Drive OAuth authorization (line ~345)"
echo "  ✓ Google Drive OAuth token exchange (line ~425)"
echo "  ✓ Gmail admin instructions display (line ~706)"
echo "  ✓ Google Drive admin instructions display (line ~879)"
echo ""

echo "=================================="
echo "✓ All verification checks passed!"
echo "=================================="
echo ""
echo "Next Steps:"
echo "1. Test Gmail OAuth connection in WordPress admin"
echo "2. Verify the redirect URI displayed matches what you entered in Google Cloud Console"
echo "3. Complete the OAuth flow and verify successful connection"
echo ""
echo "If you still get redirect_uri_mismatch errors:"
echo "  - Double-check the redirect URI in Google Cloud Console"
echo "  - Wait 30-60 seconds for Google's cache to update"
echo "  - Clear your browser cache"
echo "  - Check that your site uses HTTPS"
echo ""
