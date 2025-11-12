#!/bin/bash
##
# Performance Test Runner for WP oOS
#
# Executes comprehensive performance test suites and generates reports.
#
# Usage:
#   ./bin/run-performance-tests.sh [--full|--suite=NAME|--report]
#
# Options:
#   --full          Run all test suites with both optimization states
#   --suite=NAME    Run specific suite (stress, security, speed, optimization, elementor)
#   --report        Generate performance report from historical data
#   --help          Show this help message
##

set -e

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Default options.
RUN_ALL=false
SUITE=""
GENERATE_REPORT=false

# Parse command line arguments.
for arg in "$@"; do
	case $arg in
		--full)
			RUN_ALL=true
			shift
			;;
		--suite=*)
			SUITE="${arg#*=}"
			shift
			;;
		--report)
			GENERATE_REPORT=true
			shift
			;;
		--help)
			head -n 15 "$0" | tail -n 13
			exit 0
			;;
		*)
			echo -e "${RED}Unknown option: $arg${NC}"
			echo "Run with --help for usage information"
			exit 1
			;;
	esac
done

# Print header.
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}WP oOS Performance Test Runner${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Navigate to project root.
cd "$PROJECT_ROOT"

# Ensure PHPUnit is available.
if [ ! -x "vendor/bin/phpunit" ]; then
        echo -e "${YELLOW}Installing development dependencies (including PHPUnit)...${NC}"
        composer install --no-interaction
        echo ""
fi

# Function to run a specific test suite.
run_suite() {
	local suite_name=$1
	local suite_file=$2
	
	echo -e "${GREEN}Running ${suite_name} Tests...${NC}"
	
	if [ -f "$suite_file" ]; then
		vendor/bin/phpunit "$suite_file"
		echo -e "${GREEN}✓ ${suite_name} tests completed${NC}"
	else
		echo -e "${YELLOW}⚠ ${suite_name} test file not found: $suite_file${NC}"
	fi
	
	echo ""
}

# Function to generate performance report.
generate_report() {
	echo -e "${GREEN}Generating Performance Report...${NC}"
	
	# Use WP-CLI if available.
	if command -v wp &> /dev/null; then
		wp eval-file "$SCRIPT_DIR/generate-performance-report.php" 2>/dev/null || {
			echo -e "${YELLOW}⚠ Performance report generation requires WordPress installation${NC}"
			echo -e "${YELLOW}  Run tests in a WordPress environment to generate reports${NC}"
		}
	else
		echo -e "${YELLOW}⚠ WP-CLI not found. Install WP-CLI to generate reports.${NC}"
	fi
	
	echo ""
}

# Main execution logic.
if [ "$GENERATE_REPORT" = true ]; then
	generate_report
	exit 0
fi

if [ "$RUN_ALL" = true ]; then
	echo -e "${BLUE}Running Full Performance Test Suite${NC}"
	echo -e "${BLUE}(All tests with optimization states)${NC}"
	echo ""
	
	# Run all test suites.
	run_suite "Stress" "tests/performance/test-stress-suite.php"
	run_suite "Security" "tests/security/test-security-suite.php"
	run_suite "Speed Benchmarks" "tests/performance/test-speed-benchmarks.php"
	run_suite "Optimization Comparison" "tests/performance/test-optimization-comparison.php"
	run_suite "Elementor Performance" "tests/performance/test-elementor-performance.php"
	
	echo -e "${GREEN}========================================${NC}"
	echo -e "${GREEN}All Performance Tests Completed${NC}"
	echo -e "${GREEN}========================================${NC}"
	
elif [ -n "$SUITE" ]; then
	case "$SUITE" in
		stress)
			run_suite "Stress" "tests/performance/test-stress-suite.php"
			;;
		security)
			run_suite "Security" "tests/security/test-security-suite.php"
			;;
		speed)
			run_suite "Speed Benchmarks" "tests/performance/test-speed-benchmarks.php"
			;;
		optimization)
			run_suite "Optimization Comparison" "tests/performance/test-optimization-comparison.php"
			;;
		elementor)
			run_suite "Elementor Performance" "tests/performance/test-elementor-performance.php"
			;;
		*)
			echo -e "${RED}Unknown test suite: $SUITE${NC}"
			echo "Available suites: stress, security, speed, optimization, elementor"
			exit 1
			;;
	esac
else
	echo "Usage: $0 [--full|--suite=NAME|--report|--help]"
	echo ""
	echo "Options:"
	echo "  --full           Run all test suites"
	echo "  --suite=NAME     Run specific suite (stress, security, speed, optimization, elementor)"
	echo "  --report         Generate performance report"
	echo "  --help           Show help message"
	echo ""
	echo "Examples:"
	echo "  $0 --full                    # Run all tests"
	echo "  $0 --suite=stress            # Run stress tests only"
	echo "  $0 --report                  # Generate performance report"
fi

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Test results stored in Performance Monitor CCT${NC}"
echo -e "${BLUE}View results in WordPress admin or via widgets${NC}"
echo -e "${BLUE}========================================${NC}"
