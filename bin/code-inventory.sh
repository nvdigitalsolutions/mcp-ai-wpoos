#!/bin/bash
# Generate code inventory for verification

echo "=== Code Inventory Report ==="
echo "Generated: $(date)"
echo ""

echo "Total PHP Files:"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" | wc -l

echo ""
echo "Total Lines of Code:"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -exec wc -l {} + 2>/dev/null | tail -1

echo ""
echo "Total Classes:"
grep -r "^class " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l

echo ""
echo "Total Functions:"
grep -r "^function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l

echo ""
echo "Total Methods (public):"
grep -r "public function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l

echo ""
echo "Total Methods (protected):"
grep -r "protected function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l

echo ""
echo "Total Methods (private):"
grep -r "private function " --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l

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
grep -r "^function wp_mcp_ai" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l
echo "Total wp_mcp_ai_* functions"

echo ""
echo "=== WordPress Hook Registrations ==="
echo "add_action calls: $(grep -r "add_action" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)"
echo "add_filter calls: $(grep -r "add_filter" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l)"

echo ""
echo "=== REST API Endpoints ==="
grep -r "register_rest_route" --include="*.php" --exclude-dir=vendor --exclude-dir=node_modules . 2>/dev/null | wc -l
echo "Total REST route registrations"
