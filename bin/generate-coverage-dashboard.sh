#!/bin/bash
# Generate Code Coverage Dashboard
# This script generates a comprehensive code coverage report with HTML dashboard

set -e

echo "🔍 Generating Code Coverage Dashboard..."

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Check if vendor/bin/phpunit exists
if [ ! -f "vendor/bin/phpunit" ]; then
    echo -e "${RED}❌ PHPUnit not found. Run 'composer install' first.${NC}"
    exit 1
fi

# Create coverage directory if it doesn't exist
mkdir -p coverage

echo "📊 Running PHPUnit with coverage..."

# Run PHPUnit with HTML coverage report
if vendor/bin/phpunit --coverage-html coverage/html --coverage-clover coverage/clover.xml --coverage-text; then
    echo -e "${GREEN}✅ Coverage report generated successfully!${NC}"
else
    echo -e "${RED}❌ Coverage generation failed${NC}"
    exit 1
fi

# Extract coverage percentage
if [ -f coverage/clover.xml ]; then
    COVERAGE=$(php -r "
        \$xml = simplexml_load_file('coverage/clover.xml');
        if (\$xml && isset(\$xml->project->metrics)) {
            \$metrics = \$xml->project->metrics;
            \$statements = (int)\$metrics['statements'];
            \$covered = (int)\$metrics['coveredstatements'];
            if (\$statements > 0) {
                echo round((\$covered / \$statements) * 100, 2);
            } else {
                echo '0';
            }
        } else {
            echo '0';
        }
    ")
    
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    echo "                   COVERAGE SUMMARY"
    echo "═══════════════════════════════════════════════════════════"
    echo ""
    
    # Color code the coverage percentage
    if (( $(echo "$COVERAGE >= 80" | bc -l) )); then
        COLOR=$GREEN
        STATUS="Excellent"
    elif (( $(echo "$COVERAGE >= 70" | bc -l) )); then
        COLOR=$YELLOW
        STATUS="Good"
    else
        COLOR=$RED
        STATUS="Needs Improvement"
    fi
    
    echo -e "  Total Coverage: ${COLOR}${COVERAGE}%${NC} (${STATUS})"
    echo ""
    echo "  HTML Report:    coverage/html/index.html"
    echo "  Clover XML:     coverage/clover.xml"
    echo "  Text Report:    (printed above)"
    echo ""
    echo "═══════════════════════════════════════════════════════════"
    echo ""
    
    # Generate coverage badge
    if (( $(echo "$COVERAGE >= 80" | bc -l) )); then
        BADGE_COLOR="brightgreen"
    elif (( $(echo "$COVERAGE >= 70" | bc -l) )); then
        BADGE_COLOR="yellow"
    else
        BADGE_COLOR="red"
    fi
    
    # Create a simple markdown file with coverage info
    cat > coverage/COVERAGE_REPORT.md << EOF
# Code Coverage Report

**Generated:** $(date)

## Summary

- **Total Coverage:** ${COVERAGE}%
- **Status:** ${STATUS}
- **Target:** 70%+

## Coverage by Component

View the detailed HTML report at \`coverage/html/index.html\`

## How to Improve Coverage

1. Add tests for uncovered files
2. Focus on core components first
3. Target critical paths and error handling
4. Use the HTML report to identify gaps

## Running Coverage Locally

\`\`\`bash
# Generate coverage report
composer run test:coverage

# Or use this script
./bin/generate-coverage-dashboard.sh

# View HTML report
open coverage/html/index.html
\`\`\`

## CI/CD Integration

Coverage is automatically generated and uploaded to Codecov on every push to main and on pull requests.

- View on Codecov: https://codecov.io/gh/nvdigitalsolutions/mcp-ai-wpoos
- Coverage badge in README shows current main branch coverage
EOF
    
    echo -e "${GREEN}✅ Coverage dashboard available at: coverage/html/index.html${NC}"
    echo -e "${GREEN}✅ Coverage report saved to: coverage/COVERAGE_REPORT.md${NC}"
    echo ""
    
    # Suggest opening the report
    if command -v open &> /dev/null; then
        echo "💡 To view the dashboard, run: open coverage/html/index.html"
    elif command -v xdg-open &> /dev/null; then
        echo "💡 To view the dashboard, run: xdg-open coverage/html/index.html"
    else
        echo "💡 Open coverage/html/index.html in your browser to view the dashboard"
    fi
else
    echo -e "${RED}❌ No coverage data generated${NC}"
    exit 1
fi
