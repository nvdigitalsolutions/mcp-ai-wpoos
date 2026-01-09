#!/bin/bash
#
# Test Script for Screenshot Capture Tools
# This script validates the automation tools without requiring an API key.
#
# Usage: bash bin/test-screenshot-tools.sh
#

set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters
PASSED=0
FAILED=0
TOTAL=0

# Helper functions
log_test() {
    echo -e "${BLUE}[TEST]${NC} $1"
    TOTAL=$((TOTAL + 1))
}

log_pass() {
    echo -e "${GREEN}  ✓ PASS${NC} $1"
    PASSED=$((PASSED + 1))
}

log_fail() {
    echo -e "${RED}  ✗ FAIL${NC} $1"
    FAILED=$((FAILED + 1))
}

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

# Test 1: Check if setup script exists and is executable
test_setup_script_exists() {
    log_test "Setup script exists and is executable"
    
    if [ -f "bin/capture-chat-screenshots.sh" ]; then
        log_pass "Setup script exists"
    else
        log_fail "Setup script not found at bin/capture-chat-screenshots.sh"
        return 1
    fi
    
    if [ -x "bin/capture-chat-screenshots.sh" ]; then
        log_pass "Setup script is executable"
    else
        log_fail "Setup script is not executable (run: chmod +x bin/capture-chat-screenshots.sh)"
        return 1
    fi
}

# Test 2: Check if Playwright script exists
test_playwright_script_exists() {
    log_test "Playwright script exists"
    
    if [ -f "bin/playwright-capture-screenshots.js" ]; then
        log_pass "Playwright script exists"
    else
        log_fail "Playwright script not found at bin/playwright-capture-screenshots.js"
        return 1
    fi
}

# Test 3: Check if documentation exists
test_documentation_exists() {
    log_test "Documentation files exist"
    
    if [ -f "docs/screenshots/CHAT_CAPTURE_GUIDE.md" ]; then
        log_pass "CHAT_CAPTURE_GUIDE.md exists"
    else
        log_fail "CHAT_CAPTURE_GUIDE.md not found"
        return 1
    fi
    
    if [ -f "docs/screenshots/SCREENSHOT_PROGRESS.md" ]; then
        log_pass "SCREENSHOT_PROGRESS.md exists"
    else
        log_fail "SCREENSHOT_PROGRESS.md not found"
        return 1
    fi
}

# Test 4: Check Docker availability
test_docker_available() {
    log_test "Docker is available"
    
    if command -v docker >/dev/null 2>&1; then
        log_pass "Docker command found"
    else
        log_fail "Docker not installed"
        return 1
    fi
    
    if command -v docker compose >/dev/null 2>&1; then
        log_pass "Docker Compose available"
    else
        log_fail "Docker Compose not available (install Docker Compose v2)"
        return 1
    fi
}

# Test 5: Check if Docker containers are running
test_docker_running() {
    log_test "Docker containers are running"
    
    if docker compose ps 2>/dev/null | grep -q "Up"; then
        log_pass "Docker containers are running"
    else
        log_warn "Docker containers not running (start with: docker compose up -d)"
    fi
}

# Test 6: Check screenshot directory structure
test_screenshot_directories() {
    log_test "Screenshot directory structure"
    
    if [ -d "docs/screenshots" ]; then
        log_pass "Screenshots directory exists"
    else
        log_fail "docs/screenshots directory not found"
        return 1
    fi
    
    if [ -d "docs/screenshots/chat" ]; then
        log_pass "Chat screenshots directory exists"
    else
        log_warn "Chat screenshots directory not found (will be created when needed)"
    fi
}

# Test 7: Validate setup script syntax
test_setup_script_syntax() {
    log_test "Setup script syntax is valid"
    
    if bash -n bin/capture-chat-screenshots.sh 2>/dev/null; then
        log_pass "Setup script syntax is valid"
    else
        log_fail "Setup script has syntax errors"
        return 1
    fi
}

# Test 8: Check Node.js availability for Playwright
test_nodejs_available() {
    log_test "Node.js is available for Playwright"
    
    if command -v node >/dev/null 2>&1; then
        NODE_VERSION=$(node --version)
        log_pass "Node.js available: $NODE_VERSION"
    else
        log_warn "Node.js not installed (required for Playwright automation)"
    fi
    
    if command -v npm >/dev/null 2>&1; then
        NPM_VERSION=$(npm --version)
        log_pass "npm available: v$NPM_VERSION"
    else
        log_warn "npm not installed"
    fi
}

# Test 9: Check if package.json allows Playwright
test_package_json() {
    log_test "Package.json configuration"
    
    if [ -f "package.json" ]; then
        log_pass "package.json exists"
        
        if grep -q "playwright" package.json 2>/dev/null; then
            log_info "Playwright already in package.json"
        else
            log_info "Playwright not in package.json (will be installed separately)"
        fi
    else
        log_fail "package.json not found"
        return 1
    fi
}

# Test 10: Verify Docker Compose configuration
test_docker_compose_config() {
    log_test "Docker Compose configuration"
    
    if [ -f "docker-compose.yml" ]; then
        log_pass "docker-compose.yml exists"
    else
        log_fail "docker-compose.yml not found"
        return 1
    fi
    
    if grep -q "wordpress" docker-compose.yml; then
        log_pass "WordPress service configured"
    else
        log_fail "WordPress service not found in docker-compose.yml"
        return 1
    fi
    
    if grep -q "wp-cli" docker-compose.yml; then
        log_pass "WP-CLI service configured"
    else
        log_fail "WP-CLI service not found in docker-compose.yml"
        return 1
    fi
}

# Main test execution
main() {
    echo ""
    echo "========================================="
    echo "Screenshot Capture Tools - Test Suite"
    echo "========================================="
    echo ""
    
    # Run all tests (allow failures)
    test_setup_script_exists || true
    test_playwright_script_exists || true
    test_documentation_exists || true
    test_docker_available || true
    test_docker_running || true
    test_screenshot_directories || true
    test_setup_script_syntax || true
    test_nodejs_available || true
    test_package_json || true
    test_docker_compose_config || true
    
    # Print summary
    echo ""
    echo "========================================="
    echo "Test Summary"
    echo "========================================="
    echo -e "Total Tests: ${BLUE}$TOTAL${NC}"
    echo -e "Passed:      ${GREEN}$PASSED${NC}"
    echo -e "Failed:      ${RED}$FAILED${NC}"
    echo ""
    
    if [ $FAILED -eq 0 ]; then
        echo -e "${GREEN}✓ All tests passed!${NC}"
        echo ""
        echo "Next steps:"
        echo "  1. Set AI provider API key: export OPENAI_API_KEY='sk-...'"
        echo "  2. Start Docker: docker compose up -d"
        echo "  3. Run setup: ./bin/capture-chat-screenshots.sh"
        echo "  4. Capture screenshots: node bin/playwright-capture-screenshots.js"
        echo ""
        exit 0
    else
        echo -e "${RED}✗ Some tests failed!${NC}"
        echo ""
        echo "Please fix the failed tests before proceeding."
        echo "See docs/screenshots/CHAT_CAPTURE_GUIDE.md for help."
        echo ""
        exit 1
    fi
}

# Change to repository root
cd "$(dirname "$0")/.."

# Run tests
main "$@"
