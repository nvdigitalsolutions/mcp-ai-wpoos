#!/bin/bash
#
# Build Individual Toolkit Add-on ZIPs
#
# Creates separate WordPress plugin ZIP files for each Pro toolkit,
# allowing users to install only the toolkits they need as individual add-ons.
#
# Each toolkit add-on:
#   - Requires the base oOS plugin to be installed and activated
#   - Contains only the toolkit-specific files (init, tools, admin pages, assets)
#   - Automatically enables its toolkit setting on activation
#   - Registers its tools via the wp_mcp_ai_register_tools hook
#   - Can be installed alongside the full Pro add-on (won't conflict)
#
# Usage:
#   ./bin/build-toolkit-addons.sh                         # Build all toolkit add-ons
#   ./bin/build-toolkit-addons.sh --toolkit ecommerce     # Build a specific toolkit
#   ./bin/build-toolkit-addons.sh --list                  # List available toolkits
#   ./bin/build-toolkit-addons.sh --version 1.0.0         # Specify version
#
# Output:
#   build/toolkit-addons/oos-toolkit-{name}-{VERSION}.zip
#
# Requirements:
#   - zip command
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PRO_DIR="${ROOT_DIR}/addons/pro"

cd "$ROOT_DIR"

# ---------------------------------------------------------------------------
# WSL auto-detection: when running natively on Windows (Git Bash / MSYS2)
# without a working rsync, automatically re-execute inside WSL where the
# full Linux toolchain (rsync, zip, php) is available.
# ---------------------------------------------------------------------------
_wsl_rerun_if_needed() {
	# Only applies to Windows-native shells (MINGW / MSYS)
	case "$(uname -s)" in
		MINGW*|MSYS*) ;;
		*) return 0 ;;
	esac

	# Already running inside WSL? Skip (WSL uname reports "Linux")
	# If rsync is already working natively, skip
	if rsync --version >/dev/null 2>&1; then
		return 0
	fi

	# Check if WSL is available
	if ! command -v wsl >/dev/null 2>&1; then
		return 0
	fi

	# Build WSL-safe paths from the current Git Bash absolute paths.
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
	exec wsl bash -c "export PATH=/usr/bin:/bin:/usr/local/bin:$PATH; cd '$_wsl_root' && bash '$_wsl_script' $_wsl_args"
}
_wsl_rerun_if_needed "$@"

# Default values
BUILD_SINGLE=""
LIST_ONLY=false
VERSION=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --toolkit)
            BUILD_SINGLE="$2"
            shift 2
            ;;
        --list)
            LIST_ONLY=true
            shift
            ;;
        --version)
            VERSION="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --toolkit NAME    Build only the specified toolkit add-on"
            echo "  --list            List all available toolkits and exit"
            echo "  --version X.Y.Z   Specify version number"
            echo "  -h, --help        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                            # Build all toolkit add-ons"
            echo "  $0 --toolkit ecommerce        # Build only E-commerce toolkit"
            echo "  $0 --toolkit financial-planner # Build only Financial Planner toolkit"
            echo "  $0 --list                     # Show available toolkits"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information."
            exit 1
            ;;
    esac
done

# Get version if not specified
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^\s*\*\s*Version:" mcp-ai-wpoos.php | sed 's/.*Version:\s*//' | tr -d '[:space:]')
    if [ -z "$VERSION" ]; then
        VERSION="dev"
    fi
fi

# ============================================================================
# Toolkit Definitions
# ============================================================================
# Each toolkit is defined as:
#   TOOLKIT_ID|Display Name|setting_key|tools_dir|init_file|description|extra_dirs|vendor_packages
#
# extra_dirs: comma-separated list of additional directories/files to include
#   relative to addons/pro/ (e.g., includes/admin/class-wp-mcp-ai-crm-*.php)
#
# vendor_packages: comma-separated list of Composer vendor package names to include.
#   Each package and its transitive dependencies will be copied from addons/pro/vendor/.
#   A vendor/autoload.php will be generated and the bootstrapper will load it.
#   Leave empty if no vendor packages are needed.

declare -a TOOLKITS=(
    "ecommerce|E-commerce Toolkit|enable_ecommerce_toolkit|ecommerce|ecommerce-toolkit-init.php|Advanced WooCommerce integration with product management, order processing, inventory tracking, shipping optimization, and customer management.|includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php,includes/admin/class-wp-mcp-ai-product-research-page.php,includes/admin/class-wp-mcp-ai-product-consolidate-page.php,includes/admin/class-wp-mcp-ai-product-settings-page.php|dvdoug/boxpacker"
    "social-media|Social Media Management Toolkit|enable_social_media_toolkit|social-media|social-media-toolkit-init.php|Multi-platform social media posting, scheduling, analytics, and engagement management for Twitter, Facebook, LinkedIn, and Instagram.||"
    "analytics|Advanced Analytics Toolkit|enable_advanced_analytics_toolkit|analytics|analytics-toolkit-init.php|Business intelligence, predictive analytics, data visualization, statistical analysis, and regression modeling.||"
    "multilingual|Multilingual Content Toolkit|enable_multilingual_toolkit|multilingual|multilingual-toolkit-init.php|Multi-language content management with automatic language detection, translation, and localization.||"
    "video-production|Video Production Toolkit|enable_video_production_toolkit|video-production|video-production-toolkit-init.php|Professional video creation, editing, and processing with FFmpeg, subtitle generation, and GIF creation.||"
    "financial-planner|Financial Planner Toolkit|enable_financial_planner_toolkit|financial-planning|financial-planner-toolkit-init.php|Retirement planning, budgeting, investment tracking, debt management, and financial goal planning.||phpoffice/phpspreadsheet"
    "dj-management|DJ Management Toolkit|enable_dj_management_toolkit|dj-management|dj-management-toolkit-init.php|Equipment tracking, playlist management, event scheduling, client management, and music library organization.||phpoffice/phpspreadsheet"
    "image-production|Image Production Toolkit|enable_image_production_toolkit|image-production|image-production-toolkit-init.php|AI-powered image generation, editing, enhancement, and optimization with advanced filters and effects.||"
    "ai-tool-builder|AI Tool Builder Toolkit|enable_ai_tool_builder_toolkit|ai-tool-builder|ai-tool-builder-toolkit-init.php|Meta-toolkit for creating custom AI tools with scaffolding, code generation, testing, and documentation.||"
    "architect-agent|Architect Agent Toolkit|enable_architect_agent_toolkit|architect-agent|architect-agent-toolkit-init.php|Self-editing capabilities for AI agents with file operations, shell commands, git integration, and code search.||"
    "architectural-design|Architectural Design Toolkit|enable_architectural_design_toolkit|architectural-design|architectural-design-toolkit-init.php|AI-powered floor plan generation, 3D modeling, blueprint creation, code compliance, and cost estimation.||"
    "calendar-booking|Calendar Booking Toolkit|enable_calendar_booking_toolkit|calendar-booking|calendar-booking-toolkit-init.php|Appointment scheduling, availability management, calendar synchronization, and booking management.|includes/calendar-booking|"
    "crm|CRM & Email Marketing Toolkit|enable_crm_toolkit|crm|crm-toolkit-init.php|Contact management, email campaigns, lead tracking, CSV import/export, and customer relationship management.|includes/class-wp-mcp-ai-company-cpt.php,includes/admin/class-wp-mcp-ai-crm-settings-page.php,includes/admin/class-wp-mcp-ai-company-research-page.php,includes/research-add/class-wp-mcp-ai-crm-research-add.php|"
    "document-generation|Document Generation Toolkit|enable_document_generation_toolkit|document-generation|document-generation-toolkit-init.php|Advanced PDF, Word, and Excel document generation, OCR, merging, and watermarking.||dompdf/dompdf,tecnickcom/tcpdf,phpoffice/phpspreadsheet,phpoffice/phpword,smalot/pdfparser,thiagoalessio/tesseract_ocr"
    "regulatory-registration|Regulatory Registration Toolkit|enable_regulatory_registration_toolkit|regulatory-registration|regulatory-registration-toolkit-init.php|Regulatory product registration and compliance management for multi-country submissions.||phpoffice/phpspreadsheet"
    "site-creator|Site Creator Toolkit|enable_site_creator_toolkit|site-creator-toolkit|site-creator-toolkit-init.php|Advanced site creation with page builders, section builders, and widget builders.||"
    "healthcare-imaging|Healthcare Imaging Toolkit|enable_healthcare_imaging|_none_|healthcare-imaging-toolkit-init.php|DICOM medical imaging viewer with Cornerstone3D for PET/CT/MR studies.||"
    "media|Media Toolkit|enable_media_toolkit|_none_|media-toolkit-init.php|Image optimization, video processing, SVG vectorization, and math equation rendering.||"
    "tcpdf|TCPDF Library Add-on|enable_tcpdf_toolkit|_none_|_none_|TCPDF library add-on (~28.7 MB) for PDF merging and watermarking. Required by the optional document-generation tools shipped in the combined oOS plugin (merge-pdfs, add-watermark-to-pdf). Ships only the tecnickcom/tcpdf vendor library — no tools or admin pages.||tecnickcom/tcpdf"
)

# ============================================================================
# Vendor Package → Directory Mapping
# ============================================================================
# Maps Composer package names to their vendor directory names and transitive
# dependencies. This avoids needing to parse composer.lock at build time.
#
# Format: VENDOR_PACKAGE_MAP[package/name]="dir1 dir2 dir3"
# where each dir is a path under vendor/ to copy.

declare -A VENDOR_PACKAGE_MAP=(
    # dvdoug/boxpacker → dvdoug/ + psr/log
    ["dvdoug/boxpacker"]="dvdoug psr/log"
    # phpoffice/phpspreadsheet → phpoffice/phpspreadsheet + transitive deps
    ["phpoffice/phpspreadsheet"]="phpoffice/phpspreadsheet composer/pcre maennchen markbaker psr/simple-cache"
    # phpoffice/phpword → phpoffice/phpword + phpoffice/math
    ["phpoffice/phpword"]="phpoffice/phpword phpoffice/math"
    # dompdf/dompdf → dompdf/ + masterminds + sabberworm + thecodingmachine
    ["dompdf/dompdf"]="dompdf masterminds sabberworm thecodingmachine"
    # tecnickcom/tcpdf (no transitive deps)
    ["tecnickcom/tcpdf"]="tecnickcom"
    # smalot/pdfparser → smalot/ + symfony/polyfill-mbstring
    ["smalot/pdfparser"]="smalot symfony/polyfill-mbstring"
    # thiagoalessio/tesseract_ocr (no transitive deps)
    ["thiagoalessio/tesseract_ocr"]="thiagoalessio"
)

# ============================================================================
# List toolkits mode
# ============================================================================
if [ "$LIST_ONLY" = true ]; then
    echo "Available Toolkit Add-ons:"
    echo "========================="
    echo ""
    for toolkit_def in "${TOOLKITS[@]}"; do
        IFS='|' read -r tk_id tk_name tk_setting tk_tools_dir tk_init tk_desc tk_extra tk_vendor <<< "$toolkit_def"
        local_deps=""
        if [ -n "$tk_vendor" ]; then
            local_deps=" [vendor: ${tk_vendor}]"
        fi
        printf "  %-25s %s%s\n" "$tk_id" "$tk_name" "$local_deps"
    done
    echo ""
    echo "Use --toolkit NAME to build a specific toolkit."
    exit 0
fi

# Check requirements
if ! command -v zip &> /dev/null; then
    echo "❌ Error: zip is required but not installed."
    exit 1
fi

if [ ! -d "$PRO_DIR" ]; then
    echo "❌ Error: Pro add-on directory not found at: $PRO_DIR"
    exit 1
fi

echo "=========================================="
echo "Building Toolkit Add-on ZIPs v${VERSION}"
echo "=========================================="
echo ""

# Create output directory
OUTPUT_DIR="build/toolkit-addons"
mkdir -p "$OUTPUT_DIR"

# ============================================================================
# Function: Copy vendor packages for a toolkit add-on
# ============================================================================
# Copies the required vendor packages (and their transitive dependencies)
# from addons/pro/vendor/ to the toolkit's build directory, then copies
# the Composer autoloader files and regenerates the autoload classmap.
#
# Arguments:
#   $1 - build_dir: path to the toolkit build directory
#   $2 - vendor_packages: comma-separated list of package names
#   $3 - is_vendor_only: if "true", skip Composer autoloader (direct include)
#
# Returns 0 if vendor packages were copied, 1 if none were needed.
copy_vendor_packages() {
    local build_dir="$1"
    local vendor_packages="$2"
    local is_vendor_only="${3:-false}"
    
    if [ -z "$vendor_packages" ]; then
        return 1
    fi
    
    local vendor_src="${PRO_DIR}/vendor"
    local vendor_dest="${build_dir}/vendor"
    
    # Collect all unique vendor directories to copy
    declare -A dirs_to_copy
    
    IFS=',' read -ra PACKAGES <<< "$vendor_packages"
    for package in "${PACKAGES[@]}"; do
        package=$(echo "$package" | xargs)  # trim whitespace
        
        # Look up the package's directory mapping
        local dirs="${VENDOR_PACKAGE_MAP[$package]}"
        if [ -z "$dirs" ]; then
            echo "    ⚠️  No vendor mapping for: ${package} (skipping)"
            continue
        fi
        
        # Add each directory to the set
        for dir in $dirs; do
            dirs_to_copy["$dir"]=1
        done
    done
    
    if [ ${#dirs_to_copy[@]} -eq 0 ]; then
        return 1
    fi
    
    # Create vendor directory
    mkdir -p "$vendor_dest"
    
    # Copy each required vendor directory
    local copied_count=0
    for dir in "${!dirs_to_copy[@]}"; do
        local src_path="${vendor_src}/${dir}"
        if [ -d "$src_path" ]; then
            local dest_path="${vendor_dest}/${dir}"
            mkdir -p "$(dirname "$dest_path")"
            rsync -a --quiet "$src_path/" "$dest_path/" \
                --exclude 'tests' \
                --exclude 'test' \
                --exclude 'Test' \
                --exclude 'Tests' \
                --exclude 'docs' \
                --exclude 'doc' \
                --exclude 'Docs' \
                --exclude 'examples' \
                --exclude 'example' \
                --exclude 'README*' \
                --exclude 'CHANGELOG*' \
                --exclude 'CONTRIBUTING*' \
                --exclude '.travis.yml' \
                --exclude '.circleci' \
                --exclude '.github' \
                --exclude 'phpunit.xml*' \
                --exclude 'phpstan.neon*' \
                --exclude 'psalm.xml*' \
                --exclude '.php-cs-fixer*' \
                --exclude 'Makefile' \
                --exclude '.gitignore' \
                --exclude '.gitattributes' \
                --exclude '.editorconfig'
            copied_count=$((copied_count + 1))
        else
            echo "    ⚠️  Vendor directory not found: vendor/${dir}"
        fi
    done
    
    # Copy the Composer autoloader infrastructure (skip for vendor-only
    # supplements to avoid "Cannot declare class ComposerAutoloaderInit…"
    # fatal errors when the main plugin has already loaded its autoloader).
    if [ "$is_vendor_only" = "true" ]; then
        echo "    ✓ Copied ${copied_count} vendor package(s) (vendor-only — no Composer autoloader)"
    elif [ $copied_count -gt 0 ] && [ -d "${vendor_src}/composer" ]; then
        mkdir -p "${vendor_dest}/composer"
        # Copy the Composer autoloader files
        for f in autoload_classmap.php autoload_namespaces.php autoload_psr4.php \
                 autoload_real.php autoload_static.php ClassLoader.php \
                 installed.json installed.php LICENSE platform_check.php InstalledVersions.php; do
            [ -f "${vendor_src}/composer/${f}" ] && cp "${vendor_src}/composer/${f}" "${vendor_dest}/composer/"
        done
        # Copy the root autoload.php
        [ -f "${vendor_src}/autoload.php" ] && cp "${vendor_src}/autoload.php" "${vendor_dest}/"
        
        echo "    ✓ Copied ${copied_count} vendor package(s) with Composer autoloader"
    fi
    
    return 0
}

# ============================================================================
# Function: Generate the bootstrapper PHP file for a toolkit add-on
# ============================================================================
generate_bootstrapper() {
    local tk_id="$1"
    local tk_name="$2"
    local tk_setting="$3"
    local tk_tools_dir="$4"
    local tk_init="$5"
    local tk_desc="$6"
    local output_file="$7"
    local has_vendor="$8"
    local tk_vendor="$9"
    
    # Convert toolkit ID to slug (e.g., "ecommerce" -> "oos-toolkit-ecommerce")
    local plugin_slug="oos-toolkit-${tk_id}"
    local constant_prefix
    constant_prefix=$(echo "$tk_id" | tr '[:lower:]-' '[:upper:]_')

    # Vendor-only supplement toolkits (no tools dir, no init file) ship a
    # Composer vendor library to fill in for the combined Pro distribution.
    # They must match the Pro add-on's PHP requirement (8.1+) since their
    # purpose is to support Pro tools that already require 8.1.
    local requires_php="7.4"
    if [ "$tk_tools_dir" = "_none_" ] && [ "$tk_init" = "_none_" ]; then
        requires_php="8.1"
    fi
    
    cat > "$output_file" << PHPEOF
<?php
/**
 * Plugin Name: oOS Toolkit - ${tk_name}
 * Plugin URI: https://nvdigitalsolutions.com/wpoos/toolkits/${tk_id}
 * Description: ${tk_desc} Requires NV Digital Open Operator System (oOS) base plugin.
 * Version: ${VERSION}
 * Requires at least: 6.0
 * Requires PHP: ${requires_php}
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: Proprietary
 * Text Domain: ${plugin_slug}
 * Domain Path: /languages
 * Network: true
 *
 * @package WP_MCP_AI_Toolkit_${constant_prefix}
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * All rights reserved. This is proprietary software.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Detect "vendor-only supplement" toolkits (no tools, no init file).
// These exist solely to ship a Composer vendor library that the combined oOS
// Pro distribution intentionally omits to keep its footprint small (e.g.,
// tcpdf is ~28.7 MB). When Pro is active, a normal toolkit add-on would
// duplicate functionality and should deactivate, but a vendor-only supplement
// must STAY active so its autoloader can register the missing classes for
// Pro's tools to consume.
\$wp_mcp_ai_is_vendor_supplement_${tk_id//-/_} = ( '${tk_tools_dir}' === '_none_' && '${tk_init}' === '_none_' );

// Prevent loading if the full Pro add-on is already active — unless this is a
// vendor-only supplement add-on whose purpose is to provide a library that
// Pro is missing.
if ( defined( 'WP_MCP_AI_PRO_VERSION' ) && ! \$wp_mcp_ai_is_vendor_supplement_${tk_id//-/_} ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: toolkit display name */
					__( 'oOS Toolkit - %s: The full oOS Pro add-on is already active. This individual toolkit add-on is not needed and has been deactivated to avoid conflicts.', '${plugin_slug}' ),
					'${tk_name}'
				)
			);
			echo '</p></div>';
		}
	);

	// Deactivate this plugin since Pro is already handling everything.
	add_action(
		'admin_init',
		function () {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);
	return;
}

// Check for base oOS plugin dependency.
if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: toolkit display name */
					__( 'oOS Toolkit - %s requires the NV Digital Open Operator System (oOS) base plugin to be installed and activated.', '${plugin_slug}' ),
					'${tk_name}'
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

// Define toolkit add-on constants.
define( 'WP_MCP_AI_TOOLKIT_${constant_prefix}_VERSION', '${VERSION}' );
define( 'WP_MCP_AI_TOOLKIT_${constant_prefix}_FILE', __FILE__ );
define( 'WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_AI_TOOLKIT_${constant_prefix}_URL', plugin_dir_url( __FILE__ ) );

/**
 * Auto-enable the toolkit setting on plugin activation.
 */
function wp_mcp_ai_toolkit_${tk_id//-/_}_activate() {
	\$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( \$settings['${tk_setting}'] ) ) {
		\$settings['${tk_setting}'] = '1';
		update_option( 'wp_mcp_ai_settings', \$settings );
	}
}
register_activation_hook( __FILE__, 'wp_mcp_ai_toolkit_${tk_id//-/_}_activate' );

/**
 * Initialize the toolkit add-on.
 *
 * Hooks into the oOS core to set up path constants that the toolkit init file
 * expects, then loads the original toolkit initialization file.
 */
function wp_mcp_ai_toolkit_${tk_id//-/_}_init() {
	// Ensure the toolkit setting is enabled.
	\$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( \$settings['${tk_setting}'] ) ) {
		return;
	}

	// Set the Pro path constant to point to this add-on's directory so that
	// the original toolkit init file can locate its includes.
	if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		define( 'WP_MCP_AI_PRO_PATH', WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH );
	}
	if ( ! defined( 'WP_MCP_AI_PRO_URL' ) ) {
		define( 'WP_MCP_AI_PRO_URL', WP_MCP_AI_TOOLKIT_${constant_prefix}_URL );
	}
	if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		define( 'WP_MCP_AI_PRO_VERSION', WP_MCP_AI_TOOLKIT_${constant_prefix}_VERSION );
	}

PHPEOF

    # Conditionally add vendor autoloader loading if this toolkit has vendor packages
    if [ "$has_vendor" = "true" ]; then
        local is_vendor_only="false"
        if [ "$tk_tools_dir" = "_none_" ] && [ "$tk_init" = "_none_" ]; then
            is_vendor_only="true"
        fi

        if [ "$is_vendor_only" = "true" ]; then
            # Vendor-only supplement (e.g., oos-toolkit-tcpdf).
            # Do NOT use the Composer autoloader — the main oOS Complete
            # plugin already loaded its own copy (same hash), and loading
            # another would fatal with "Cannot declare class
            # ComposerAutoloaderInit…". Instead, directly include the
            # library's own entry point so its classes become available
            # to the main plugin's existing autoloader.
            cat >> "$output_file" << 'VENDORONLYEOF'
	// Vendor-only supplement: directly include the library instead of
	// loading a duplicate Composer autoloader (which would fatal with
	// "Cannot declare class ComposerAutoloaderInit…" when the main
	// oOS Complete plugin has already loaded its autoloader).
	if ( version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
VENDORONLYEOF

            # Generate require_once for each vendor package's main entry point.
            IFS=',' read -ra VENDOR_ONLY_PKGS <<< "$tk_vendor"
            for vpkg in "${VENDOR_ONLY_PKGS[@]}"; do
                vpkg=$(echo "$vpkg" | xargs)
                case "$vpkg" in
                    tecnickcom/tcpdf)
                        cat >> "$output_file" << VENDORONLYEOF2
		\$tcpdf_main = WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH . 'vendor/tecnickcom/tcpdf/tcpdf.php';
		if ( file_exists( \$tcpdf_main ) ) {
			require_once \$tcpdf_main;
		}
VENDORONLYEOF2
                        ;;
                    *)
                        # Generic fallback: try the package's main file
                        local vpkg_dir="${vpkg#*/}"
                        cat >> "$output_file" << VENDORONLYEOF3
		\$vendor_file = WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH . 'vendor/${vpkg}/${vpkg_dir}.php';
		if ( file_exists( \$vendor_file ) ) {
			require_once \$vendor_file;
		}
VENDORONLYEOF3
                        ;;
                esac
            done

            cat >> "$output_file" << 'VENDORONLYEOF4'
	}
VENDORONLYEOF4
        else
            cat >> "$output_file" << 'VENDORPHPEOF'
	// Load Composer vendor autoloader for toolkit dependencies.
	// These packages require PHP 8.1+; tools gracefully degrade on older PHP.
	if ( version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
VENDORPHPEOF
            cat >> "$output_file" << VENDORPHPEOF2
		\$vendor_autoload = WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH . 'vendor/autoload.php';
VENDORPHPEOF2
            cat >> "$output_file" << 'VENDORPHPEOF3'
		if ( file_exists( $vendor_autoload ) ) {
			require_once $vendor_autoload;
		}
	}

VENDORPHPEOF3
        fi
    fi
    
    cat >> "$output_file" << PHPEOF
	// Load the toolkit init file.
	\$init_file = WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH . 'includes/${tk_init}';
	if ( file_exists( \$init_file ) ) {
		require_once \$init_file;
	}
}
add_action( 'plugins_loaded', 'wp_mcp_ai_toolkit_${tk_id//-/_}_init', 20 );

/**
 * Register toolkit-specific tools with the oOS tool registry.
 *
 * @param WP_MCP_AI_Tool_Registry \$registry The tool registry instance.
 */
function wp_mcp_ai_toolkit_${tk_id//-/_}_register_tools( \$registry ) {
	// Ensure the toolkit setting is enabled.
	\$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( \$settings['${tk_setting}'] ) ) {
		return;
	}

	\$toolkit_path = WP_MCP_AI_TOOLKIT_${constant_prefix}_PATH;
	\$tools_dir    = \$toolkit_path . 'includes/tools/${tk_tools_dir}/';

	// Auto-discover and register all tool classes in the tools directory.
	if ( '${tk_tools_dir}' !== '_none_' && is_dir( \$tools_dir ) ) {
		\$tool_files = glob( \$tools_dir . 'class-wp-mcp-ai-tool-*.php' );
		if ( \$tool_files ) {
			foreach ( \$tool_files as \$tool_file ) {
				require_once \$tool_file;

				// Derive class name from file name.
				\$basename   = basename( \$tool_file, '.php' );
				\$class_name = str_replace( '-', '_', \$basename );
				\$class_name = implode( '_', array_map( 'ucfirst', explode( '_', \$class_name ) ) );
				// Fix the 'Class_' prefix: class-wp-mcp-ai-tool-x -> Class_Wp_Mcp_Ai_Tool_X.
				// We need WP_MCP_AI_Tool_X format.
				\$class_name = preg_replace( '/^Class_/', '', \$class_name );
				// Normalize common abbreviations.
				\$class_name = str_replace(
					array( 'Wp_Mcp_Ai_', '_Pdf', '_Csv', '_Api', '_Ocr', '_Rtl', '_Seo', '_Hs_', '_Inci_', '_Ira_', '_Ml', '_Roi', '_Html_' ),
					array( 'WP_MCP_AI_', '_PDF', '_CSV', '_API', '_OCR', '_RTL', '_SEO', '_HS_', '_INCI_', '_IRA_', '_ML', '_ROI', '_HTML_' ),
					\$class_name
				);

				if ( class_exists( \$class_name ) ) {
					\$should_register = true;
					if ( method_exists( \$class_name, 'is_available' ) ) {
						\$should_register = (bool) call_user_func( array( \$class_name, 'is_available' ) );
					}
					if ( \$should_register ) {
						\$registry->register_tool( new \$class_name() );
					}
				}
			}
		}
	}

	// Also check for tools in the root tools directory that belong to this toolkit.
	\$root_tools_dir = \$toolkit_path . 'includes/tools/';
	if ( is_dir( \$root_tools_dir ) ) {
		\$root_tool_files = glob( \$root_tools_dir . 'class-wp-mcp-ai-tool-*.php' );
		if ( \$root_tool_files ) {
			foreach ( \$root_tool_files as \$tool_file ) {
				require_once \$tool_file;

				\$basename   = basename( \$tool_file, '.php' );
				\$class_name = str_replace( '-', '_', \$basename );
				\$class_name = implode( '_', array_map( 'ucfirst', explode( '_', \$class_name ) ) );
				\$class_name = preg_replace( '/^Class_/', '', \$class_name );
				\$class_name = str_replace(
					array( 'Wp_Mcp_Ai_', '_Pdf', '_Csv', '_Api', '_Ocr' ),
					array( 'WP_MCP_AI_', '_PDF', '_CSV', '_API', '_OCR' ),
					\$class_name
				);

				if ( class_exists( \$class_name ) ) {
					\$should_register = true;
					if ( method_exists( \$class_name, 'is_available' ) ) {
						\$should_register = (bool) call_user_func( array( \$class_name, 'is_available' ) );
					}
					if ( \$should_register ) {
						\$registry->register_tool( new \$class_name() );
					}
				}
			}
		}
	}
}
add_action( 'wp_mcp_ai_register_tools', 'wp_mcp_ai_toolkit_${tk_id//-/_}_register_tools', 25 );
PHPEOF
}

# ============================================================================
# Function: Build a single toolkit add-on ZIP
# ============================================================================
build_toolkit() {
    local toolkit_def="$1"
    
    IFS='|' read -r tk_id tk_name tk_setting tk_tools_dir tk_init tk_desc tk_extra tk_vendor <<< "$toolkit_def"
    
    local plugin_slug="oos-toolkit-${tk_id}"
    local build_dir="build/toolkit-addons/${plugin_slug}"
    local has_vendor="false"
    
    echo "  Building: ${tk_name} (${plugin_slug})..."
    
    # Clean previous build
    rm -rf "$build_dir"
    mkdir -p "$build_dir/includes"
    
    # 1. Copy vendor packages if needed (before generating bootstrapper)
    if [ -n "$tk_vendor" ]; then
        local is_vendor_only="false"
        if [ "$tk_tools_dir" = "_none_" ] && [ "$tk_init" = "_none_" ]; then
            is_vendor_only="true"
        fi
        if copy_vendor_packages "$build_dir" "$tk_vendor" "$is_vendor_only"; then
            has_vendor="true"
        fi
    fi
    
    # 2. Generate the bootstrapper plugin file
    generate_bootstrapper "$tk_id" "$tk_name" "$tk_setting" "$tk_tools_dir" "$tk_init" "$tk_desc" "${build_dir}/${plugin_slug}.php" "$has_vendor" "$tk_vendor"
    
    # 3. Copy the toolkit init file
    if [ "$tk_init" = "_none_" ]; then
        # Vendor-only toolkit (e.g., tcpdf): no init file needed.
        :
    elif [ -f "${PRO_DIR}/includes/${tk_init}" ]; then
        cp "${PRO_DIR}/includes/${tk_init}" "${build_dir}/includes/"
    else
        echo "    ⚠️  Init file not found: includes/${tk_init}"
    fi
    
    # 4. Copy the tools directory (if it exists and is not _none_)
    if [ "$tk_tools_dir" != "_none_" ] && [ -d "${PRO_DIR}/includes/tools/${tk_tools_dir}" ]; then
        mkdir -p "${build_dir}/includes/tools/${tk_tools_dir}"
        rsync -a --quiet "${PRO_DIR}/includes/tools/${tk_tools_dir}/" "${build_dir}/includes/tools/${tk_tools_dir}/"
        local tools_count
        tools_count=$(find "${build_dir}/includes/tools/${tk_tools_dir}" -name "class-wp-mcp-ai-tool-*.php" | wc -l)
        echo "    ✓ Copied ${tools_count} tool files from tools/${tk_tools_dir}/"
    fi
    
    # 5. Copy any root-level tool files that belong to this toolkit
    # (Some toolkits like media/healthcare have tools in includes/tools/ root)
    case "$tk_id" in
        media)
            mkdir -p "${build_dir}/includes/tools"
            for f in "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-*media-template*.php \
                     "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-*media-collection*.php \
                     "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-*collection-template*.php \
                     "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-process-collection.php \
                     "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-optimize-image-sharp.php; do
                [ -f "$f" ] && cp "$f" "${build_dir}/includes/tools/"
            done
            echo "    ✓ Copied media toolkit root-level tool files"
            ;;
        healthcare-imaging)
            mkdir -p "${build_dir}/includes/tools"
            for f in "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-manage-imaging-studies.php \
                     "${PRO_DIR}"/includes/tools/class-wp-mcp-ai-tool-interpret-imaging-study.php; do
                [ -f "$f" ] && cp "$f" "${build_dir}/includes/tools/"
            done
            echo "    ✓ Copied healthcare imaging root-level tool files"
            ;;
    esac
    
    # 6. Copy extra directories/files specified in the toolkit definition
    if [ -n "$tk_extra" ]; then
        IFS=',' read -ra EXTRA_ITEMS <<< "$tk_extra"
        for item in "${EXTRA_ITEMS[@]}"; do
            local src="${PRO_DIR}/${item}"
            if [ -d "$src" ]; then
                # It's a directory - copy it
                local dest_dir="${build_dir}/${item}"
                mkdir -p "$dest_dir"
                rsync -a --quiet "$src/" "$dest_dir/"
                echo "    ✓ Copied directory: ${item}/"
            elif [ -f "$src" ]; then
                # It's a file - ensure parent dir exists and copy
                local dest_parent
                dest_parent=$(dirname "${build_dir}/${item}")
                mkdir -p "$dest_parent"
                cp "$src" "${build_dir}/${item}"
                echo "    ✓ Copied file: ${item}"
            else
                # Try glob pattern
                local glob_parent
                glob_parent=$(dirname "$src")
                local glob_pattern
                glob_pattern=$(basename "$src")
                if [ -d "$glob_parent" ]; then
                    local dest_parent
                    dest_parent=$(dirname "${build_dir}/${item}")
                    mkdir -p "$dest_parent"
                    for matched_file in ${glob_parent}/${glob_pattern}; do
                        [ -f "$matched_file" ] && cp "$matched_file" "$dest_parent/"
                    done
                fi
            fi
        done
    fi
    
    # 7. Copy relevant CSS assets if they exist
    mkdir -p "${build_dir}/assets/css"
    local css_slug="${tk_id}"
    for css_file in "${PRO_DIR}/assets/css/admin-${css_slug}-toolkit"*.css \
                    "${PRO_DIR}/assets/css/${css_slug}-toolkit"*.css \
                    "${PRO_DIR}/assets/css/admin-${css_slug}"*.css; do
        [ -f "$css_file" ] && cp "$css_file" "${build_dir}/assets/css/" 2>/dev/null
    done
    # Remove empty assets directory if no CSS was found
    rmdir "${build_dir}/assets/css" 2>/dev/null || true
    rmdir "${build_dir}/assets" 2>/dev/null || true
    
    # 8. Copy relevant Gutenberg blocks if they exist
    local block_dir="${PRO_DIR}/includes/blocks/${tk_id}-block"
    if [ -d "$block_dir" ]; then
        mkdir -p "${build_dir}/includes/blocks/${tk_id}-block"
        rsync -a --quiet "$block_dir/" "${build_dir}/includes/blocks/${tk_id}-block/" \
            --exclude 'node_modules' \
            --exclude 'src'
        echo "    ✓ Copied Gutenberg block: ${tk_id}-block/"
    fi
    
    # 9. Create the ZIP
    cd "build/toolkit-addons"
    zip -r -q "${plugin_slug}-${VERSION}.zip" "${plugin_slug}/" -x "*.DS_Store" -x "*__MACOSX*"
    cd "$ROOT_DIR"
    
    # Clean up the build directory (keep only the ZIP)
    rm -rf "$build_dir"
    
    local zip_size
    zip_size=$(du -h "${OUTPUT_DIR}/${plugin_slug}-${VERSION}.zip" | cut -f1)
    echo "    ✅ ${plugin_slug}-${VERSION}.zip (${zip_size})"
    echo ""
}

# ============================================================================
# Build toolkit add-ons
# ============================================================================

BUILT_COUNT=0

if [ -n "$BUILD_SINGLE" ]; then
    # Build a specific toolkit
    FOUND=false
    for toolkit_def in "${TOOLKITS[@]}"; do
        IFS='|' read -r tk_id _ _ _ _ _ _ _ <<< "$toolkit_def"
        if [ "$tk_id" = "$BUILD_SINGLE" ]; then
            build_toolkit "$toolkit_def"
            BUILT_COUNT=$((BUILT_COUNT + 1))
            FOUND=true
            break
        fi
    done
    
    if [ "$FOUND" = false ]; then
        echo "❌ Error: Unknown toolkit '${BUILD_SINGLE}'"
        echo ""
        echo "Available toolkits:"
        for toolkit_def in "${TOOLKITS[@]}"; do
            IFS='|' read -r tk_id tk_name _ _ _ _ _ _ <<< "$toolkit_def"
            printf "  %-25s %s\n" "$tk_id" "$tk_name"
        done
        exit 1
    fi
else
    # Build all toolkits
    for toolkit_def in "${TOOLKITS[@]}"; do
        build_toolkit "$toolkit_def"
        BUILT_COUNT=$((BUILT_COUNT + 1))
    done
fi

# ============================================================================
# Summary
# ============================================================================
echo "=========================================="
echo "✅ Toolkit Add-on Build Complete!"
echo "=========================================="
echo ""
echo "📦 ${BUILT_COUNT} toolkit add-on(s) created in: ${OUTPUT_DIR}/"
echo ""
ls -lh "${OUTPUT_DIR}"/*.zip 2>/dev/null | awk '{print "   " $NF " (" $5 ")"}'
echo ""
echo "Each toolkit add-on:"
echo "  • Requires the base oOS plugin to be installed"
echo "  • Auto-enables its toolkit setting on activation"
echo "  • Won't conflict with the full Pro add-on (auto-deactivates if Pro detected)"
echo "  • Contains only toolkit-specific files for minimal footprint"
echo "  • Includes required Composer vendor packages (if needed) with PHP 8.1+ autoloader"
echo ""
echo "To install:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Upload the toolkit ZIP file"
echo "  3. Click 'Install Now' and then 'Activate'"
echo ""
