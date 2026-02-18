#!/bin/bash
#
# Build Plugin ZIP
#
# Creates production-ready ZIP files of the oOS plugin.
# Supports building base, pro, core-only, or combined (base + pro) versions.
#
# Usage:
#   ./bin/build-plugin-zip.sh                    # Builds all versions (base, pro, combined)
#   ./bin/build-plugin-zip.sh --base             # Builds base version only
#   ./bin/build-plugin-zip.sh --pro              # Builds pro add-on only
#   ./bin/build-plugin-zip.sh --combined         # Builds base + pro combined
#   ./bin/build-plugin-zip.sh --core-only        # Builds core plugin only
#   ./bin/build-plugin-zip.sh --version 1.0.0    # Specify version number
#
# Output:
#   build/nvdigital-open-operator-system-oos-X.Y.Z.zip       (standard/WordPress.org version)
#   build/nvdigital-open-operator-system-oos-pro-X.Y.Z.zip   (pro add-on - requires base)
#   build/nvdigital-open-operator-system-oos-complete-X.Y.Z.zip (base + pro combined)
#   build/nvdigital-open-operator-system-oos-core-X.Y.Z.zip  (lightweight core plugin - 4 basic tools)
#
# Requirements:
#   - Node.js and npm (for asset building)
#   - Composer (for PHP dependencies)
#   - zip command
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

# Default values
BUILD_BASE=false
BUILD_PRO=false
BUILD_COMBINED=false
BUILD_CORE_ONLY=false
VERSION=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --base|--core)
            BUILD_BASE=true
            shift
            ;;
        --pro)
            BUILD_PRO=true
            shift
            ;;
        --combined|--full)
            BUILD_COMBINED=true
            shift
            ;;
        --core-only)
            BUILD_CORE_ONLY=true
            shift
            ;;
        --version)
            VERSION="$2"
            shift 2
            ;;
        --all)
            BUILD_BASE=true
            BUILD_PRO=true
            BUILD_COMBINED=true
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --base, --core    Build base version (free, WordPress.org compatible)"
            echo "  --pro             Build pro add-on (requires base plugin)"
            echo "  --combined        Build base + pro combined package"
            echo "  --core-only       Build core plugin only (lightweight, 4 basic tools)"
            echo "  --all             Build all main versions (base, pro, combined)"
            echo "  --version X.Y.Z   Specify version number"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                       # Build base, pro, and combined"
            echo "  $0 --base                # Build only base version"
            echo "  $0 --pro                 # Build only pro add-on"
            echo "  $0 --combined            # Build base + pro combined"
            echo "  $0 --core-only           # Build only core plugin"
            echo "  $0 --version 1.0.0       # Specify version"
            exit 0
            ;;
        *)
            # Assume it's a version number for backwards compatibility
            VERSION="$1"
            shift
            ;;
    esac
done

# If no build type specified, build all versions (base, pro, combined, and core-only)
if [ "$BUILD_BASE" = false ] && [ "$BUILD_PRO" = false ] && [ "$BUILD_COMBINED" = false ] && [ "$BUILD_CORE_ONLY" = false ]; then
    BUILD_BASE=true
    BUILD_PRO=true
    BUILD_COMBINED=true
    BUILD_CORE_ONLY=true
fi

# Get version if not specified
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

echo "=========================================="
echo "Building oOS Plugin ZIP v${VERSION}"
echo "=========================================="
echo ""
echo "Build targets:"
[ "$BUILD_BASE" = true ] && echo "  ✓ Base version (mcp-ai-wpoos-base) - standalone"
[ "$BUILD_PRO" = true ] && echo "  ✓ Pro add-on (mcp-ai-wpoos-pro)"
[ "$BUILD_COMBINED" = true ] && echo "  ✓ Base + Pro combined (mcp-ai-wpoos)"
[ "$BUILD_CORE_ONLY" = true ] && echo "  ✓ Core plugin (mcp-ai-wpoos-core) - lightweight"
echo ""

# Check requirements
echo "Checking requirements..."

if ! command -v npm &> /dev/null; then
    echo "❌ Error: npm is required but not installed."
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo "❌ Error: composer is required but not installed."
    exit 1
fi

if ! command -v zip &> /dev/null; then
    echo "❌ Error: zip is required but not installed."
    exit 1
fi

echo "✅ All requirements met"
echo ""

# Step 1: Install and build frontend assets
echo "Step 1: Building frontend assets..."
npm ci --silent 2>/dev/null || npm install --silent
npm run build
echo "✅ Frontend assets built"
echo ""

# Step 2: Install production Composer dependencies
echo "Step 2: Installing production PHP dependencies..."
composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --quiet
echo "✅ Production dependencies installed (with optimized classmap autoloader)"
echo ""

# Clean build directory
rm -rf build
mkdir -p build

# ============================================================================
# Build Base Version (Standalone, fully functional without Pro)
# ============================================================================
if [ "$BUILD_BASE" = true ]; then
    echo "Step 3a: Building Base version (standalone)..."
    
    BASE_SLUG="mcp-ai-wpoos-base"
    mkdir -p "build/${BASE_SLUG}"
    
    # Copy full plugin files EXCEPT pro addons
    # This creates a fully functional standalone plugin
    rsync -av --quiet . "build/${BASE_SLUG}/" \
        --include 'bin/' \
        --include 'bin/vectorize-image.js' \
        --exclude 'bin/*' \
        --exclude '.git' \
        --exclude '.git-branch-info' \
        --exclude '.github' \
        --exclude '.wordpress-org' \
        --exclude '.codex' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'coverage' \
        --exclude 'build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.lock' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude 'core' \
        --exclude 'shared' \
        --exclude 'ARCHITECTURE.md' \
        --exclude 'RELEASE_CHECKLIST.md' \
        --exclude 'CONTRIBUTING.md' \
        --exclude 'SECURITY.md' \
        --exclude 'BUILD.md' \
        --exclude 'INCOMPLETE-FEATURES-REVIEW.md' \
        --exclude 'INCOMPLETE-FEATURES-STATUS-SUMMARY.md' \
        --exclude 'PERFORMANCE_BUTTONS_FIX.md' \
        --exclude 'VENDOR-EXEC-USAGE.md' \
        --exclude 'WORDPRESS_ORG_SUBMISSION_GUIDE.md' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'addons/pro' \
        --exclude 'assets/examples' \
        --exclude 'assets/csv-templates' \
        --exclude 'examples' \
        --exclude '*.map' \
        --exclude 'vendor/*/Test' \
        --exclude 'vendor/*/Tests' \
        --exclude 'vendor/*/tests' \
        --exclude 'vendor/*/*/Test' \
        --exclude 'vendor/*/*/Tests' \
        --exclude 'vendor/*/*/tests' \
        --exclude 'vendor/*/.git' \
        --exclude 'vendor/*/*/.git' \
        --exclude 'vendor/*/*/*/.git' \
        --exclude 'vendor/symfony/*/Resources/translations' \
        --exclude 'vendor/*/README.md' \
        --exclude 'vendor/*/README' \
        --exclude 'vendor/*/CHANGELOG.md' \
        --exclude 'vendor/*/CHANGELOG' \
        --exclude 'vendor/*/CONTRIBUTING.md' \
        --exclude 'vendor/*/UPGRADE.md' \
        --exclude 'vendor/*/*/README.md' \
        --exclude 'vendor/*/*/README' \
        --exclude 'vendor/*/*/CHANGELOG.md' \
        --exclude 'vendor/*/*/CHANGELOG' \
        --exclude 'vendor/*/*/CONTRIBUTING.md' \
        --exclude 'vendor/*/*/UPGRADE.md' \
        --exclude 'vendor/*/.gitignore' \
        --exclude 'vendor/*/*/.gitignore' \
        --exclude 'vendor/*/.gitattributes' \
        --exclude 'vendor/*/*/.gitattributes' \
        --exclude 'vendor/*/Makefile' \
        --exclude 'vendor/*/*/Makefile' \
        --exclude 'vendor/*/.travis.yml' \
        --exclude 'vendor/*/*/.travis.yml' \
        --exclude 'vendor/*/.circleci' \
        --exclude 'vendor/*/*/.circleci' \
        --exclude 'vendor/*/phpunit.xml' \
        --exclude 'vendor/*/*/phpunit.xml' \
        --exclude 'vendor/*/phpunit.xml.dist' \
        --exclude 'vendor/*/*/phpunit.xml.dist' \
        --exclude 'vendor/*/phpstan.neon' \
        --exclude 'vendor/*/*/phpstan.neon' \
        --exclude 'vendor/*/phpstan.neon.dist' \
        --exclude 'vendor/*/*/phpstan.neon.dist' \
        --exclude 'vendor/*/phpstan-baseline.neon' \
        --exclude 'vendor/*/*/phpstan-baseline.neon' \
        --exclude 'vendor/*/psalm.xml' \
        --exclude 'vendor/*/*/psalm.xml' \
        --exclude 'vendor/*/psalm.baseline.xml' \
        --exclude 'vendor/*/*/psalm.baseline.xml' \
        --exclude 'vendor/*/.php-cs-fixer.php' \
        --exclude 'vendor/*/*/.php-cs-fixer.php' \
        --exclude 'vendor/*/.php-cs-fixer.dist.php' \
        --exclude 'vendor/*/*/.php-cs-fixer.dist.php' \
        --exclude 'vendor/*/phpspec.yml' \
        --exclude 'vendor/*/*/phpspec.yml' \
        --exclude 'vendor/*/phpspec.yml.dist' \
        --exclude 'vendor/*/*/phpspec.yml.dist' \
        --exclude 'vendor/*/phpspec.ci.yml' \
        --exclude 'vendor/*/*/phpspec.ci.yml' \
        --exclude 'vendor/*/.pullapprove.yml' \
        --exclude 'vendor/*/*/.pullapprove.yml' \
        --exclude 'includes/class-wp-mcp-ai-remote-tester.php' \
        --exclude 'includes/elementor/class-wp-mcp-ai-elementor-test-*.php' \
        --exclude 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-*.php'
    
    # Note: mcp-ai-wpoos-base.php is already included via rsync above.
    # It serves as the main plugin file for the base version (matches folder name).
    
    # Keep both minified and unminified assets for flexibility
    # PHP code will automatically use minified versions in production (via get_asset_file() method)
    # and unminified versions when SCRIPT_DEBUG is enabled.
    # This provides better debugging experience while maintaining optimal production performance.
    echo "✓ Keeping both minified and unminified assets for SCRIPT_DEBUG support"
    
    # Remove README.md (readme.txt is the WordPress.org standard)
    if [ -f "build/${BASE_SLUG}/README.md" ]; then
        rm -f "build/${BASE_SLUG}/README.md"
        echo "✓ Removed README.md (readme.txt is used for WordPress.org)"
    fi
    
    # Remove plugin header from mcp-ai-wpoos.php to prevent WordPress from detecting it as a separate plugin
    # Only mcp-ai-wpoos-base.php should have the plugin header in the base version
    if [ -f "build/${BASE_SLUG}/mcp-ai-wpoos.php" ]; then
        # Replace the plugin header comment block (lines 1-24) with a regular comment
        # Use line number range for consistency and to prevent accidentally removing code
        sed -i '1,24c\
<?php\
/**\
 * WP MCP AI - Main Plugin File\
 *\
 * This file contains the core plugin functionality and is included by the\
 * main plugin entry point (mcp-ai-wpoos-base.php for base version,\
 * or mcp-ai-wpoos.php itself for the combined version).\
 *\
 * @package WP_MCP_AI\
 */' "build/${BASE_SLUG}/mcp-ai-wpoos.php"
        echo "✓ Removed plugin header from mcp-ai-wpoos.php (prevents duplicate plugin detection)"
    fi
    
    # Add plugin header to mcp-ai-wpoos-base.php for base version distribution
    # In the repository, this file doesn't have a plugin header to prevent duplicate plugin detection
    if [ -f "build/${BASE_SLUG}/mcp-ai-wpoos-base.php" ]; then
        # Replace the comment block (lines 1-17) with a full plugin header
        # Use line number range to avoid accidentally removing code after the comment
        sed -i '1,17c\
<?php\
/**\
 * Plugin Name: NV Digital Open Operator System (oOS)\
 * Plugin URI: https://nvdigitalsolutions.com/wpoos\
 * Description: AI Assistant framework with OpenAI, Gemini, and Ollama integration. Works standalone with optional third-party plugin integrations.\
 * Version: '"${VERSION}"'\
 * Requires at least: 6.0\
 * Requires PHP: 7.4\
 * Tested up to: 6.7.1\
 * Author: NV Digital Solutions\
 * Author URI: https://nvdigitalsolutions.com\
 * License: GPLv3 or later\
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html\
 * Text Domain: mcp-ai-wpoos-base\
 * Domain Path: /languages\
 * Network: true\
 *\
 * @package WP_MCP_AI\
 *\
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)\
 * This plugin is licensed under the GNU General Public License v3 or later.\
 */' "build/${BASE_SLUG}/mcp-ai-wpoos-base.php"
        echo "✓ Added plugin header to mcp-ai-wpoos-base.php for base version"
    fi
    
    # Reduce knowledge base size by keeping only 20 most common professions
    echo "Step 3a.1: Reducing knowledge base size (keeping top 20 professions)..."
    PLAYBOOKS_DIR="build/${BASE_SLUG}/includes/knowledge-base/profession-playbooks/professions"
    if [ -d "$PLAYBOOKS_DIR" ]; then
        # Keep these 20 most common professions
        KEEP_PROFESSIONS=(
            "business_consultant.txt"
            "content_writer.txt"
            "marketing_consultant.txt"
            "web_developer.txt"
            "graphic_designer.txt"
            "data_analyst.txt"
            "project_manager.txt"
            "social_media_manager.txt"
            "seo_specialist.txt"
            "customer_support.txt"
            "software_engineer.txt"
            "sales_manager.txt"
            "accountant.txt"
            "virtual_assistant.txt"
            "copywriter.txt"
            "ux_designer.txt"
            "product_manager.txt"
            "photographer.txt"
            "video_editor.txt"
            "translator.txt"
        )
        
        # Remove all profession playbooks except the ones we want to keep
        cd "$PLAYBOOKS_DIR"
        for file in *.txt; do
            KEEP=false
            for keep_file in "${KEEP_PROFESSIONS[@]}"; do
                if [ "$file" = "$keep_file" ]; then
                    KEEP=true
                    break
                fi
            done
            if [ "$KEEP" = false ]; then
                rm -f "$file"
            fi
        done
        cd "$ROOT_DIR"
        
        KEPT_COUNT=$(ls -1 "$PLAYBOOKS_DIR"/*.txt 2>/dev/null | wc -l)
        echo "✓ Kept ${KEPT_COUNT} most common profession playbooks (others will download on-demand)"
    fi
    
    # Create ZIP
    cd build
    zip -r -q "${BASE_SLUG}-${VERSION}.zip" "${BASE_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..
    
    BASE_SIZE=$(du -h "build/${BASE_SLUG}-${VERSION}.zip" | cut -f1)
    echo "✅ Base version created: build/${BASE_SLUG}-${VERSION}.zip (${BASE_SIZE})"
    echo ""
fi

# ============================================================================
# Build Pro Add-on
# ============================================================================
if [ "$BUILD_PRO" = true ]; then
    echo "Step 3b: Building Pro add-on..."
    
    PRO_SLUG="mcp-ai-wpoos-pro"
    mkdir -p "build/${PRO_SLUG}"
    
    # Note: All NPM packages (Sharp, etc.) are pre-packaged in assets/vendor/ and committed to git.
    # The copy-dependencies.js script is only used by maintainers when updating vendor packages.
    # For building the zip, we just copy the pre-packaged vendor directory.
    echo "ℹ️  Using pre-packaged NPM dependencies from assets/vendor/ (104 MB, 43 packages)"
    
    # Copy pro addon files with aggressive exclusions to reduce size
    if [ -d "addons/pro" ]; then
        echo "Step 3b.1: Copying Pro add-on files (excluding tests, docs, dev files)..."
        rsync -av --quiet addons/pro/ "build/${PRO_SLUG}/" \
            --exclude '.git' \
            --exclude '.gitignore' \
            --exclude '.vscode' \
            --exclude 'node_modules' \
            --exclude 'tests' \
            --exclude 'test' \
            --exclude 'Test' \
            --exclude 'Tests' \
            --exclude '*.zip' \
            --exclude 'assets/examples' \
            --exclude 'composer.lock' \
            --exclude 'package-lock.json' \
            --exclude '*.js.map' \
            --exclude '*.css.map' \
            --exclude 'assets/vendor/facebook-nodejs-business-sdk' \
            --exclude 'assets/vendor/canvas' \
            --exclude 'assets/vendor/puppeteer-core' \
            --exclude 'assets/vendor/chart.js' \
            --exclude 'assets/vendor/katex' \
            --exclude 'assets/vendor/d3' \
            --exclude 'assets/vendor/axios' \
            --exclude 'assets/vendor/mathjs' \
            --exclude 'assets/vendor/prettier' \
            --exclude 'vendor/*/tests' \
            --exclude 'vendor/*/test' \
            --exclude 'vendor/*/Test' \
            --exclude 'vendor/*/Tests' \
            --exclude 'vendor/*/*/tests' \
            --exclude 'vendor/*/*/test' \
            --exclude 'vendor/*/*/Test' \
            --exclude 'vendor/*/*/Tests' \
            --exclude 'vendor/*/docs' \
            --exclude 'vendor/*/doc' \
            --exclude 'vendor/*/Docs' \
            --exclude 'vendor/*/examples' \
            --exclude 'vendor/*/example' \
            --exclude 'vendor/*/*/docs' \
            --exclude 'vendor/*/*/doc' \
            --exclude 'vendor/*/*/Docs' \
            --exclude 'vendor/*/*/examples' \
            --exclude 'vendor/*/*/example' \
            --exclude 'vendor/*/README*' \
            --exclude 'vendor/*/CHANGELOG*' \
            --exclude 'vendor/*/CONTRIBUTING*' \
            --exclude 'vendor/*/LICENSE*' \
            --exclude 'vendor/*/*/README*' \
            --exclude 'vendor/*/*/CHANGELOG*' \
            --exclude 'vendor/*/*/CONTRIBUTING*' \
            --exclude 'vendor/*/*/LICENSE*' \
            --exclude 'vendor/*/.travis.yml' \
            --exclude 'vendor/*/.circleci' \
            --exclude 'vendor/*/.github' \
            --exclude 'vendor/*/*/.travis.yml' \
            --exclude 'vendor/*/*/.circleci' \
            --exclude 'vendor/*/*/.github' \
            --exclude 'vendor/*/phpunit.xml*' \
            --exclude 'vendor/*/phpstan.neon*' \
            --exclude 'vendor/*/psalm.xml*' \
            --exclude 'vendor/*/.php-cs-fixer*' \
            --exclude 'vendor/*/*/phpunit.xml*' \
            --exclude 'vendor/*/*/phpstan.neon*' \
            --exclude 'vendor/*/*/psalm.xml*' \
            --exclude 'vendor/*/*/.php-cs-fixer*' \
            --exclude 'vendor/*/Makefile' \
            --exclude 'vendor/*/*/Makefile'
        
        echo "✓ Excluded: tests (~13MB), docs (~1MB), examples (~2MB), README files (~1MB), CI configs, QA tools, source maps (~16MB), Facebook SDK (~28MB)"
        echo "✓ Excluded optional packages requiring system dependencies or large size: canvas, puppeteer-core"
        echo "✓ Excluded CDN packages: chart.js (~420KB), katex (~3.1MB), d3 (~864KB), axios (~1.6MB), mathjs (~17MB), prettier (~500KB)"
        echo "ℹ️  Note: Excluded packages are kept in git repo but not in ZIP distribution"
        echo "ℹ️  Note: CDN packages load from jsDelivr with automatic fallback"
        echo "ℹ️  Note: Optional packages (canvas, puppeteer-core) are installed via npm when needed"
        echo "ℹ️  Note: Other vendor packages (~35+ NPM packages) are included for immediate functionality after installation"
        
        # Copy examples and CSV templates from root to Pro (excluded from base)
        if [ -d "examples" ]; then
            rsync -av --quiet examples/ "build/${PRO_SLUG}/examples/" \
                --exclude '.git'
            echo "✓ Copied examples/ to Pro addon"
        fi
        
        if [ -d "assets/csv-templates" ]; then
            mkdir -p "build/${PRO_SLUG}/assets"
            rsync -av --quiet assets/csv-templates/ "build/${PRO_SLUG}/assets/csv-templates/" \
                --exclude '.git'
            echo "✓ Copied assets/csv-templates/ to Pro addon"
        fi
        
        # Add plugin header to mcp-ai-wpoos-pro.php for standalone Pro addon distribution
        # In the repository, this file doesn't have a plugin header to prevent duplicate plugin detection
        if [ -f "build/${PRO_SLUG}/mcp-ai-wpoos-pro.php" ]; then
            # Replace the comment block (lines 1-25) with a full plugin header
            # Use line number range to avoid accidentally removing constants after the comment
            sed -i '1,25c\
<?php\
/**\
 * Plugin Name: NV Digital Open Operator System Pro (oOS Pro)\
 * Plugin URI: https://github.com/nvdigitalsolutions/wp-mcp-ai\
 * Description: Professional add-on for NV Digital Open Operator System (oOS). Adds WooCommerce, JetEngine, advanced permissions, and more. Patent Pending (Application #19/410,504).\
 * Version: '"${VERSION}"'\
 * Requires at least: 6.0\
 * Requires PHP: 7.4\
 * Author: NV Digital Solutions\
 * Author URI: https://nvdigitalsolutions.com\
 * License: Proprietary\
 * Text Domain: mcp-ai-wpoos-pro\
 * Domain Path: /languages\
 * Network: true\
 *\
 * @package WP_MCP_AI_Pro\
 *\
 * Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)\
 * All rights reserved. This is proprietary software.\
 *\
 * Patent Pending: This software is the subject of a pending patent application\
 * (Application #19/410,504) for "System and Method for Dynamic AI Orchestration\
 * Layer with Real-Time Capability Gating and Resource Budgeting."\
 */' "build/${PRO_SLUG}/mcp-ai-wpoos-pro.php"
            echo "✓ Added plugin header to mcp-ai-wpoos-pro.php for Pro addon"
        fi
        
        # Create ZIP
        cd build
        zip -r -q "${PRO_SLUG}-${VERSION}.zip" "${PRO_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
        cd ..
        
        PRO_SIZE=$(du -h "build/${PRO_SLUG}-${VERSION}.zip" | cut -f1)
        echo "✅ Pro add-on created: build/${PRO_SLUG}-${VERSION}.zip (${PRO_SIZE})"
    else
        echo "⚠️  Pro add-on directory (addons/pro) not found, skipping..."
    fi
    echo ""
fi

# ============================================================================
# Build Core Plugin (Lightweight)
# ============================================================================
if [ "$BUILD_CORE_ONLY" = true ]; then
    echo "Step 3c: Building Core plugin (lightweight)..."
    
    CORE_SLUG="mcp-ai-wpoos-core"
    mkdir -p "build/${CORE_SLUG}"
    
    # Copy core plugin files
    if [ -d "core" ]; then
        rsync -av --quiet core/ "build/${CORE_SLUG}/" \
            --exclude '.git' \
            --exclude '.vscode' \
            --exclude 'node_modules' \
            --exclude 'tests' \
            --exclude '*.zip' \
            --exclude '.DS_Store'
        
        # The core plugin already has its plugin header, just update the version if needed
        # Core plugin has its own version (1.0.0) separate from main plugin
        CORE_VERSION=$(grep -E "^\s*\*\s*Version:" "build/${CORE_SLUG}/mcp-ai-wpoos-core.php" | sed 's/.*Version:\s*//' | tr -d '[:space:]')
        if [ -z "$CORE_VERSION" ]; then
            CORE_VERSION="1.0.0"
        fi
        
        echo "✓ Core plugin version: ${CORE_VERSION}"
        
        # Create ZIP
        cd build
        zip -r -q "${CORE_SLUG}-${CORE_VERSION}.zip" "${CORE_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
        cd ..
        
        CORE_SIZE=$(du -h "build/${CORE_SLUG}-${CORE_VERSION}.zip" | cut -f1)
        echo "✅ Core plugin created: build/${CORE_SLUG}-${CORE_VERSION}.zip (${CORE_SIZE})"
    else
        echo "⚠️  Core plugin directory (core/) not found, skipping..."
    fi
    echo ""
fi

# ============================================================================
# Build Base + Pro Combined Version
# ============================================================================
if [ "$BUILD_COMBINED" = true ]; then
    echo "Step 3d: Building Base + Pro combined version..."
    
    COMBINED_SLUG="mcp-ai-wpoos"
    mkdir -p "build/${COMBINED_SLUG}"
    
    # Copy all plugin files (includes both base and pro)
    # Exclude mcp-ai-wpoos-base.php to prevent duplicate plugin detection in WordPress
    rsync -av --quiet . "build/${COMBINED_SLUG}/" \
        --include 'bin/' \
        --include 'bin/vectorize-image.js' \
        --exclude 'bin/*' \
        --exclude '.git' \
        --exclude '.git-branch-info' \
        --exclude '.github' \
        --exclude '.wordpress-org' \
        --exclude '.codex' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'coverage' \
        --exclude 'build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.lock' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude 'core' \
        --exclude 'shared' \
        --exclude 'ARCHITECTURE.md' \
        --exclude 'RELEASE_CHECKLIST.md' \
        --exclude 'CONTRIBUTING.md' \
        --exclude 'SECURITY.md' \
        --exclude 'BUILD.md' \
        --exclude 'INCOMPLETE-FEATURES-REVIEW.md' \
        --exclude 'INCOMPLETE-FEATURES-STATUS-SUMMARY.md' \
        --exclude 'PERFORMANCE_BUTTONS_FIX.md' \
        --exclude 'VENDOR-EXEC-USAGE.md' \
        --exclude 'WORDPRESS_ORG_SUBMISSION_GUIDE.md' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'assets/examples' \
        --exclude 'mcp-ai-wpoos-base.php' \
        --exclude '*.map' \
        --exclude 'vendor/*/Test' \
        --exclude 'vendor/*/Tests' \
        --exclude 'vendor/*/tests' \
        --exclude 'vendor/*/*/Test' \
        --exclude 'vendor/*/*/Tests' \
        --exclude 'vendor/*/*/tests' \
        --exclude 'vendor/*/.git' \
        --exclude 'vendor/*/*/.git' \
        --exclude 'vendor/*/*/*/.git' \
        --exclude 'vendor/symfony/*/Resources/translations' \
        --exclude 'vendor/*/README.md' \
        --exclude 'vendor/*/README' \
        --exclude 'vendor/*/CHANGELOG.md' \
        --exclude 'vendor/*/CHANGELOG' \
        --exclude 'vendor/*/CONTRIBUTING.md' \
        --exclude 'vendor/*/UPGRADE.md' \
        --exclude 'vendor/*/*/README.md' \
        --exclude 'vendor/*/*/README' \
        --exclude 'vendor/*/*/CHANGELOG.md' \
        --exclude 'vendor/*/*/CHANGELOG' \
        --exclude 'vendor/*/*/CONTRIBUTING.md' \
        --exclude 'vendor/*/*/UPGRADE.md' \
        --exclude 'vendor/*/.gitignore' \
        --exclude 'vendor/*/*/.gitignore' \
        --exclude 'vendor/*/.gitattributes' \
        --exclude 'vendor/*/*/.gitattributes' \
        --exclude 'vendor/*/Makefile' \
        --exclude 'vendor/*/*/Makefile' \
        --exclude 'vendor/*/.travis.yml' \
        --exclude 'vendor/*/*/.travis.yml' \
        --exclude 'vendor/*/.circleci' \
        --exclude 'vendor/*/*/.circleci' \
        --exclude 'vendor/*/phpunit.xml' \
        --exclude 'vendor/*/*/phpunit.xml' \
        --exclude 'vendor/*/phpunit.xml.dist' \
        --exclude 'vendor/*/*/phpunit.xml.dist' \
        --exclude 'vendor/*/phpstan.neon' \
        --exclude 'vendor/*/*/phpstan.neon' \
        --exclude 'vendor/*/phpstan.neon.dist' \
        --exclude 'vendor/*/*/phpstan.neon.dist' \
        --exclude 'vendor/*/phpstan-baseline.neon' \
        --exclude 'vendor/*/*/phpstan-baseline.neon' \
        --exclude 'vendor/*/psalm.xml' \
        --exclude 'vendor/*/*/psalm.xml' \
        --exclude 'vendor/*/psalm.baseline.xml' \
        --exclude 'vendor/*/*/psalm.baseline.xml' \
        --exclude 'vendor/*/.php-cs-fixer.php' \
        --exclude 'vendor/*/*/.php-cs-fixer.php' \
        --exclude 'vendor/*/.php-cs-fixer.dist.php' \
        --exclude 'vendor/*/*/.php-cs-fixer.dist.php' \
        --exclude 'vendor/*/phpspec.yml' \
        --exclude 'vendor/*/*/phpspec.yml' \
        --exclude 'vendor/*/phpspec.yml.dist' \
        --exclude 'vendor/*/*/phpspec.yml.dist' \
        --exclude 'vendor/*/phpspec.ci.yml' \
        --exclude 'vendor/*/*/phpspec.ci.yml' \
        --exclude 'vendor/*/.pullapprove.yml' \
        --exclude 'vendor/*/*/.pullapprove.yml' \
        --exclude 'includes/class-wp-mcp-ai-remote-tester.php' \
        --exclude 'includes/elementor/class-wp-mcp-ai-elementor-test-*.php' \
        --exclude 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-*.php'
    
    # Keep both minified and unminified assets for flexibility
    # PHP code will automatically use minified versions in production (via get_asset_file() method)
    # and unminified versions when SCRIPT_DEBUG is enabled.
    # This provides better debugging experience while maintaining optimal production performance.
    echo "✓ Keeping both minified and unminified assets for SCRIPT_DEBUG support"
    
    # Remove README.md (readme.txt is the WordPress.org standard)
    if [ -f "build/${COMBINED_SLUG}/README.md" ]; then
        rm -f "build/${COMBINED_SLUG}/README.md"
        echo "✓ Removed README.md (readme.txt is used for WordPress.org)"
    fi
    
    # Create ZIP
    cd build
    zip -r -q "${COMBINED_SLUG}-${VERSION}.zip" "${COMBINED_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..
    
    COMBINED_SIZE=$(du -h "build/${COMBINED_SLUG}-${VERSION}.zip" | cut -f1)
    echo "✅ Combined version created: build/${COMBINED_SLUG}-${VERSION}.zip (${COMBINED_SIZE})"
    echo ""
fi

# ============================================================================
# Summary
# ============================================================================
echo "=========================================="
echo "✅ Build Complete!"
echo "=========================================="
echo ""
echo "📦 Output files in build/:"
ls -lh build/*.zip 2>/dev/null | awk '{print "   " $NF " (" $5 ")"}'
echo ""
echo "To install:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Upload the appropriate ZIP file:"
[ "$BUILD_BASE" = true ] && echo "     - mcp-ai-wpoos-base-${VERSION}.zip (Base version)"
[ "$BUILD_PRO" = true ] && echo "     - mcp-ai-wpoos-pro-${VERSION}.zip (Pro add-on, requires base)"
[ "$BUILD_COMBINED" = true ] && echo "     - mcp-ai-wpoos-${VERSION}.zip (Complete: Base + Pro combined)"
if [ "$BUILD_CORE_ONLY" = true ]; then
    CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")
    echo "     - mcp-ai-wpoos-core-${CORE_VERSION}.zip (Lightweight core plugin)"
fi
echo "  3. Click 'Install Now' and then 'Activate'"
echo ""
