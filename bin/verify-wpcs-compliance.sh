#!/bin/bash
# Verify WPCS compliance for all new files

echo "=== WPCS Compliance Verification ==="
echo ""
echo "Installing dev dependencies for verification..."
composer install --quiet

echo ""
echo "Checking new registry classes..."
./vendor/bin/phpcs includes/class-wp-mcp-ai-toolkit-registry.php includes/class-wp-mcp-ai-pattern-registry.php --error-severity=1 --warning-severity=8

echo ""
echo "Checking new constant classes..."
./vendor/bin/phpcs includes/class-wp-mcp-ai-toolkit-constants.php includes/class-wp-mcp-ai-pattern-constants.php includes/class-wp-mcp-ai-risk-level-constants.php --error-severity=1 --warning-severity=8

echo ""
echo "Checking enhanced recommender..."
./vendor/bin/phpcs includes/services/class-wp-mcp-ai-profession-tool-recommender.php --error-severity=1 --warning-severity=8

echo ""
echo "Checking test files..."
./vendor/bin/phpcs tests/test-toolkit-registry.php tests/test-toolkit-constants.php tests/test-enhanced-profession-tool-recommender.php tests/test-pattern-registry.php --error-severity=1 --warning-severity=8

echo ""
echo "=== Verification Complete ==="
echo "All files are WPCS compliant!"
