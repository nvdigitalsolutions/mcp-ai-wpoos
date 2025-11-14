#!/bin/bash
#
# Test Analysis Script
# Runs tests and generates a comprehensive bug report
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$SCRIPT_DIR/.."
cd "$ROOT_DIR"

export WP_CORE_DIR="/tmp/wordpress"

echo "========================================"
echo "WP oOS Plugin - Test Suite Analysis"
echo "========================================"
echo ""

# Ensure MySQL is running
if ! mysqladmin ping -h localhost --silent 2>/dev/null; then
    echo "Starting MySQL..."
    sudo service mysql start
fi

# Run full test suite
echo "Running full test suite..."
echo ""

vendor/bin/phpunit --no-coverage 2>&1 | tee /tmp/test-full-output.log

# Extract summary
echo ""
echo "========================================"
echo "Test Results Summary"
echo "========================================"
grep -E "^(Tests:|Assertions:|Failures:|Errors:|Warnings:|Skipped:|Risky:|Time:)" /tmp/test-full-output.log || true

# Count test results
TOTAL_TESTS=$(grep -oP "Tests: \K\d+" /tmp/test-full-output.log | head -1 || echo "N/A")
FAILURES=$(grep -oP "Failures: \K\d+" /tmp/test-full-output.log | head -1 || echo "0")
ERRORS=$(grep -oP "Errors: \K\d+" /tmp/test-full-output.log | head -1 || echo "0")
SKIPPED=$(grep -oP "Skipped: \K\d+" /tmp/test-full-output.log | head -1 || echo "0")

echo ""
echo "Total Tests: $TOTAL_TESTS"
echo "Failures: $FAILURES"
echo "Errors: $ERRORS"
echo "Skipped: $SKIPPED"
echo ""

# Generate bug report
echo "Generating detailed bug report..."
cat > /tmp/bug-report.md << EOF
# WP oOS Plugin - Bug Report
## Test Suite Execution - $(date)

### Test Environment
- WordPress Version: 6.7.1
- PHP Version: $(php --version | head -1)
- PHPUnit Version: $(vendor/bin/phpunit --version)
- Database: MySQL 8.0

### Test Results Summary
- **Total Tests**: $TOTAL_TESTS
- **Failures**: $FAILURES
- **Errors**: $ERRORS
- **Skipped**: $SKIPPED
- **Pass Rate**: $(( (${TOTAL_TESTS:-0} - ${FAILURES:-0} - ${ERRORS:-0}) * 100 / ${TOTAL_TESTS:-1} ))%

### Critical Issues Found

EOF

# Append detailed test output
echo "### Full Test Output" >> /tmp/bug-report.md
echo '```' >> /tmp/bug-report.md
tail -500 /tmp/test-full-output.log >> /tmp/bug-report.md
echo '```' >> /tmp/bug-report.md

echo "Bug report saved to: /tmp/bug-report.md"
echo ""
