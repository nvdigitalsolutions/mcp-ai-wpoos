#!/bin/bash
#
# Run all orchestration tests for the 5 enhanced tools
#
# Usage:
#   bash bin/run-orchestration-tests.sh
#   bash bin/run-orchestration-tests.sh --coverage

set -e

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}Multi-Step Orchestration Test Suite${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Check if PHPUnit is available
if [ ! -f "vendor/bin/phpunit" ]; then
    echo -e "${RED}Error: PHPUnit not found. Run 'composer install' first.${NC}"
    exit 1
fi

# Define test files
TESTS=(
    "tests/test-create-woo-product-orchestration.php"
    "tests/test-save-post-orchestration.php"
    "tests/test-generate-openai-image-orchestration.php"
    "tests/test-generate-gemini-image-orchestration.php"
    "tests/test-create-assistant-orchestration.php"
)

# Check if all test files exist
echo -e "${YELLOW}Checking test files...${NC}"
for test in "${TESTS[@]}"; do
    if [ -f "$test" ]; then
        echo -e "  ${GREEN}✓${NC} $test"
    else
        echo -e "  ${RED}✗${NC} $test (missing)"
        exit 1
    fi
done
echo ""

# Run tests
echo -e "${YELLOW}Running orchestration tests...${NC}"
echo ""

FAILED=0
PASSED=0

for test in "${TESTS[@]}"; do
    tool_name=$(basename "$test" | sed 's/test-//' | sed 's/-orchestration.php//')
    echo -e "${BLUE}Testing:${NC} $tool_name"
    
    if [ "$1" == "--coverage" ]; then
        vendor/bin/phpunit --coverage-text "$test" 2>&1 | tee /tmp/phpunit_output.txt
    else
        vendor/bin/phpunit "$test" 2>&1 | tee /tmp/phpunit_output.txt
    fi
    
    if [ ${PIPESTATUS[0]} -eq 0 ]; then
        echo -e "${GREEN}✓ PASSED${NC}"
        ((PASSED++))
    else
        echo -e "${RED}✗ FAILED${NC}"
        ((FAILED++))
    fi
    echo ""
done

# Summary
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}Test Summary${NC}"
echo -e "${BLUE}================================================${NC}"
echo -e "Total test files: ${#TESTS[@]}"
echo -e "${GREEN}Passed: $PASSED${NC}"
if [ $FAILED -gt 0 ]; then
    echo -e "${RED}Failed: $FAILED${NC}"
else
    echo -e "Failed: $FAILED"
fi
echo ""

# Exit code
if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All orchestration tests passed! ✓${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed. ✗${NC}"
    exit 1
fi
