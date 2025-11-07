#!/bin/bash
# Test script to verify PHP version check works

echo "Testing WP oOS PHP Version Check"
echo "================================"
echo ""

# Test 1: Check that plugin file has the version check
echo "Test 1: Verify PHP version check exists in wp-mcp-ai.php"
if grep -q "version_compare.*PHP_VERSION.*7.4.0" wp-mcp-ai.php; then
    echo "✓ PHP version check found"
else
    echo "✗ PHP version check NOT found"
    exit 1
fi
echo ""

# Test 2: Check that plugin header has Requires PHP
echo "Test 2: Verify 'Requires PHP' header exists"
if grep -q "Requires PHP: 7.4" wp-mcp-ai.php; then
    echo "✓ 'Requires PHP: 7.4' header found"
else
    echo "✗ 'Requires PHP' header NOT found"
    exit 1
fi
echo ""

# Test 3: Check readme.txt has PHP requirement
echo "Test 3: Verify readme.txt has PHP requirement"
if grep -q "Requires PHP: 7.4" readme.txt; then
    echo "✓ PHP requirement in readme.txt found"
else
    echo "✗ PHP requirement in readme.txt NOT found"
    exit 1
fi
echo ""

# Test 4: Verify syntax of all PHP files
echo "Test 4: Verify PHP syntax of modified files"
files_to_check=(
    "wp-mcp-ai.php"
    "includes/admin/class-wp-mcp-ai-admin-settings.php"
    "includes/class-admin-settings.php"
)

all_valid=true
for file in "${files_to_check[@]}"; do
    if [ -f "$file" ]; then
        if php -l "$file" > /dev/null 2>&1; then
            echo "✓ $file: valid syntax"
        else
            echo "✗ $file: syntax error"
            php -l "$file"
            all_valid=false
        fi
    fi
done
echo ""

if [ "$all_valid" = false ]; then
    exit 1
fi

# Test 5: Check that troubleshooting guide exists
echo "Test 5: Verify troubleshooting guide exists"
if [ -f "TROUBLESHOOTING-SYNTAX-ERRORS.md" ]; then
    echo "✓ TROUBLESHOOTING-SYNTAX-ERRORS.md exists"
else
    echo "✗ TROUBLESHOOTING-SYNTAX-ERRORS.md NOT found"
    exit 1
fi
echo ""

# Test 6: Check that OPcache notice method exists
echo "Test 6: Verify OPcache warning method exists"
if grep -q "maybe_render_opcache_warning" includes/admin/class-wp-mcp-ai-admin-settings.php; then
    echo "✓ maybe_render_opcache_warning method found"
else
    echo "✗ maybe_render_opcache_warning method NOT found"
    exit 1
fi
echo ""

echo "================================"
echo "All tests passed! ✓"
echo ""
echo "Summary:"
echo "- PHP version check: ✓"
echo "- Plugin headers: ✓"
echo "- Syntax validation: ✓"
echo "- Documentation: ✓"
echo "- Admin notices: ✓"
