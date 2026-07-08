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
#   ./bin/build-plugin-zip.sh --wp-org           # Builds WordPress.org submission packages
#   ./bin/build-plugin-zip.sh --all              # Builds all versions including WP.org
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

# ---------------------------------------------------------------------------
# WSL auto-detection: when running natively on Windows (Git Bash / MSYS2)
# without a working rsync, automatically re-execute inside WSL.
# ---------------------------------------------------------------------------
_wsl_rerun_if_needed() {
	case "$(uname -s)" in
		MINGW*|MSYS*) ;;
		*) return 0 ;;
	esac
	if rsync --version >/dev/null 2>&1; then
		return 0
	fi
	if ! command -v wsl >/dev/null 2>&1; then
		return 0
	fi
	_wsl_root="$(echo "$ROOT_DIR" | sed 's|^/\([a-zA-Z]\)/|/mnt/\1/|')"
	_wsl_script="$(echo "$0" | sed 's|\\|/|g')"
	case "$_wsl_script" in
		/*) ;;
		*) _wsl_script="$_wsl_root/$_wsl_script" ;;
	esac
	_wsl_script="$(echo "$_wsl_script" | sed 's|^/\([a-zA-Z]\)/|/mnt/\1/|')"
	# Build a safely-escaped argument string for the re-exec
	_wsl_args=""
	for _arg in "$@"; do
		_wsl_args="$_wsl_args $(printf '%q' "$_arg")"
	done
	echo "ℹ️  Windows detected without working rsync → re-executing via WSL..."
	echo ""
	exec wsl bash -c "export PATH=/usr/bin:/bin:/usr/local/bin:\$PATH; cd '$_wsl_root' && bash '$_wsl_script' $_wsl_args"
}
_wsl_rerun_if_needed "$@"

# Default values
BUILD_BASE=false
BUILD_PRO=false
BUILD_COMBINED=false
BUILD_CORE_ONLY=false
BUILD_TOOLKITS=false
BUILD_WP_ORG=false
SKIP_NPM_BUILD=false
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
        --toolkits)
            BUILD_TOOLKITS=true
            shift
            ;;
        --wp-org)
            BUILD_WP_ORG=true
            shift
            ;;
        --skip-npm-build)
            SKIP_NPM_BUILD=true
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
            BUILD_TOOLKITS=true
            BUILD_WP_ORG=true
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
            echo "  --toolkits        Build individual toolkit add-on ZIPs"
            echo "  --wp-org          Build WordPress.org submission packages (text domain transform)"
            echo "  --all             Build all versions (base, pro, combined, toolkits, wp-org)"
            echo "  --skip-npm-build  Skip npm install and build (use pre-built assets)"
            echo "  --version X.Y.Z   Specify version number"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                       # Build base, pro, and combined"
            echo "  $0 --base                # Build only base version"
            echo "  $0 --pro                 # Build only pro add-on"
            echo "  $0 --combined            # Build base + pro combined"
            echo "  $0 --core-only           # Build only core plugin"
            echo "  $0 --skip-npm-build      # Skip npm build (assets already pre-built)"
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
if [ "$BUILD_BASE" = false ] && [ "$BUILD_PRO" = false ] && [ "$BUILD_COMBINED" = false ] && [ "$BUILD_CORE_ONLY" = false ] && [ "$BUILD_TOOLKITS" = false ] && [ "$BUILD_WP_ORG" = false ]; then
    BUILD_BASE=true
    BUILD_PRO=true
    BUILD_COMBINED=true
    BUILD_CORE_ONLY=true
    BUILD_WP_ORG=true
fi

# WordPress.org packages are generated from the base and combined ZIPs
if [ "$BUILD_WP_ORG" = true ]; then
    BUILD_BASE=true
    BUILD_COMBINED=true
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
[ "$BUILD_TOOLKITS" = true ] && echo "  ✓ Individual toolkit add-ons (build/toolkit-addons/)"
[ "$BUILD_WP_ORG" = true ] && echo "  ✓ WordPress.org submission packages (nvdigital-open-operator-system-oos-*)"
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
if [ "$SKIP_NPM_BUILD" = true ]; then
    echo "Step 1: Skipping npm build (--skip-npm-build flag set — using pre-built assets)..."
    echo "✅ Using pre-built frontend assets from repository"
else
    echo "Step 1: Building frontend assets..."
    npm ci --silent 2>/dev/null || npm install --silent
    npm run build:full
    echo "✅ Frontend assets built"
fi
echo ""

# Step 2: Install production Composer dependencies
echo "Step 2: Installing production PHP dependencies..."
composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --quiet
echo "✅ Production dependencies installed (with optimized classmap autoloader)"
echo ""

# Clean build staging directories only — preserve previously-built ZIP files
# (ZIPs in build/ are tracked in git; rm -rf build would delete them from the repo)
rm -rf build/mcp-ai-wpoos \
       build/mcp-ai-wpoos-base \
       build/mcp-ai-wpoos-pro \
       build/mcp-ai-wpoos-core \
       build/nvdigital-open-operator-system-oos \
       build/nvdigital-open-operator-system-oos-pro \
       build/nvdigital-open-operator-system-oos-complete \
       build/nvdigital-open-operator-system-oos-core \
       build/wp-mcp-ai \
       build/wp-mcp-ai-base \
       build/wp-mcp-ai-pro \
       build/workflow-builder \
       build/.tmp-addon-zips
mkdir -p build

# Remove previously built main-plugin ZIPs that may carry a stale version stamp.
# Only clean ZIPs for the variants being built in *this* invocation, so
# incremental builds (e.g. --base then --pro) don't wipe each other.
# (build-addon-zips.sh handles addon ZIP cleanup separately.)
[ "$BUILD_BASE"     = true ] && rm -f build/mcp-ai-wpoos-base-*.zip build/nvdigital-open-operator-system-oos-*.zip
[ "$BUILD_PRO"      = true ] && rm -f build/mcp-ai-wpoos-pro-*.zip build/nvdigital-open-operator-system-oos-pro-*.zip build/nvdigital-oos-pro-*.zip
[ "$BUILD_COMBINED" = true ] && rm -f build/mcp-ai-wpoos-[0-9]*.zip build/nvdigital-open-operator-system-oos-complete-*.zip
[ "$BUILD_CORE_ONLY" = true ] && rm -f build/mcp-ai-wpoos-core-*.zip build/nvdigital-open-operator-system-oos-core-*.zip

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
        --exclude '.codex-wordpress' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude '.agents' \
        --exclude '.bmad' \
        --exclude '.context' \
        --exclude '.zed' \
        --exclude '.wp-env.json' \
        --exclude '.wp-env.test.json' \
        --exclude '.wp-env.override.json' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'coverage' \
        --exclude '/build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.gitattributes' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude '/*.md' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.lock' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude '/docker' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude '/core' \
        --exclude '/shared' \
        --exclude '/lib' \
        --exclude 'archive' \
        --exclude 'packages' \
        --exclude '/src' \
        --exclude 'package.json' \
        --exclude 'tsconfig.json' \
        --exclude '.npmrc' \
        --exclude '.codecov.yml' \
        --exclude 'esbuild.config.pro.js' \
        --exclude 'cleancss.config.js' \
        --exclude 'cosmos.config.json' \
        --exclude 'cosmos.webpack.config.js' \
        --exclude 'webpack.config.tma-builder.js' \
        --exclude 'webpack.config.tma-woo-shop.js' \
        --exclude 'webpack.config.tma.js' \
        --exclude 'webpack.config.workflow.js' \
        --exclude 'phpcs.xml.dist' \
        --exclude 'phpcs' \
        --exclude 'webpack.config.js' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'addons' \
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

    # Restore the addons/ directory with a placeholder so the folder exists for
    # users who later install toolkit add-ons (which unzip into addons/<slug>/).
    mkdir -p "build/${BASE_SLUG}/addons"
    touch "build/${BASE_SLUG}/addons/.gitkeep"
    echo "✓ Created addons/.gitkeep placeholder (addons/ excluded from base build)"

    # Keep both minified and unminified assets for flexibility
    # PHP code will automatically use minified versions in production (via get_asset_file() method)
    # and unminified versions when SCRIPT_DEBUG is enabled.
    # This provides better debugging experience while maintaining optimal production performance.
    echo "✓ Keeping both minified and unminified assets for SCRIPT_DEBUG support"

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
 * Tested up to: 6.9\
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
            --exclude '.gitattributes' \
            --exclude '.distignore' \
            --exclude '.npmrc' \
            --exclude '.vscode' \
            --exclude 'node_modules' \
            --exclude 'tests' \
            --exclude 'test' \
            --exclude 'Test' \
            --exclude 'Tests' \
            --exclude 'docs' \
            --exclude '*.zip' \
            --exclude 'assets/examples' \
            --exclude 'composer.lock' \
            --exclude 'package-lock.json' \
            --exclude 'package.json' \
            --exclude '*.js.map' \
            --exclude '*.css.map' \
            --exclude 'assets/vendor/facebook-nodejs-business-sdk' \
            --exclude 'assets/vendor/canvas' \
            --exclude 'assets/vendor/sharp/node_modules/@img' \
            --exclude 'assets/vendor/sharp/src' \
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
            --exclude 'vendor/*/*/*/phpunit.xml*' \
            --exclude 'vendor/*/*/phpstan.neon*' \
            --exclude 'vendor/*/*/psalm.xml*' \
            --exclude 'vendor/*/*/.php-cs-fixer*' \
            --exclude 'vendor/*/Makefile' \
            --exclude 'vendor/*/*/Makefile'
        
        echo "✓ Excluded: tests (~13MB), docs (~1MB), examples (~2MB), README files (~1MB), CI configs, QA tools, source maps (~16MB), Facebook SDK (~28MB)"
        echo "✓ Excluded packages requiring system dependencies: canvas (requires libvips/Cairo for PDF OCR), sharp native binaries (@img/sharp-libvips-linux-x64)"
        echo "✓ Excluded CDN packages: chart.js (~420KB), katex (~3.1MB), d3 (~864KB), axios (~1.6MB), mathjs (~17MB), prettier (~500KB)"
        echo "ℹ️  Note: Excluded packages are kept in git repo but not in ZIP distribution"
        echo "ℹ️  Note: CDN packages load from jsDelivr with automatic fallback"
        echo "ℹ️  Note: Canvas requires system-level installation for PDF OCR; install via npm when needed"
        echo "ℹ️  Note: Sharp native binaries excluded (~16 MB); JS wrapper included — run npm install sharp for native acceleration"
        echo "ℹ️  Note: Other vendor packages (~40+ NPM packages including puppeteer-core) are included for immediate functionality"

        # Fix masterminds/html5 case collision on case-insensitive filesystems (Windows/macOS).
        # The package ships both src/HTML5.php (file) and src/HTML5/ (directory), which
        # collide when the filesystem treats them as the same name.
        # Rename HTML5.php → HTML5_main.php and add a classmap entry to resolve.
        MASTERMINDS_SRC="build/${PRO_SLUG}/vendor/masterminds/html5/src"
        if [ -f "${MASTERMINDS_SRC}/HTML5.php" ] && [ -d "${MASTERMINDS_SRC}/HTML5" ]; then
            mv "${MASTERMINDS_SRC}/HTML5.php" "${MASTERMINDS_SRC}/HTML5_main.php"
            MASTERMINDS_CLASSMAP="build/${PRO_SLUG}/vendor/composer/autoload_classmap.php"
            if [ -f "${MASTERMINDS_CLASSMAP}" ]; then
                # Remove closing ); and append new classmap entry + closing );
                sed -i '$ d' "${MASTERMINDS_CLASSMAP}"
                echo "    'Masterminds\\\\HTML5' => \$vendorDir . '/masterminds/html5/src/HTML5_main.php'," >> "${MASTERMINDS_CLASSMAP}"
                echo ");" >> "${MASTERMINDS_CLASSMAP}"
            fi
            echo "✓ Fixed masterminds/html5 case collision (HTML5.php → HTML5_main.php)"
        fi
        
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
        --exclude '.codex-wordpress' \
        --exclude '.devcontainer' \
        --exclude '.vscode' \
        --exclude '.agents' \
        --exclude '.bmad' \
        --exclude '.context' \
        --exclude '.zed' \
        --exclude '.wp-env.json' \
        --exclude '.wp-env.test.json' \
        --exclude '.wp-env.override.json' \
        --exclude 'node_modules' \
        --exclude 'tests' \
        --exclude 'coverage' \
        --exclude '/build' \
        --exclude 'svn-*' \
        --exclude '.eslintrc.json' \
        --exclude '.eslintignore' \
        --exclude '.gitignore' \
        --exclude '.gitattributes' \
        --exclude '.editorconfig' \
        --exclude '.nvmrc' \
        --exclude 'CODEOWNERS' \
        --exclude '/*.md' \
        --exclude 'phpunit.xml.dist' \
        --exclude 'composer.lock' \
        --exclude 'package-lock.json' \
        --exclude 'patches.lock.json' \
        --exclude 'babel.config.js' \
        --exclude 'jest.config.js' \
        --exclude 'esbuild.config.js' \
        --exclude 'docker-compose.yml' \
        --exclude '/docker' \
        --exclude 'patches' \
        --exclude 'docs' \
        --exclude '/core' \
        --exclude '/shared' \
        --exclude 'archive' \
        --exclude 'packages' \
        --exclude '/src' \
        --exclude 'package.json' \
        --exclude 'tsconfig.json' \
        --exclude '.npmrc' \
        --exclude '.codecov.yml' \
        --exclude 'esbuild.config.pro.js' \
        --exclude 'cleancss.config.js' \
        --exclude 'cosmos.config.json' \
        --exclude 'cosmos.webpack.config.js' \
        --exclude 'webpack.config.tma-builder.js' \
        --exclude 'webpack.config.tma-woo-shop.js' \
        --exclude 'webpack.config.tma.js' \
        --exclude 'webpack.config.workflow.js' \
        --exclude 'phpcs.xml.dist' \
        --exclude 'phpcs' \
        --exclude 'webpack.config.js' \
        --exclude 'test-*.php' \
        --exclude 'verify-*.sh' \
        --exclude '*.zip' \
        --exclude '*.tar.gz' \
        --exclude '.distignore' \
        --exclude 'assets/examples' \
        --exclude 'addons/pro/assets/vendor/facebook-nodejs-business-sdk' \
        --exclude 'addons/pro/assets/vendor/canvas' \
        --exclude 'addons/pro/assets/vendor/sharp/node_modules/@img' \
        --exclude 'addons/pro/assets/vendor/sharp/src' \
        --exclude 'addons/pro/assets/vendor/chart.js' \
        --exclude 'addons/pro/assets/vendor/katex' \
        --exclude 'addons/pro/assets/vendor/d3' \
        --exclude 'addons/pro/assets/vendor/axios' \
        --exclude 'addons/pro/assets/vendor/mathjs' \
        --exclude 'addons/pro/assets/vendor/prettier' \
        --exclude 'addons/pro/vendor/tecnickcom' \
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
    
    # Fix masterminds/html5 case collision on case-insensitive filesystems (Windows/macOS).
    # The package ships both src/HTML5.php (file) and src/HTML5/ (directory), which
    # collide when the filesystem treats them as the same name.
    # Rename HTML5.php → HTML5_main.php and add a classmap entry to resolve.
    COMBINED_MASTERMINDS_SRC="build/${COMBINED_SLUG}/addons/pro/vendor/masterminds/html5/src"
    if [ -f "${COMBINED_MASTERMINDS_SRC}/HTML5.php" ] && [ -d "${COMBINED_MASTERMINDS_SRC}/HTML5" ]; then
        mv "${COMBINED_MASTERMINDS_SRC}/HTML5.php" "${COMBINED_MASTERMINDS_SRC}/HTML5_main.php"
        COMBINED_CLASSMAP="build/${COMBINED_SLUG}/addons/pro/vendor/composer/autoload_classmap.php"
        if [ -f "${COMBINED_CLASSMAP}" ]; then
            # Remove closing ); and append new classmap entry + closing );
            sed -i '$ d' "${COMBINED_CLASSMAP}"
            echo "    'Masterminds\\\\HTML5' => \$vendorDir . '/masterminds/html5/src/HTML5_main.php'," >> "${COMBINED_CLASSMAP}"
            echo ");" >> "${COMBINED_CLASSMAP}"
        fi
        echo "✓ Fixed masterminds/html5 case collision (HTML5.php → HTML5_main.php)"
    fi

    # Keep both minified and unminified assets for flexibility
    # PHP code will automatically use minified versions in production (via get_asset_file() method)
    # and unminified versions when SCRIPT_DEBUG is enabled.
    # This provides better debugging experience while maintaining optimal production performance.
    echo "✓ Keeping both minified and unminified assets for SCRIPT_DEBUG support"

    # Remove non-pro toolkit addons from the combined build.
    # Toolkit addons (chat-spa, canvas-toolkit, document-editor, etc.) are distributed
    # as individual ZIPs via --toolkits and should not be bundled inside the combined package.
    if [ -d "build/${COMBINED_SLUG}/addons" ]; then
        find "build/${COMBINED_SLUG}/addons" -mindepth 1 -maxdepth 1 -type d ! -name 'pro' -exec rm -rf {} +
        echo "✓ Removed non-pro toolkit addons from combined build (distributed separately)"
    fi

    # ------------------------------------------------------------------
    # Prune tecnickcom/tcpdf (~28.7 MB) from the combined zip.
    #
    # tcpdf is the single largest vendor dependency and is only used by two
    # optional pro tools (merge-pdfs, add-watermark-to-pdf) that already
    # gracefully degrade when the library is missing via
    # `class_exists( '\TCPDF' )` checks. Users who need PDF merge/watermark
    # functionality install the dedicated `oos-toolkit-tcpdf` add-on, which
    # ships only the tcpdf vendor library and registers its own autoloader.
    #
    # The rsync exclude above removes the vendor source. Below we also strip
    # tcpdf entries from the Composer autoload classmap so that
    # `class_exists( '\TCPDF' )` returns false cleanly without emitting an
    # `include(): Failed opening` warning when the autoloader resolves a
    # classmap entry to a missing file.
    # ------------------------------------------------------------------
    COMBINED_VENDOR_COMPOSER="build/${COMBINED_SLUG}/addons/pro/vendor/composer"
    if [ -f "${COMBINED_VENDOR_COMPOSER}/autoload_classmap.php" ]; then
        sed -i.bak '/tecnickcom\/tcpdf/d' "${COMBINED_VENDOR_COMPOSER}/autoload_classmap.php"
        rm -f "${COMBINED_VENDOR_COMPOSER}/autoload_classmap.php.bak"
    fi
    if [ -f "${COMBINED_VENDOR_COMPOSER}/autoload_static.php" ]; then
        sed -i.bak '/tecnickcom\/tcpdf/d' "${COMBINED_VENDOR_COMPOSER}/autoload_static.php"
        rm -f "${COMBINED_VENDOR_COMPOSER}/autoload_static.php.bak"
    fi
    echo "✓ Excluded tecnickcom/tcpdf vendor (ships in oos-toolkit-tcpdf add-on)"

    # Create ZIP
    cd build
    zip -r -q "${COMBINED_SLUG}-${VERSION}.zip" "${COMBINED_SLUG}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..
    
    COMBINED_SIZE=$(du -h "build/${COMBINED_SLUG}-${VERSION}.zip" | cut -f1)
    echo "✅ Combined version created: build/${COMBINED_SLUG}-${VERSION}.zip (${COMBINED_SIZE})"
    echo ""
fi

# ============================================================================
# Build Individual Toolkit Add-ons
# ============================================================================
if [ "$BUILD_TOOLKITS" = true ]; then
    echo "Step 3e: Building individual toolkit add-ons..."
    "$SCRIPT_DIR/build-toolkit-addons.sh" --version "$VERSION"
    echo ""
fi

# ============================================================================
# Build WordPress.org Submission Packages
# ============================================================================
if [ "$BUILD_WP_ORG" = true ]; then
    echo "Step 3f: Building WordPress.org submission packages..."
    "$SCRIPT_DIR/build-wordpress-org-from-base.sh" --version "$VERSION"
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
if [ "$BUILD_TOOLKITS" = true ] && [ -d "build/toolkit-addons" ]; then
    echo ""
    echo "📦 Toolkit add-ons in build/toolkit-addons/:"
    ls -lh build/toolkit-addons/*.zip 2>/dev/null | awk '{print "   " $NF " (" $5 ")"}'
fi
echo ""
echo "To install:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Upload the appropriate ZIP file:"
[ "$BUILD_BASE" = true ] && echo "     - mcp-ai-wpoos-base-${VERSION}.zip (Base version — extracts to mcp-ai-wpoos-base/)"
[ "$BUILD_PRO" = true ] && echo "     - mcp-ai-wpoos-pro-${VERSION}.zip (Pro add-on — extracts to mcp-ai-wpoos-pro/)"
[ "$BUILD_COMBINED" = true ] && echo "     - mcp-ai-wpoos-${VERSION}.zip (Complete: Base + Pro — extracts to mcp-ai-wpoos/)"
if [ "$BUILD_CORE_ONLY" = true ]; then
    CORE_VERSION=$(grep -E "^\s*\*\s*Version:" core/mcp-ai-wpoos-core.php 2>/dev/null | sed 's/.*Version:\s*//' | tr -d '[:space:]' || echo "1.0.0")
    echo "     - mcp-ai-wpoos-core-${CORE_VERSION}.zip (Lightweight core — extracts to mcp-ai-wpoos-core/)"
fi
[ "$BUILD_TOOLKITS" = true ] && echo "     - build/toolkit-addons/oos-toolkit-*-${VERSION}.zip (Individual toolkit add-ons)"
[ "$BUILD_WP_ORG" = true ] && echo "     - nvdigital-open-operator-system-oos-${VERSION}.zip (WP.org Base — extracts to nvdigital-open-operator-system-oos/)"
[ "$BUILD_WP_ORG" = true ] && echo "     - nvdigital-open-operator-system-oos-pro-${VERSION}.zip (WP.org Pro — extracts to nvdigital-open-operator-system-oos-pro/)"
[ "$BUILD_WP_ORG" = true ] && echo "     - nvdigital-open-operator-system-oos-complete-${VERSION}.zip (WP.org Complete — extracts to nvdigital-open-operator-system-oos-complete/)"
echo "  3. Click 'Install Now' and then 'Activate'"
echo ""
echo "  ℹ️  All ZIP filenames include the version, but the folder inside is"
echo "     versionless — so WordPress always extracts to the same directory"
echo "     regardless of the version number. Updating is a simple replace."
echo ""

# Step: Rebuild production autoloader after all ZIPs are created.
# This ensures vendor/ is left in the optimised production state
# (no dev packages, classmap-only autoloading) for any subsequent
# deployment or commit step.
echo "Rebuilding production autoloader..."
composer install --no-dev --classmap-authoritative --no-interaction --quiet
echo "✅ Production autoloader rebuilt"
