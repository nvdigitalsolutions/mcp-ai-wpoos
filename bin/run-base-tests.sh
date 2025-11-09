#!/bin/bash
#
# Base Functionality Test Runner
#
# This script runs comprehensive tests for the base version of WP oOS plugin.
# It ensures all core functionality works without third-party plugin dependencies.
#
# Usage:
#   ./bin/run-base-tests.sh [options]
#
# Options:
#   --verbose    Show detailed test output
#   --coverage   Generate code coverage report
#   --filter     Run specific test (e.g., --filter test_plugin_constants_defined)
#   --help       Show this help message

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default options
VERBOSE=false
COVERAGE=false
FILTER=""

# Parse command line arguments
while [[ $# -gt 0 ]]; do
	case $1 in
		--verbose)
			VERBOSE=true
			shift
			;;
		--coverage)
			COVERAGE=true
			shift
			;;
		--filter)
			FILTER="$2"
			shift 2
			;;
		--help)
			echo "Usage: $0 [options]"
			echo ""
			echo "Options:"
			echo "  --verbose    Show detailed test output"
			echo "  --coverage   Generate code coverage report"
			echo "  --filter     Run specific test"
			echo "  --help       Show this help message"
			exit 0
			;;
		*)
			echo -e "${RED}Unknown option: $1${NC}"
			exit 1
			;;
	esac
done

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                                                                ║${NC}"
echo -e "${BLUE}║         WP oOS Base Functionality Test Runner                 ║${NC}"
echo -e "${BLUE}║                                                                ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check if we're in the right directory
if [ ! -f "$PROJECT_ROOT/wp-mcp-ai.php" ]; then
	echo -e "${RED}Error: Could not find wp-mcp-ai.php${NC}"
	echo "Please run this script from the plugin root directory."
	exit 1
fi

# Check if PHPUnit is installed
if [ ! -f "$PROJECT_ROOT/vendor/bin/phpunit" ]; then
	echo -e "${YELLOW}PHPUnit not found. Installing dependencies...${NC}"
	cd "$PROJECT_ROOT"
	composer install --no-interaction
fi

# Check if WordPress test environment is set up
if [ ! -d "$PROJECT_ROOT/vendor/wp-phpunit/wp-phpunit" ]; then
	echo -e "${RED}Error: WordPress test environment not found.${NC}"
	echo "Run 'composer install' to set up the test environment."
	exit 1
fi

echo -e "${GREEN}✓${NC} Environment checks passed"
echo ""

# Build PHPUnit command
PHPUNIT_CMD="$PROJECT_ROOT/vendor/bin/phpunit"
PHPUNIT_ARGS=""

# Add test file
if [ -n "$FILTER" ]; then
	PHPUNIT_ARGS="$PHPUNIT_ARGS --filter=$FILTER"
fi

# Add verbose flag
if [ "$VERBOSE" = true ]; then
	PHPUNIT_ARGS="$PHPUNIT_ARGS --verbose"
else
	PHPUNIT_ARGS="$PHPUNIT_ARGS --colors=always"
fi

# Add coverage flag
if [ "$COVERAGE" = true ]; then
	if ! php -m | grep -q xdebug; then
		echo -e "${YELLOW}Warning: Xdebug not installed. Coverage will be skipped.${NC}"
		echo ""
	else
		PHPUNIT_ARGS="$PHPUNIT_ARGS --coverage-html $PROJECT_ROOT/coverage"
		echo -e "${BLUE}Code coverage will be generated in: ${PROJECT_ROOT}/coverage${NC}"
		echo ""
	fi
fi

# Test suites to run
echo -e "${BLUE}Running Base Functionality Tests...${NC}"
echo ""

# 1. Run the comprehensive base functionality test
echo -e "${YELLOW}→${NC} Comprehensive Base Functionality Test Suite"
$PHPUNIT_CMD $PHPUNIT_ARGS tests/test-base-functionality-comprehensive.php
BASE_EXIT_CODE=$?

if [ $BASE_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}✓${NC} Comprehensive base functionality tests passed"
else
	echo -e "${RED}✗${NC} Comprehensive base functionality tests failed"
fi

echo ""

# 2. Run base version mode test
echo -e "${YELLOW}→${NC} Base Version Mode Tests"
$PHPUNIT_CMD $PHPUNIT_ARGS tests/test-base-version.php
VERSION_EXIT_CODE=$?

if [ $VERSION_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}✓${NC} Base version mode tests passed"
else
	echo -e "${RED}✗${NC} Base version mode tests failed"
fi

echo ""

# 3. Run core plugin tests
echo -e "${YELLOW}→${NC} Core Plugin Tests"
$PHPUNIT_CMD $PHPUNIT_ARGS tests/test-assistant-tools.php
TOOLS_EXIT_CODE=$?

if [ $TOOLS_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}✓${NC} Core plugin tests passed"
else
	echo -e "${RED}✗${NC} Core plugin tests failed"
fi

echo ""

# 4. Run REST API tests
echo -e "${YELLOW}→${NC} REST API Tests"
$PHPUNIT_CMD $PHPUNIT_ARGS --testsuite rest-api
REST_EXIT_CODE=$?

if [ $REST_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}✓${NC} REST API tests passed"
else
	echo -e "${RED}✗${NC} REST API tests failed"
fi

echo ""

# 5. Run tool registry tests
echo -e "${YELLOW}→${NC} Tool Registry Tests"
$PHPUNIT_CMD $PHPUNIT_ARGS tests/test-tool-registry.php
REGISTRY_EXIT_CODE=$?

if [ $REGISTRY_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}✓${NC} Tool registry tests passed"
else
	echo -e "${RED}✗${NC} Tool registry tests failed"
fi

echo ""

# Calculate overall result
TOTAL_EXIT_CODE=$((BASE_EXIT_CODE + VERSION_EXIT_CODE + TOOLS_EXIT_CODE + REST_EXIT_CODE + REGISTRY_EXIT_CODE))

# Print summary
echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                        Test Summary                            ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Test results table
printf "%-50s %s\n" "Test Suite" "Status"
printf "%-50s %s\n" "──────────────────────────────────────────────────" "──────"

if [ $BASE_EXIT_CODE -eq 0 ]; then
	printf "%-50s ${GREEN}✓ PASS${NC}\n" "Comprehensive Base Functionality"
else
	printf "%-50s ${RED}✗ FAIL${NC}\n" "Comprehensive Base Functionality"
fi

if [ $VERSION_EXIT_CODE -eq 0 ]; then
	printf "%-50s ${GREEN}✓ PASS${NC}\n" "Base Version Mode"
else
	printf "%-50s ${RED}✗ FAIL${NC}\n" "Base Version Mode"
fi

if [ $TOOLS_EXIT_CODE -eq 0 ]; then
	printf "%-50s ${GREEN}✓ PASS${NC}\n" "Core Plugin"
else
	printf "%-50s ${RED}✗ FAIL${NC}\n" "Core Plugin"
fi

if [ $REST_EXIT_CODE -eq 0 ]; then
	printf "%-50s ${GREEN}✓ PASS${NC}\n" "REST API"
else
	printf "%-50s ${RED}✗ FAIL${NC}\n" "REST API"
fi

if [ $REGISTRY_EXIT_CODE -eq 0 ]; then
	printf "%-50s ${GREEN}✓ PASS${NC}\n" "Tool Registry"
else
	printf "%-50s ${RED}✗ FAIL${NC}\n" "Tool Registry"
fi

echo ""

if [ $TOTAL_EXIT_CODE -eq 0 ]; then
	echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
	echo -e "${GREEN}║                                                                ║${NC}"
	echo -e "${GREEN}║              ALL BASE FUNCTIONALITY TESTS PASSED! ✓            ║${NC}"
	echo -e "${GREEN}║                                                                ║${NC}"
	echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
	echo ""
	echo "The base version of WP oOS is functioning correctly."
	echo ""
	exit 0
else
	echo -e "${RED}╔════════════════════════════════════════════════════════════════╗${NC}"
	echo -e "${RED}║                                                                ║${NC}"
	echo -e "${RED}║              SOME BASE FUNCTIONALITY TESTS FAILED ✗            ║${NC}"
	echo -e "${RED}║                                                                ║${NC}"
	echo -e "${RED}╚════════════════════════════════════════════════════════════════╝${NC}"
	echo ""
	echo "Please review the test output above for details."
	echo ""
	echo "For detailed information, run:"
	echo "  $0 --verbose"
	echo ""
	echo "To see code coverage, run:"
	echo "  $0 --coverage"
	echo ""
	exit 1
fi
