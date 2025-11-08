#!/bin/bash
# Generate code inventory for verification

echo "=== Code Inventory Report ==="
echo "Generated: $(date)"
echo ""

php_files=$(find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" | wc -l)
echo "Total PHP Files: $php_files"

echo ""
lines_of_code=$(find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -exec wc -l {} + 2>/dev/null | tail -1 | awk '{print $1}')
echo "Total Lines of Code: $lines_of_code"

echo ""
total_classes=$(grep -r "^class " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total Classes: $total_classes"

echo ""
total_functions=$(grep -r "^function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total Functions: $total_functions"

echo ""
public_methods=$(grep -r "public function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total Methods (public): $public_methods"

echo ""
protected_methods=$(grep -r "protected function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total Methods (protected): $protected_methods"

echo ""
private_methods=$(grep -r "private function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total Methods (private): $private_methods"

echo ""
echo "=== Three Main Classes - Method Count ==="
for file in includes/class-wp-mcp-ai-rest.php includes/admin/class-wp-mcp-ai-admin-settings.php includes/assistants/class-wp-mcp-ai-assistant-cpt.php; do
    if [ -f "$file" ]; then
        echo ""
        echo "File: $file"
        echo "  Public methods: $(grep "public function " "$file" | wc -l)"
        echo "  Protected methods: $(grep "protected function " "$file" | wc -l)"
        echo "  Private methods: $(grep "private function " "$file" | wc -l)"
        echo "  Total methods: $(grep -E "^\s*(public|private|protected) function " "$file" | wc -l)"
    fi
done

echo ""
echo "=== Detailed Class Inventory ==="
grep -r "^class " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | sed 's/:class /: /' | sort

echo ""
echo "=== Global Function Definitions ==="
wp_mcp_ai_functions=$(grep -r "^function wp_mcp_ai" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total wp_mcp_ai_* functions: $wp_mcp_ai_functions"

echo ""
echo "=== WordPress Hook Registrations ==="
action_hooks=$(grep -r "add_action" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
filter_hooks=$(grep -r "add_filter" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "add_action calls: $action_hooks"
echo "add_filter calls: $filter_hooks"

echo ""
echo "=== REST API Endpoints ==="
rest_routes=$(grep -r "register_rest_route" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)
echo "Total REST route registrations: $rest_routes"

echo ""
echo "=== Public Method Signatures (for verification) ==="
grep -rh "public function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | \
    sed 's/^\s*//' | \
    sed 's/{.*//' | \
    sort | \
    uniq
