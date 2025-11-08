#!/bin/bash
# Verify refactoring didn't lose functionality

echo "=== Refactoring Verification Report ==="
echo "Generated: $(date)"
echo ""

# Generate current inventory
bash bin/code-inventory.sh > CURRENT-INVENTORY.txt 2>&1

# Compare with baseline
echo "=== Changes Summary ==="
echo ""

baseline_files=$(grep "^Total PHP Files:" BASELINE-INVENTORY.txt | awk '{print $4}')
current_files=$(grep "^Total PHP Files:" CURRENT-INVENTORY.txt | awk '{print $4}')
echo "PHP Files:"
echo "  Before: $baseline_files"
echo "  After:  $current_files"
if [ -n "$baseline_files" ] && [ -n "$current_files" ]; then
    echo "  Change: $((current_files - baseline_files))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_classes=$(grep "^Total Classes:" BASELINE-INVENTORY.txt | awk '{print $3}')
current_classes=$(grep "^Total Classes:" CURRENT-INVENTORY.txt | awk '{print $3}')
echo "Classes:"
echo "  Before: $baseline_classes"
echo "  After:  $current_classes"
if [ -n "$baseline_classes" ] && [ -n "$current_classes" ]; then
    echo "  Change: $((current_classes - baseline_classes))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_functions=$(grep "^Total Functions:" BASELINE-INVENTORY.txt | awk '{print $3}')
current_functions=$(grep "^Total Functions:" CURRENT-INVENTORY.txt | awk '{print $3}')
echo "Global Functions:"
echo "  Before: $baseline_functions"
echo "  After:  $current_functions"
if [ -n "$baseline_functions" ] && [ -n "$current_functions" ]; then
    echo "  Change: $((current_functions - baseline_functions))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_public=$(grep "^Total Methods (public):" BASELINE-INVENTORY.txt | awk '{print $4}')
current_public=$(grep "^Total Methods (public):" CURRENT-INVENTORY.txt | awk '{print $4}')
echo "Public Methods:"
echo "  Before: $baseline_public"
echo "  After:  $current_public"
if [ -n "$baseline_public" ] && [ -n "$current_public" ]; then
    echo "  Change: $((current_public - baseline_public))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_protected=$(grep "^Total Methods (protected):" BASELINE-INVENTORY.txt | awk '{print $4}')
current_protected=$(grep "^Total Methods (protected):" CURRENT-INVENTORY.txt | awk '{print $4}')
echo "Protected Methods:"
echo "  Before: $baseline_protected"
echo "  After:  $current_protected"
if [ -n "$baseline_protected" ] && [ -n "$current_protected" ]; then
    echo "  Change: $((current_protected - baseline_protected))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_private=$(grep "^Total Methods (private):" BASELINE-INVENTORY.txt | awk '{print $4}')
current_private=$(grep "^Total Methods (private):" CURRENT-INVENTORY.txt | awk '{print $4}')
echo "Private Methods:"
echo "  Before: $baseline_private"
echo "  After:  $current_private"
if [ -n "$baseline_private" ] && [ -n "$current_private" ]; then
    echo "  Change: $((current_private - baseline_private))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_actions=$(grep "^add_action calls:" BASELINE-INVENTORY.txt | awk '{print $3}')
current_actions=$(grep "^add_action calls:" CURRENT-INVENTORY.txt | awk '{print $3}')
echo "WordPress add_action Hooks:"
echo "  Before: $baseline_actions"
echo "  After:  $current_actions"
if [ -n "$baseline_actions" ] && [ -n "$current_actions" ]; then
    echo "  Change: $((current_actions - baseline_actions))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_filters=$(grep "^add_filter calls:" BASELINE-INVENTORY.txt | awk '{print $3}')
current_filters=$(grep "^add_filter calls:" CURRENT-INVENTORY.txt | awk '{print $3}')
echo "WordPress add_filter Hooks:"
echo "  Before: $baseline_filters"
echo "  After:  $current_filters"
if [ -n "$baseline_filters" ] && [ -n "$current_filters" ]; then
    echo "  Change: $((current_filters - baseline_filters))"
else
    echo "  Change: Unable to parse values"
fi

echo ""
baseline_routes=$(grep "^Total REST route registrations:" BASELINE-INVENTORY.txt | awk '{print $5}')
current_routes=$(grep "^Total REST route registrations:" CURRENT-INVENTORY.txt | awk '{print $5}')
echo "REST Route Registrations:"
echo "  Before: $baseline_routes"
echo "  After:  $current_routes"
if [ -n "$baseline_routes" ] && [ -n "$current_routes" ]; then
    echo "  Change: $((current_routes - baseline_routes))"
else
    echo "  Change: Unable to parse values"
fi

# Verify method counts in main classes
echo ""
echo "=== Three Main Classes - Method Changes ==="
for file in includes/class-wp-mcp-ai-rest.php includes/admin/class-wp-mcp-ai-admin-settings.php includes/assistants/class-wp-mcp-ai-assistant-cpt.php; do
    if [ -f "$file" ]; then
        baseline_total=$(grep -A 3 "File: $file" BASELINE-INVENTORY.txt | grep "Total methods:" | awk '{print $3}')
        current_total=$(grep -A 3 "File: $file" CURRENT-INVENTORY.txt | grep "Total methods:" | awk '{print $3}')
        echo ""
        echo "$file:"
        echo "  Before: $baseline_total methods"
        echo "  After:  $current_total methods"
        echo "  Change: $((current_total - baseline_total))"
    fi
done

# Check for critical errors
echo ""
echo "=== Critical Checks ==="

# Check if public methods were removed
echo ""
echo "Checking for removed public methods..."
# Extract method signatures from inventory files
sed -n '/^=== Public Method Signatures/,/^===/p' BASELINE-INVENTORY.txt | grep "^public function" | sort > /tmp/baseline-public.txt 2>/dev/null
sed -n '/^=== Public Method Signatures/,/^===/p' CURRENT-INVENTORY.txt | grep "^public function" | sort > /tmp/current-public.txt 2>/dev/null
removed_public=$(diff /tmp/baseline-public.txt /tmp/current-public.txt 2>/dev/null | grep "^<" | wc -l)
if [ "$removed_public" -gt 0 ]; then
    echo "  ❌ WARNING: $removed_public public method(s) removed!"
    echo "  Removed methods:"
    diff /tmp/baseline-public.txt /tmp/current-public.txt | grep "^<" | head -10 | sed 's/^< /    /'
else
    echo "  ✓ No public methods removed"
fi

# Check if classes were removed
echo ""
echo "Checking for removed classes..."
grep "^class " BASELINE-INVENTORY.txt 2>/dev/null | awk '{print $2}' | sort > /tmp/baseline-classes.txt
grep "^class " CURRENT-INVENTORY.txt 2>/dev/null | awk '{print $2}' | sort > /tmp/current-classes.txt
removed_classes=$(diff /tmp/baseline-classes.txt /tmp/current-classes.txt 2>/dev/null | grep "^<" | wc -l)
if [ "$removed_classes" -gt 0 ]; then
    echo "  ❌ WARNING: $removed_classes class(es) removed!"
    diff /tmp/baseline-classes.txt /tmp/current-classes.txt | grep "^<" | head -10
else
    echo "  ✓ No classes removed"
fi

# Check if global functions were removed
echo ""
echo "Checking for removed global functions..."
if [ -n "$baseline_functions" ] && [ -n "$current_functions" ] && [ "$current_functions" -lt "$baseline_functions" ]; then
    echo "  ❌ WARNING: $(($baseline_functions - $current_functions)) global function(s) removed!"
else
    echo "  ✓ No global functions removed"
fi

# Check if REST routes were removed
echo ""
echo "Checking for removed REST routes..."
if [ -n "$baseline_routes" ] && [ -n "$current_routes" ] && [ "$current_routes" -lt "$baseline_routes" ]; then
    echo "  ❌ WARNING: $(($baseline_routes - $current_routes)) REST route(s) removed!"
else
    echo "  ✓ No REST routes removed"
fi

# Summary
echo ""
echo "=== Verification Summary ==="
errors=0

if [ "$removed_public" -gt 0 ]; then
    errors=$((errors + 1))
fi
if [ "$removed_classes" -gt 0 ]; then
    errors=$((errors + 1))
fi
if [ -n "$baseline_functions" ] && [ -n "$current_functions" ] && [ "$current_functions" -lt "$baseline_functions" ]; then
    errors=$((errors + 1))
fi
if [ -n "$baseline_routes" ] && [ -n "$current_routes" ] && [ "$current_routes" -lt "$baseline_routes" ]; then
    errors=$((errors + 1))
fi

if [ "$errors" -eq 0 ]; then
    echo "✓ All verification checks passed!"
    echo "✓ No critical functionality was removed"
    exit 0
else
    echo "❌ $errors critical issue(s) found!"
    echo "❌ Review the changes before proceeding"
    exit 1
fi
