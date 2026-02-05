#!/bin/bash
# Manual verification script for slash commands dashboard fix

echo "============================================="
echo "Slash Commands Dashboard Fix Verification"
echo "============================================="
echo ""

# Check if files exist
echo "1. Checking if modified files exist..."
if [ -f "includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php" ]; then
    echo "   ✓ Admin dashboard file exists"
else
    echo "   ✗ Admin dashboard file missing!"
    exit 1
fi

if [ -f "includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php" ]; then
    echo "   ✓ Handler file exists"
else
    echo "   ✗ Handler file missing!"
    exit 1
fi

if [ -f "includes/slash-commands/slash-commands-init.php" ]; then
    echo "   ✓ Init file exists"
else
    echo "   ✗ Init file missing!"
    exit 1
fi

echo ""
echo "2. Verifying correct method calls..."

# Check that get_instance is NOT used
if grep -q "WP_MCP_AI_Slash_Command_Handler::get_instance" includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php; then
    echo "   ✗ Still using get_instance() - this should have been removed!"
    exit 1
else
    echo "   ✓ No longer using get_instance()"
fi

# Check that wp_mcp_ai_get_slash_command_handler is used
if grep -q "wp_mcp_ai_get_slash_command_handler" includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php; then
    echo "   ✓ Using wp_mcp_ai_get_slash_command_handler() helper"
else
    echo "   ✗ Not using the correct helper function!"
    exit 1
fi

# Check that get_commands() is used instead of get_registered_commands()
if grep -q "get_registered_commands" includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php; then
    echo "   ✗ Still using get_registered_commands() - this should have been removed!"
    exit 1
else
    echo "   ✓ No longer using get_registered_commands()"
fi

if grep -q "get_commands()" includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php; then
    echo "   ✓ Using get_commands() method"
else
    echo "   ✗ Not using get_commands() method!"
    exit 1
fi

# Check that execute() is used instead of handle()
HANDLE_COUNT=$(grep -c '->handle(' includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php || echo "0")
if [ "$HANDLE_COUNT" -gt 0 ]; then
    echo "   ✗ Still using handle() method - should be execute()!"
    exit 1
else
    echo "   ✓ No longer using handle() method"
fi

if grep -q '->execute(' includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php; then
    echo "   ✓ Using execute() method"
else
    echo "   ✗ Not using execute() method!"
    exit 1
fi

echo ""
echo "3. Verifying null checks are in place..."
NULL_CHECKS=$(grep -c "if ( ! \$handler )" includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php || echo "0")
if [ "$NULL_CHECKS" -ge 3 ]; then
    echo "   ✓ Found $NULL_CHECKS null checks for handler"
else
    echo "   ⚠ Only found $NULL_CHECKS null checks (expected at least 3)"
fi

echo ""
echo "4. Checking PHP syntax..."
if php -l includes/admin/class-wp-mcp-ai-admin-slash-commands-dashboard.php > /dev/null 2>&1; then
    echo "   ✓ PHP syntax is valid"
else
    echo "   ✗ PHP syntax errors found!"
    exit 1
fi

echo ""
echo "============================================="
echo "✓ All verification checks passed!"
echo "============================================="
echo ""
echo "The slash commands dashboard should now work without fatal errors."
echo ""
echo "To test in WordPress:"
echo "1. Access: /wp-admin/admin.php?page=mcp-ai-slash-commands"
echo "2. Verify the page loads without errors"
echo "3. Check that commands are listed in the dashboard"
echo ""
