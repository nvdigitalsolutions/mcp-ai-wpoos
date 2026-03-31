#!/bin/bash
#
# Review All Plugin ZIPs
#
# Validates all generated ZIP files to ensure they are correct
# before testing or release. Checks:
# - ZIP file existence and integrity
# - Plugin headers present with correct version
# - No dev files leaked (node_modules, tests, .git, etc.)
# - Required files/directories present
# - No source maps (.map files)
# - Text domain correctness for WordPress.org packages
# - File counts and sizes
#
# Usage:
#   ./bin/review-zips.sh                          # Review all ZIPs
#   ./bin/review-zips.sh --version 1.1.5          # Specify expected version
#   ./bin/review-zips.sh --dir build              # Specify build directory
#   ./bin/review-zips.sh --skip-wporg             # Skip WordPress.org packages
#   ./bin/review-zips.sh --verbose                # Show detailed file lists
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Default values
VERSION=""
BUILD_DIR="build"
SKIP_WPORG=false
VERBOSE=false

# Counters
TOTAL_CHECKS=0
PASSED_CHECKS=0
FAILED_CHECKS=0
WARNED_CHECKS=0
SKIPPED_ZIPS=0

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --version)
            VERSION="$2"
            shift 2
            ;;
        --dir)
            BUILD_DIR="$2"
            shift 2
            ;;
        --skip-wporg)
            SKIP_WPORG=true
            shift
            ;;
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Review and validate all generated plugin ZIP files."
            echo ""
            echo "Options:"
            echo "  --version X.Y.Z   Expected version number (auto-detected if omitted)"
            echo "  --dir PATH        Build directory (default: build)"
            echo "  --skip-wporg      Skip WordPress.org package checks"
            echo "  --verbose, -v     Show detailed file lists"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                           # Review all ZIPs in build/"
            echo "  $0 --version 1.1.5           # Verify version 1.1.5"
            echo "  $0 --dir /path/to/build      # Custom build directory"
            echo "  $0 --skip-wporg --verbose    # Detailed review without WordPress.org packages"
            exit 0
            ;;
        *)
            shift
            ;;
    esac
done

# Auto-detect version if not specified
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

# Auto-detect core version
CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")

# ============================================================================
# Helper functions
# ============================================================================

pass() {
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    PASSED_CHECKS=$((PASSED_CHECKS + 1))
    echo "   ✅ $1"
}

fail() {
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    FAILED_CHECKS=$((FAILED_CHECKS + 1))
    echo "   ❌ $1"
}

warn() {
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    WARNED_CHECKS=$((WARNED_CHECKS + 1))
    echo "   ⚠️  $1"
}

info() {
    echo "   ℹ️  $1"
}

# Check if a pattern exists in the extracted ZIP
# Returns 0 if found, 1 if not found
has_files() {
    local dir="$1"
    local pattern="$2"
    find "$dir" -path "$pattern" -type f 2>/dev/null | head -1 | grep -q .
}

has_dirs() {
    local dir="$1"
    local pattern="$2"
    find "$dir" -path "$pattern" -type d 2>/dev/null | head -1 | grep -q .
}

# ============================================================================
# Review a single ZIP file
# ============================================================================
review_zip() {
    local ZIP_PATH="$1"
    local ZIP_TYPE="$2"       # base, pro, combined, core, wporg-base, wporg-pro, wporg-combined, wporg-core
    local EXPECTED_VER="$3"
    local TEMP_DIR="/tmp/review-zip-$$-$(date +%N)"

    local ZIP_NAME
    ZIP_NAME=$(basename "$ZIP_PATH")
    local ZIP_SIZE
    ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📦 $ZIP_NAME ($ZIP_SIZE)"
    echo "   Type: $ZIP_TYPE"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    # --- Check 1: ZIP integrity ---
    if unzip -t "$ZIP_PATH" > /dev/null 2>&1; then
        pass "ZIP integrity OK"
    else
        fail "ZIP is corrupt or invalid"
        return
    fi

    # Extract to temp dir
    mkdir -p "$TEMP_DIR"
    unzip -q "$ZIP_PATH" -d "$TEMP_DIR"

    # Find the extracted root directory.
    # Standard packages have a single top-level folder (e.g., mcp-ai-wpoos-base/).
    # WordPress.org packages extract files directly (no subfolder) because the
    # build script zips from inside the directory (zip -r ... .).
    # We detect this by checking for PHP files at the top level of the temp dir.
    local EXTRACT_ROOT
    local TOP_PHP_COUNT
    TOP_PHP_COUNT=$(find "$TEMP_DIR" -maxdepth 1 -name "*.php" -type f 2>/dev/null | wc -l)

    if [ "$TOP_PHP_COUNT" -gt 0 ]; then
        # PHP files at top level — flat layout (WordPress.org package)
        EXTRACT_ROOT="$TEMP_DIR"
    else
        # No PHP files at top — look for single subfolder (standard package)
        EXTRACT_ROOT=$(find "$TEMP_DIR" -maxdepth 1 -type d ! -path "$TEMP_DIR" | head -1)
        if [ -z "$EXTRACT_ROOT" ]; then
            EXTRACT_ROOT="$TEMP_DIR"
        fi
    fi

    local FILE_COUNT
    FILE_COUNT=$(find "$EXTRACT_ROOT" -type f | wc -l)
    local DIR_COUNT
    DIR_COUNT=$(find "$EXTRACT_ROOT" -type d | wc -l)
    info "Files: $FILE_COUNT | Directories: $DIR_COUNT"

    if [ "$VERBOSE" = true ]; then
        echo "   --- Directory structure (top 3 levels) ---"
        find "$EXTRACT_ROOT" -maxdepth 3 -type d | sed "s|$TEMP_DIR/||" | sort | head -60 | while read -r d; do
            echo "      $d"
        done
        echo "   ---"
    fi

    # --- Check 2: No dev files leaked ---
    local DEV_LEAK=false

    if has_dirs "$EXTRACT_ROOT" "*/.git"; then
        fail "Dev leak: .git directory found"
        DEV_LEAK=true
    fi

    if has_dirs "$EXTRACT_ROOT" "*/.github"; then
        fail "Dev leak: .github directory found"
        DEV_LEAK=true
    fi

    if has_dirs "$EXTRACT_ROOT" "*/node_modules"; then
        fail "Dev leak: node_modules directory found"
        DEV_LEAK=true
    fi

    if has_dirs "$EXTRACT_ROOT" "*/tests"; then
        fail "Dev leak: tests directory found"
        DEV_LEAK=true
    fi

    if has_dirs "$EXTRACT_ROOT" "*/coverage"; then
        fail "Dev leak: coverage directory found"
        DEV_LEAK=true
    fi

    if has_files "$EXTRACT_ROOT" "*/phpunit.xml.dist"; then
        # Only flag if not deeply nested in vendor packages
        local PHPUNIT_TOP
        PHPUNIT_TOP=$(find "$EXTRACT_ROOT" -name "phpunit.xml.dist" -type f -not -path "*/vendor/*" 2>/dev/null | wc -l)
        local PHPUNIT_VENDOR
        PHPUNIT_VENDOR=$(find "$EXTRACT_ROOT" -name "phpunit.xml.dist" -type f -path "*/vendor/*" 2>/dev/null | wc -l)
        if [ "$PHPUNIT_TOP" -gt 0 ]; then
            fail "Dev leak: phpunit.xml.dist found outside vendor"
            DEV_LEAK=true
        elif [ "$PHPUNIT_VENDOR" -gt 0 ]; then
            warn "phpunit.xml.dist in $PHPUNIT_VENDOR vendor package(s)"
        fi
    fi

    if has_files "$EXTRACT_ROOT" "*/.eslintrc.json"; then
        fail "Dev leak: .eslintrc.json found"
        DEV_LEAK=true
    fi

    if has_files "$EXTRACT_ROOT" "*/composer.lock"; then
        fail "Dev leak: composer.lock found"
        DEV_LEAK=true
    fi

    if has_files "$EXTRACT_ROOT" "*/package-lock.json"; then
        fail "Dev leak: package-lock.json found"
        DEV_LEAK=true
    fi

    if has_files "$EXTRACT_ROOT" "*/package.json"; then
        fail "Dev leak: package.json found"
        DEV_LEAK=true
    fi

    if has_dirs "$EXTRACT_ROOT" "*/docs"; then
        # Flag docs directory at plugin root or one level deep (not inside vendor)
        local DOCS_TOP
        DOCS_TOP=$(find "$EXTRACT_ROOT" -maxdepth 2 -type d -name "docs" -not -path "*/vendor/*" -not -path "*/assets/vendor/*" 2>/dev/null | wc -l)
        if [ "$DOCS_TOP" -gt 0 ]; then
            fail "Dev leak: docs directory found at plugin level"
            DEV_LEAK=true
        fi
    fi

    if [ "$DEV_LEAK" = false ]; then
        pass "No dev files leaked"
    fi

    # --- Check 3: No JS/CSS source maps ---
    local MAP_COUNT
    MAP_COUNT=$(find "$EXTRACT_ROOT" \( -name "*.js.map" -o -name "*.css.map" \) -type f 2>/dev/null | wc -l)
    if [ "$MAP_COUNT" -gt 0 ]; then
        fail "Source maps found: $MAP_COUNT .js.map/.css.map files"
        if [ "$VERBOSE" = true ]; then
            find "$EXTRACT_ROOT" \( -name "*.js.map" -o -name "*.css.map" \) -type f | sed "s|$TEMP_DIR/||" | head -10 | while read -r f; do
                echo "      → $f"
            done
        fi
    else
        pass "No JS/CSS source maps"
    fi

    # --- Type-specific checks ---
    case "$ZIP_TYPE" in
        base)
            review_base "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        pro)
            review_pro "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        combined)
            review_combined "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        core)
            review_core "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        wporg-base)
            review_wporg_base "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        wporg-pro)
            review_wporg_pro "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        wporg-combined)
            review_wporg_combined "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
        wporg-core)
            review_wporg_core "$EXTRACT_ROOT" "$EXPECTED_VER"
            ;;
    esac

    # Cleanup
    rm -rf "$TEMP_DIR"
}

# ============================================================================
# Base version checks
# ============================================================================
review_base() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Plugin entry point
    if [ -f "$DIR/mcp-ai-wpoos-base.php" ]; then
        pass "Entry point: mcp-ai-wpoos-base.php exists"

        # Check plugin header
        if grep -q "Plugin Name:" "$DIR/mcp-ai-wpoos-base.php"; then
            pass "Plugin header present in mcp-ai-wpoos-base.php"
        else
            fail "Plugin header MISSING in mcp-ai-wpoos-base.php"
        fi

        # Check version in header
        local HEADER_VER
        HEADER_VER=$(grep -E "^\s*\*\s*Version:" "$DIR/mcp-ai-wpoos-base.php" | sed 's/.*Version:\s*//' | tr -d '[:space:]')
        if [ "$HEADER_VER" = "$EXPECTED_VER" ]; then
            pass "Version in header: $HEADER_VER"
        else
            fail "Version mismatch: header=$HEADER_VER expected=$EXPECTED_VER"
        fi
    else
        fail "Entry point MISSING: mcp-ai-wpoos-base.php"
    fi

    # mcp-ai-wpoos.php should exist but NOT have a plugin header (prevents duplicate detection)
    if [ -f "$DIR/mcp-ai-wpoos.php" ]; then
        if grep -q "Plugin Name:" "$DIR/mcp-ai-wpoos.php"; then
            fail "mcp-ai-wpoos.php should NOT have Plugin Name header in base version"
        else
            pass "mcp-ai-wpoos.php has no plugin header (correct for base)"
        fi
    else
        fail "mcp-ai-wpoos.php MISSING (core logic file)"
    fi

    # Required directories
    check_required_dirs "$DIR" "base"

    # No Pro addon
    if [ -d "$DIR/addons/pro" ]; then
        fail "Pro addon directory should not be in base version"
    else
        pass "No Pro addon (correct for base)"
    fi

    # No core directory
    if [ -d "$DIR/core" ]; then
        fail "Core directory should not be in base version"
    else
        pass "No core directory (correct for base)"
    fi

    # Check vendor autoloader
    check_vendor_autoloader "$DIR"

    # Check readme.txt
    if [ -f "$DIR/readme.txt" ]; then
        pass "readme.txt present"
    else
        warn "readme.txt missing"
    fi
}

# ============================================================================
# Pro addon checks
# ============================================================================
review_pro() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Plugin entry point
    if [ -f "$DIR/mcp-ai-wpoos-pro.php" ]; then
        pass "Entry point: mcp-ai-wpoos-pro.php exists"

        # Check plugin header
        if grep -q "Plugin Name:" "$DIR/mcp-ai-wpoos-pro.php"; then
            pass "Plugin header present in mcp-ai-wpoos-pro.php"
        else
            fail "Plugin header MISSING in mcp-ai-wpoos-pro.php"
        fi

        # Check version in header
        local HEADER_VER
        HEADER_VER=$(grep -E "^\s*\*\s*Version:" "$DIR/mcp-ai-wpoos-pro.php" | sed 's/.*Version:\s*//' | tr -d '[:space:]')
        if [ "$HEADER_VER" = "$EXPECTED_VER" ]; then
            pass "Version in header: $HEADER_VER"
        else
            fail "Version mismatch: header=$HEADER_VER expected=$EXPECTED_VER"
        fi
    else
        fail "Entry point MISSING: mcp-ai-wpoos-pro.php"
    fi

    # Required directories for Pro
    if [ -d "$DIR/includes" ]; then
        pass "includes/ directory present"
    else
        fail "includes/ directory MISSING"
    fi

    if [ -d "$DIR/assets" ]; then
        pass "assets/ directory present"
    else
        fail "assets/ directory MISSING"
    fi

    # Check for vendor packages (Pro has its own)
    if [ -d "$DIR/assets/vendor" ]; then
        local VENDOR_PKG_COUNT
        VENDOR_PKG_COUNT=$(find "$DIR/assets/vendor" -maxdepth 1 -type d | wc -l)
        VENDOR_PKG_COUNT=$((VENDOR_PKG_COUNT - 1))
        if [ "$VENDOR_PKG_COUNT" -gt 0 ]; then
            pass "NPM vendor packages: $VENDOR_PKG_COUNT packages"
        else
            warn "assets/vendor/ is empty"
        fi
    else
        warn "assets/vendor/ not found (NPM packages)"
    fi

    # CDN packages should be excluded
    local CDN_LEAK=false
    for CDN_PKG in "facebook-nodejs-business-sdk" "canvas" "chart.js" "katex" "d3" "axios" "mathjs" "prettier"; do
        if [ -d "$DIR/assets/vendor/$CDN_PKG" ]; then
            fail "CDN package should be excluded: $CDN_PKG"
            CDN_LEAK=true
        fi
    done
    if [ "$CDN_LEAK" = false ]; then
        pass "CDN/excluded packages correctly omitted"
    fi
}

# ============================================================================
# Combined version checks
# ============================================================================
review_combined() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Plugin entry point (combined uses mcp-ai-wpoos.php directly)
    if [ -f "$DIR/mcp-ai-wpoos.php" ]; then
        pass "Entry point: mcp-ai-wpoos.php exists"

        # In combined version, this IS the main plugin file with header
        if grep -q "Plugin Name:" "$DIR/mcp-ai-wpoos.php"; then
            pass "Plugin header present in mcp-ai-wpoos.php"
        else
            # Combined version might have header removed by build
            warn "Plugin header not found in mcp-ai-wpoos.php"
        fi
    else
        fail "Entry point MISSING: mcp-ai-wpoos.php"
    fi

    # Should NOT have mcp-ai-wpoos-base.php (excluded from combined)
    if [ -f "$DIR/mcp-ai-wpoos-base.php" ]; then
        fail "mcp-ai-wpoos-base.php should NOT be in combined version"
    else
        pass "No mcp-ai-wpoos-base.php (correct for combined)"
    fi

    # Required directories
    check_required_dirs "$DIR" "combined"

    # Pro addon should be included
    if [ -d "$DIR/addons/pro" ]; then
        pass "Pro addon included"
        if [ -f "$DIR/addons/pro/mcp-ai-wpoos-pro.php" ]; then
            pass "Pro entry point present"
        else
            fail "Pro entry point MISSING: addons/pro/mcp-ai-wpoos-pro.php"
        fi
    else
        fail "Pro addon MISSING from combined version"
    fi

    # Check vendor autoloader
    check_vendor_autoloader "$DIR"
}

# ============================================================================
# Core version checks
# ============================================================================
review_core() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Plugin entry point
    if [ -f "$DIR/mcp-ai-wpoos-core.php" ]; then
        pass "Entry point: mcp-ai-wpoos-core.php exists"

        # Check plugin header
        if grep -q "Plugin Name:" "$DIR/mcp-ai-wpoos-core.php"; then
            pass "Plugin header present in mcp-ai-wpoos-core.php"
        else
            fail "Plugin header MISSING in mcp-ai-wpoos-core.php"
        fi

        # Check version
        local HEADER_VER
        HEADER_VER=$(grep -E "^\s*\*\s*Version:" "$DIR/mcp-ai-wpoos-core.php" | sed 's/.*Version:\s*//' | tr -d '[:space:]')
        if [ "$HEADER_VER" = "$EXPECTED_VER" ]; then
            pass "Version in header: $HEADER_VER"
        else
            fail "Version mismatch: header=$HEADER_VER expected=$EXPECTED_VER"
        fi
    else
        fail "Entry point MISSING: mcp-ai-wpoos-core.php"
    fi

    # Core should have includes/
    if [ -d "$DIR/includes" ]; then
        pass "includes/ directory present"
    else
        fail "includes/ directory MISSING"
    fi

    # Core should have readme.txt
    if [ -f "$DIR/readme.txt" ]; then
        pass "readme.txt present"
    else
        warn "readme.txt missing"
    fi

    # Core should NOT have addons
    if [ -d "$DIR/addons" ]; then
        fail "addons/ directory should not be in core version"
    else
        pass "No addons directory (correct for core)"
    fi
}

# ============================================================================
# WordPress.org package checks
# ============================================================================
review_wporg_base() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Check transformed entry point
    if [ -f "$DIR/nvdigital-open-operator-system-oos.php" ]; then
        pass "WP.org entry point: nvdigital-open-operator-system-oos.php exists"
    else
        fail "WP.org entry point MISSING: nvdigital-open-operator-system-oos.php"
    fi

    # Check text domain transformation
    check_text_domain_transform "$DIR" "base"

    # Check translation file renamed
    if [ -f "$DIR/languages/nvdigital-open-operator-system-oos.pot" ]; then
        pass "Translation file renamed correctly"
    elif [ -f "$DIR/languages/mcp-ai-wpoos-base.pot" ] || [ -f "$DIR/languages/mcp-ai-wpoos.pot" ]; then
        fail "Translation file NOT renamed (still uses old text domain)"
    else
        warn "No .pot translation file found"
    fi
}

review_wporg_pro() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Check transformed entry point
    if [ -f "$DIR/nvdigital-open-operator-system-oos-pro.php" ]; then
        pass "WP.org entry point: nvdigital-open-operator-system-oos-pro.php exists"
    else
        fail "WP.org entry point MISSING: nvdigital-open-operator-system-oos-pro.php"
    fi

    # Check text domain transformation
    check_text_domain_transform "$DIR" "pro"
}

review_wporg_combined() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Check transformed entry point
    if [ -f "$DIR/nvdigital-open-operator-system-oos.php" ]; then
        pass "WP.org entry point: nvdigital-open-operator-system-oos.php exists"
    else
        fail "WP.org entry point MISSING: nvdigital-open-operator-system-oos.php"
    fi

    # Check text domain transformation
    check_text_domain_transform "$DIR" "combined"
}

review_wporg_core() {
    local DIR="$1"
    local EXPECTED_VER="$2"

    # Check for a valid entry point (core may keep original or have transformed name)
    if [ -f "$DIR/nvdigital-open-operator-system-oos-core.php" ] || [ -f "$DIR/mcp-ai-wpoos-core.php" ]; then
        pass "WP.org core entry point present"
    else
        fail "WP.org core entry point MISSING"
    fi

    # Check text domain transformation
    check_text_domain_transform "$DIR" "core"
}

# ============================================================================
# Shared check functions
# ============================================================================
check_required_dirs() {
    local DIR="$1"
    local TYPE="$2"

    if [ -d "$DIR/includes" ]; then
        pass "includes/ directory present"
    else
        fail "includes/ directory MISSING"
    fi

    if [ -d "$DIR/assets" ]; then
        pass "assets/ directory present"
    else
        fail "assets/ directory MISSING"
    fi

    if [ -d "$DIR/assets/js" ]; then
        pass "assets/js/ directory present"
    else
        fail "assets/js/ directory MISSING"
    fi

    if [ -d "$DIR/assets/css" ]; then
        pass "assets/css/ directory present"
    else
        fail "assets/css/ directory MISSING"
    fi

    if [ -d "$DIR/languages" ]; then
        pass "languages/ directory present"
    else
        warn "languages/ directory missing"
    fi
}

check_vendor_autoloader() {
    local DIR="$1"

    if [ -d "$DIR/vendor" ]; then
        pass "vendor/ directory present"
        if [ -f "$DIR/vendor/autoload.php" ]; then
            pass "vendor/autoload.php present"
        else
            fail "vendor/autoload.php MISSING"
        fi
        if [ -d "$DIR/vendor/composer" ]; then
            pass "vendor/composer/ directory present"
        else
            fail "vendor/composer/ directory MISSING"
        fi
    else
        fail "vendor/ directory MISSING"
    fi
}

check_text_domain_transform() {
    local DIR="$1"
    # $2 (type) available for future use

    # Check that old text domains are gone from PHP/JS files
    local OLD_DOMAIN_COUNT
    OLD_DOMAIN_COUNT=$(grep -rlE "('mcp-ai-wpoos'|\"mcp-ai-wpoos\"|'mcp-ai-wpoos-base'|\"mcp-ai-wpoos-base\"|'mcp-ai-wpoos-pro'|\"mcp-ai-wpoos-pro\")" "$DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)

    if [ "$OLD_DOMAIN_COUNT" -eq 0 ]; then
        pass "Text domain transformation complete (no old domains)"
    elif [ "$OLD_DOMAIN_COUNT" -le 3 ]; then
        warn "Text domain: $OLD_DOMAIN_COUNT files still have old domain references"
        if [ "$VERBOSE" = true ]; then
            grep -rlE "('mcp-ai-wpoos'|\"mcp-ai-wpoos\")" "$DIR" --include="*.php" --include="*.js" 2>/dev/null | head -5 | while read -r f; do
                echo "      → $(echo "$f" | sed "s|$DIR/||")"
            done
        fi
    else
        fail "Text domain NOT transformed: $OLD_DOMAIN_COUNT files still have old domains"
        if [ "$VERBOSE" = true ]; then
            grep -rlE "('mcp-ai-wpoos'|\"mcp-ai-wpoos\")" "$DIR" --include="*.php" --include="*.js" 2>/dev/null | head -10 | while read -r f; do
                echo "      → $(echo "$f" | sed "s|$DIR/||")"
            done
        fi
    fi

    # Check that new text domains are present
    local NEW_DOMAIN_COUNT
    NEW_DOMAIN_COUNT=$(grep -rlE "('nvdigital-open-operator-system-oos'|\"nvdigital-open-operator-system-oos\")" "$DIR" --include="*.php" --include="*.js" 2>/dev/null | wc -l || echo 0)

    if [ "$NEW_DOMAIN_COUNT" -gt 0 ]; then
        pass "New text domain found in $NEW_DOMAIN_COUNT files"
    else
        warn "New text domain (nvdigital-open-operator-system-oos) not found in any files"
    fi
}

# ============================================================================
# Main
# ============================================================================
echo "=========================================="
echo "🔍 Reviewing Plugin ZIPs"
echo "=========================================="
echo ""
echo "Build directory: $BUILD_DIR"
echo "Expected version: $VERSION"
echo "Core version: $CORE_VERSION"
echo ""

# Check build directory exists
if [ ! -d "$BUILD_DIR" ]; then
    echo "❌ Build directory not found: $BUILD_DIR"
    echo ""
    echo "Run the build first:"
    echo "  ./bin/rebuild-all-zips.sh"
    exit 1
fi

# List all ZIPs found
echo "ZIP files found:"
ZIP_COUNT=$(find "$BUILD_DIR" -maxdepth 1 -name "*.zip" -type f 2>/dev/null | wc -l)
if [ "$ZIP_COUNT" -eq 0 ]; then
    echo "   (none)"
    echo ""
    echo "❌ No ZIP files found in $BUILD_DIR"
    echo ""
    echo "Run the build first:"
    echo "  ./bin/rebuild-all-zips.sh"
    exit 1
fi

find "$BUILD_DIR" -maxdepth 1 -name "*.zip" -type f | sort | while read -r z; do
    echo "   $(basename "$z") ($(du -h "$z" | cut -f1))"
done
echo ""

# ============================================================================
# Review standard packages
# ============================================================================

# Base version
BASE_ZIP="$BUILD_DIR/mcp-ai-wpoos-base-${VERSION}.zip"
if [ -f "$BASE_ZIP" ]; then
    review_zip "$BASE_ZIP" "base" "$VERSION"
else
    echo ""
    echo "⏭️  Skipped: mcp-ai-wpoos-base-${VERSION}.zip (not found)"
    SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
fi

# Pro add-on
PRO_ZIP="$BUILD_DIR/mcp-ai-wpoos-pro-${VERSION}.zip"
if [ -f "$PRO_ZIP" ]; then
    review_zip "$PRO_ZIP" "pro" "$VERSION"
else
    echo ""
    echo "⏭️  Skipped: mcp-ai-wpoos-pro-${VERSION}.zip (not found)"
    SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
fi

# Combined (base + pro)
COMBINED_ZIP="$BUILD_DIR/mcp-ai-wpoos-${VERSION}.zip"
if [ -f "$COMBINED_ZIP" ]; then
    review_zip "$COMBINED_ZIP" "combined" "$VERSION"
else
    echo ""
    echo "⏭️  Skipped: mcp-ai-wpoos-${VERSION}.zip (not found)"
    SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
fi

# Core plugin
CORE_ZIP="$BUILD_DIR/mcp-ai-wpoos-core-${CORE_VERSION}.zip"
if [ -f "$CORE_ZIP" ]; then
    review_zip "$CORE_ZIP" "core" "$CORE_VERSION"
else
    echo ""
    echo "⏭️  Skipped: mcp-ai-wpoos-core-${CORE_VERSION}.zip (not found)"
    SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
fi

# ============================================================================
# Review WordPress.org packages
# ============================================================================
if [ "$SKIP_WPORG" = false ]; then

    WPORG_BASE_ZIP="$BUILD_DIR/nvdigital-open-operator-system-oos-${VERSION}.zip"
    if [ -f "$WPORG_BASE_ZIP" ]; then
        review_zip "$WPORG_BASE_ZIP" "wporg-base" "$VERSION"
    else
        echo ""
        echo "⏭️  Skipped: nvdigital-open-operator-system-oos-${VERSION}.zip (not found)"
        SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
    fi

    WPORG_PRO_ZIP="$BUILD_DIR/nvdigital-open-operator-system-oos-pro-${VERSION}.zip"
    if [ -f "$WPORG_PRO_ZIP" ]; then
        review_zip "$WPORG_PRO_ZIP" "wporg-pro" "$VERSION"
    else
        echo ""
        echo "⏭️  Skipped: nvdigital-open-operator-system-oos-pro-${VERSION}.zip (not found)"
        SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
    fi

    WPORG_COMBINED_ZIP="$BUILD_DIR/nvdigital-open-operator-system-oos-complete-${VERSION}.zip"
    if [ -f "$WPORG_COMBINED_ZIP" ]; then
        review_zip "$WPORG_COMBINED_ZIP" "wporg-combined" "$VERSION"
    else
        echo ""
        echo "⏭️  Skipped: nvdigital-open-operator-system-oos-complete-${VERSION}.zip (not found)"
        SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
    fi

    WPORG_CORE_ZIP="$BUILD_DIR/nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip"
    if [ -f "$WPORG_CORE_ZIP" ]; then
        review_zip "$WPORG_CORE_ZIP" "wporg-core" "$CORE_VERSION"
    else
        echo ""
        echo "⏭️  Skipped: nvdigital-open-operator-system-oos-core-${CORE_VERSION}.zip (not found)"
        SKIPPED_ZIPS=$((SKIPPED_ZIPS + 1))
    fi

fi

# ============================================================================
# Summary
# ============================================================================
echo ""
echo "=========================================="
echo "📊 Review Summary"
echo "=========================================="
echo ""
echo "   Total checks: $TOTAL_CHECKS"
echo "   ✅ Passed:    $PASSED_CHECKS"
echo "   ❌ Failed:    $FAILED_CHECKS"
echo "   ⚠️  Warnings:  $WARNED_CHECKS"
if [ "$SKIPPED_ZIPS" -gt 0 ]; then
    echo "   ⏭️  Skipped ZIPs: $SKIPPED_ZIPS"
fi
echo ""

if [ "$FAILED_CHECKS" -gt 0 ]; then
    echo "❌ REVIEW FAILED — $FAILED_CHECKS issue(s) found"
    echo "   Fix the issues above and rebuild the ZIPs."
    exit 1
elif [ "$WARNED_CHECKS" -gt 0 ]; then
    echo "⚠️  REVIEW PASSED WITH WARNINGS — $WARNED_CHECKS warning(s)"
    echo "   ZIPs are usable but review warnings above."
    exit 0
else
    echo "✅ ALL CHECKS PASSED"
    exit 0
fi
