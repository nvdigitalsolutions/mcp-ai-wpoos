#!/usr/bin/env bash
#
# scaffold-toolkit-spa.sh — Generate a new NV oOS toolkit-SPA addon from the
# Docs-Hub-derived blueprint.
#
# Usage:
#   ./bin/scaffold-toolkit-spa.sh <slug> "<Human-Readable Title>"
#
# Example:
#   ./bin/scaffold-toolkit-spa.sh canvas-toolkit "Canvas Toolkit"
#
# The script creates addons/<slug>/ pre-populated with:
#   - Plugin entry (nvoos-<slug>.php) with header + constants
#   - includes/   — plugin singleton, REST controller, shortcode, block
#   - src/        — minimal React TS entry that fetches the manifest
#   - assets/dist/ placeholder (re-build with `npm run build`)
#   - tests/      — PHPUnit shortcode + REST contract tests
#   - README.md   — with Credits section
#   - THIRD_PARTY_NOTICES.md
#   - package.json, esbuild.config.js, tsconfig.json
#   - languages/.gitkeep
#
# Reference: docs/addons/toolkit-spa-blueprint.md
#

set -euo pipefail

if [ "$#" -lt 2 ]; then
	echo "Usage: $0 <slug> \"<Human-Readable Title>\"" >&2
	echo "" >&2
	echo "  <slug>   kebab-case slug, e.g. 'canvas-toolkit'" >&2
	echo "  <Title>  Human-readable title in quotes, e.g. \"Canvas Toolkit\"" >&2
	exit 64
fi

SLUG="$1"
TITLE="$2"

# --- Validate slug --------------------------------------------------------
if ! [[ "$SLUG" =~ ^[a-z][a-z0-9-]{1,62}$ ]]; then
	echo "[scaffold] ERROR: slug must be lowercase, kebab-case, 2-63 chars: $SLUG" >&2
	exit 64
fi

# --- Derive case variants -------------------------------------------------
# UPPER_SNAKE: 'canvas-toolkit' -> 'CANVAS_TOOLKIT'
UPPER_SNAKE="$(echo "$SLUG" | tr 'a-z-' 'A-Z_')"
# lower_snake: 'canvas-toolkit' -> 'canvas_toolkit'
LOWER_SNAKE="$(echo "$SLUG" | tr '-' '_')"
# TitleSnake: 'canvas-toolkit' -> 'Canvas_Toolkit'
TITLE_SNAKE="$(echo "$SLUG" | awk -F'-' '{
	for (i=1; i<=NF; i++) $i = toupper(substr($i,1,1)) substr($i,2)
} 1' OFS='_')"

# --- Locate repo root -----------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ADDON_DIR="${REPO_ROOT}/addons/${SLUG}"

if [ -d "$ADDON_DIR" ]; then
	echo "[scaffold] ERROR: addon directory already exists: $ADDON_DIR" >&2
	exit 65
fi

echo "[scaffold] Creating addon: $TITLE ($SLUG)"
echo "[scaffold] Target directory: $ADDON_DIR"
echo "[scaffold] PHP class prefix: NV_oOS_${TITLE_SNAKE}_*"
echo "[scaffold] PHP constant prefix: NVOOS_${UPPER_SNAKE}_*"
echo "[scaffold] REST namespace: nvoos-${SLUG}/v1"

# --- Create directories ---------------------------------------------------
mkdir -p "$ADDON_DIR"/{includes/{admin,block,rest,shortcode,jobs},src/{api,components,routes,styles},assets/dist,tests,languages}

# --- nvoos-<slug>.php (plugin entry) --------------------------------------
cat > "$ADDON_DIR/nvoos-${SLUG}.php" <<EOF
<?php
/**
 * Plugin Name: NV oOS ${TITLE}
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: NV oOS ${TITLE} — React-based SPA surface for the NV oOS plugin. Generated from the Toolkit SPA Blueprint.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-${SLUG}
 * Domain Path: /languages
 *
 * @package NV_oOS_${TITLE_SNAKE}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version — must match package.json. */
define( 'NVOOS_${UPPER_SNAKE}_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_${UPPER_SNAKE}_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_${UPPER_SNAKE}_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_${UPPER_SNAKE}_URL', plugin_dir_url( __FILE__ ) );

require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/class-nvoos-${SLUG}-plugin.php';
require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/rest/class-nvoos-${SLUG}-rest.php';
require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/shortcode/class-nvoos-${SLUG}-shortcode.php';
require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/block/class-nvoos-${SLUG}-block.php';

NV_oOS_${TITLE_SNAKE}_Plugin::init();
EOF

# --- includes/class-nvoos-<slug>-plugin.php -------------------------------
cat > "$ADDON_DIR/includes/class-nvoos-${SLUG}-plugin.php" <<EOF
<?php
/**
 * NV oOS ${TITLE} — Core Plugin Class
 *
 * @package NV_oOS_${TITLE_SNAKE}
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS ${TITLE} addon.
 *
 * @since 0.1.0
 */
class NV_oOS_${TITLE_SNAKE}_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_${LOWER_SNAKE}_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_${TITLE_SNAKE}_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_${TITLE_SNAKE}_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_${TITLE_SNAKE}_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
	}

	/**
	 * Render an admin notice when the pre-built SPA bundle is missing.
	 *
	 * Mirrors the SaaS Controller pattern: when assets/dist/<slug>.js is not
	 * present, operators see a clear error instead of a silent broken widget.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_bundle_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		\$bundle = NVOOS_${UPPER_SNAKE}_PATH . 'assets/dist/${SLUG}.js';
		if ( file_exists( \$bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/${SLUG} && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS ${TITLE}:', 'nvoos-${SLUG}' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-${SLUG}' )
		);
	}
}
EOF

# --- includes/rest/class-nvoos-<slug>-rest.php ----------------------------
cat > "$ADDON_DIR/includes/rest/class-nvoos-${SLUG}-rest.php" <<EOF
<?php
/**
 * NV oOS ${TITLE} — REST API Controller
 *
 * @package NV_oOS_${TITLE_SNAKE}
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS ${TITLE} addon.
 *
 * @since 0.1.0
 */
class NV_oOS_${TITLE_SNAKE}_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-${SLUG}/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-${SLUG}' ), array( 'status' => 403 ) );
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_${UPPER_SNAKE}_VERSION' ) ? NVOOS_${UPPER_SNAKE}_VERSION : 'unknown',
			)
		);
	}
}
EOF

# --- includes/shortcode/class-nvoos-<slug>-shortcode.php ------------------
cat > "$ADDON_DIR/includes/shortcode/class-nvoos-${SLUG}-shortcode.php" <<EOF
<?php
/**
 * NV oOS ${TITLE} — Shortcode
 *
 * @package NV_oOS_${TITLE_SNAKE}
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler.
 *
 * @since 0.1.0
 */
class NV_oOS_${TITLE_SNAKE}_Shortcode {

	const SHORTCODE = 'nvoos_${LOWER_SNAKE}_app';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array \$atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( \$atts ) {
		\$atts = shortcode_atts(
			array(
				'toolkit' => '',
				'theme'   => 'auto',
				'view'    => '',
				'height'  => '',
			),
			\$atts,
			self::SHORTCODE
		);

		\$can_render = apply_filters( 'nvoos_${LOWER_SNAKE}_can_render', true, \$atts );
		if ( ! \$can_render ) {
			return '';
		}

		\$config = array(
			'toolkit' => sanitize_key( \$atts['toolkit'] ),
			'theme'   => in_array( \$atts['theme'], array( 'auto', 'light', 'dark' ), true ) ? \$atts['theme'] : 'auto',
			'view'    => sanitize_key( \$atts['view'] ),
			'height'  => sanitize_text_field( \$atts['height'] ),
		);

		self::enqueue_assets( \$config );

		\$config_json = wp_json_encode( \$config );
		if ( false === \$config_json ) {
			\$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-${SLUG}-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( '${TITLE}', 'nvoos-${SLUG}' ) ),
			esc_attr( \$config_json )
		);
	}

	/**
	 * Enqueue the SPA bundle.
	 *
	 * @param array \$config Per-instance config.
	 * @return void
	 */
	public static function enqueue_assets( \$config ) {
		wp_register_style(
			'nvoos-${SLUG}',
			NVOOS_${UPPER_SNAKE}_URL . 'assets/dist/${SLUG}.css',
			array(),
			NVOOS_${UPPER_SNAKE}_VERSION
		);
		wp_register_script(
			'nvoos-${SLUG}',
			NVOOS_${UPPER_SNAKE}_URL . 'assets/dist/${SLUG}.js',
			array( 'wp-i18n' ),
			NVOOS_${UPPER_SNAKE}_VERSION,
			true
		);
		wp_set_script_translations(
			'nvoos-${SLUG}',
			'nvoos-${SLUG}',
			NVOOS_${UPPER_SNAKE}_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-${SLUG}',
			'NVOOS_${UPPER_SNAKE}',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_${TITLE_SNAKE}_REST::REST_NAMESPACE ) ),
				'proApi' => esc_url_raw( rest_url( 'mcp-ai-pro/v1' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => \$config,
			)
		);
		wp_enqueue_style( 'nvoos-${SLUG}' );
		wp_enqueue_script( 'nvoos-${SLUG}' );
	}
}
EOF

# --- includes/block/block.json + class -----------------------------------
cat > "$ADDON_DIR/includes/block/block.json" <<EOF
{
  "apiVersion": 2,
  "name": "nvoos/${SLUG}",
  "title": "NV oOS ${TITLE}",
  "category": "widgets",
  "icon": "admin-generic",
  "description": "Embeds the NV oOS ${TITLE} SPA.",
  "textdomain": "nvoos-${SLUG}",
  "attributes": {
    "toolkit": { "type": "string", "default": "" },
    "theme":   { "type": "string", "default": "auto" },
    "view":    { "type": "string", "default": "" },
    "height":  { "type": "string", "default": "" }
  },
  "supports": { "html": false }
}
EOF

cat > "$ADDON_DIR/includes/block/class-nvoos-${SLUG}-block.php" <<EOF
<?php
/**
 * NV oOS ${TITLE} — Gutenberg Block
 *
 * @package NV_oOS_${TITLE_SNAKE}
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block.
 *
 * @since 0.1.0
 */
class NV_oOS_${TITLE_SNAKE}_Block {

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			__DIR__ . '/block.json',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array \$attributes Block attributes.
	 * @return string
	 */
	public static function render( \$attributes ) {
		\$atts = array(
			'toolkit' => isset( \$attributes['toolkit'] ) ? sanitize_key( \$attributes['toolkit'] ) : '',
			'theme'   => isset( \$attributes['theme'] ) ? sanitize_text_field( \$attributes['theme'] ) : 'auto',
			'view'    => isset( \$attributes['view'] ) ? sanitize_key( \$attributes['view'] ) : '',
			'height'  => isset( \$attributes['height'] ) ? sanitize_text_field( \$attributes['height'] ) : '',
		);
		return NV_oOS_${TITLE_SNAKE}_Shortcode::render( \$atts );
	}
}
EOF

# --- src/index.tsx + App.tsx ---------------------------------------------
cat > "$ADDON_DIR/src/index.tsx" <<'EOF'
/**
 * NV oOS Toolkit SPA — entry point.
 *
 * Mounts the React app into every matching root container on the page.
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles/main.css';

// Load @axe-core/react in development builds for live accessibility audit output.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds,
// making this block dead code that is eliminated by tree-shaking.
if ( process.env.NODE_ENV !== 'production' ) {
	Promise.all( [
		import( 'react' ),
		import( 'react-dom' ),
		import( '@axe-core/react' ),
	] ).then( ( [ React, ReactDOM, axe ] ) => {
		axe.default( React, ReactDOM, 1000 );
	} ).catch( () => { /* axe unavailable */ } );
}

declare global {
	interface Window {
		// Each addon localizes its own global; the App reads window[GLOBAL_NAME].
		[key: string]: unknown;
	}
}

function mountAll() {
	const containers = document.querySelectorAll<HTMLElement>(
		// The root selector is templated per-addon by the scaffold script.
		// See the .nvoos-<slug>-root class in the shortcode renderer.
		'[class*="-root"][data-config]'
	);
	containers.forEach( ( container ) => {
		try {
			const raw = container.dataset.config ?? '{}';
			const config = JSON.parse( raw );
			const root = createRoot( container );
			root.render( <App config={ config } /> );
		} catch {
			// Invalid JSON in data-config — render fallback.
			container.textContent = 'Configuration error.';
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
EOF

cat > "$ADDON_DIR/src/App.tsx" <<EOF
/**
 * NV oOS ${TITLE} — root component.
 *
 * Replace this stub with a real implementation. The component receives
 * its per-instance config (toolkit, theme, view, height) via props.
 */

import { useEffect, useState } from 'react';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: string;
		view?: string;
		height?: string;
	};
}

export function App( { config }: AppProps ) {
	const [ status, setStatus ] = useState<string>( 'loading' );

	useEffect( () => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const g = ( window as any ).NVOOS_${UPPER_SNAKE};
		if ( ! g?.apiUrl ) {
			setStatus( 'no-config' );
			return;
		}
		fetch( g.apiUrl + '/health', {
			headers: { 'X-WP-Nonce': g.nonce ?? '' },
		} )
			.then( ( r ) => r.json() )
			.then( () => setStatus( 'ready' ) )
			.catch( () => setStatus( 'error' ) );
	}, [] );

	return (
		<div className="nvoos-${SLUG}-app" data-theme={ config.theme ?? 'auto' }>
			<header>
				<h2>NV oOS ${TITLE}</h2>
			</header>
			<main>
				<p>Status: { status }</p>
				<p>Toolkit: { config.toolkit ?? '(none)' }</p>
				<p>View: { config.view ?? '(default)' }</p>
			</main>
		</div>
	);
}
EOF

cat > "$ADDON_DIR/src/styles/main.css" <<EOF
.nvoos-${SLUG}-app {
	font: inherit;
	color: inherit;
}
.nvoos-${SLUG}-app[data-theme="dark"] {
	background: #111;
	color: #eee;
}
EOF

# --- package.json --------------------------------------------------------
cat > "$ADDON_DIR/package.json" <<EOF
{
  "name": "nvoos-${SLUG}",
  "version": "0.1.0",
  "description": "NV oOS ${TITLE} — React SPA addon",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "node esbuild.config.cjs --prod",
    "build:dev": "node esbuild.config.cjs",
    "watch": "node esbuild.config.cjs --watch",
    "typecheck": "tsc --noEmit",
    "lint:a11y": "eslint --max-warnings 0 src/"
  },
  "dependencies": {
    "react": "19.1.0",
    "react-dom": "19.1.0"
  },
  "devDependencies": {
    "@axe-core/react": "4.10.2",
    "@types/node": "22.15.17",
    "@types/react": "19.1.4",
    "@types/react-dom": "19.1.4",
    "@typescript-eslint/parser": "8.32.0",
    "@wordpress/i18n": "5.12.0",
    "esbuild": "0.25.4",
    "eslint": "9.27.0",
    "eslint-plugin-jsx-a11y": "6.10.2",
    "typescript": "5.8.3"
  }
}
EOF

# --- eslint.config.js -------------------------------------------------------
cat > "$ADDON_DIR/eslint.config.js" <<'ESLINTEOF'
/**
 * ESLint flat config — NV oOS SPA Addon.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on all React TSX/JSX sources.
 *
 * @see https://github.com/jsx-eslint/eslint-plugin-jsx-a11y
 */
// @ts-check
import tsParser from '@typescript-eslint/parser';
import jsxA11y from 'eslint-plugin-jsx-a11y';

/** @type {import('eslint').Linter.Config[]} */
export default [
	// a11y rules for all TSX/JSX sources
	{
		...jsxA11y.flatConfigs.recommended,
		files: [ 'src/**/*.{ts,tsx,js,jsx}' ],
		languageOptions: {
			parser: tsParser,
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		// Allow inline disable comments for @typescript-eslint/* rules that are
		// handled by tsc/typecheck rather than this a11y-scoped ESLint config.
		linterOptions: {
			reportUnusedDisableDirectives: 'off',
		},
	},
];
ESLINTEOF

# --- esbuild.config.cjs -------------------------------------------------
# (CJS extension required because package.json has "type":"module")
cat > "$ADDON_DIR/esbuild.config.cjs" <<'EOF'
'use strict';

const esbuild = require( 'esbuild' );
const path    = require( 'path' );
const fs      = require( 'fs' );

const args    = process.argv.slice( 2 );
const isProd  = args.includes( '--prod' );
const isWatch = args.includes( '--watch' );

const outDir = path.resolve( __dirname, 'assets', 'dist' );
fs.mkdirSync( outDir, { recursive: true } );

/** @type {import('esbuild').BuildOptions} */

/**
 * esbuild plugin — maps @wordpress/i18n imports to window.wp.i18n.
 * WordPress loads wp.i18n via the wp-i18n script dependency.
 */
const wpI18nPlugin = {
	name: 'wp-i18n-external',
	setup( build ) {
		build.onResolve( { filter: /^@wordpress\/i18n\$/ }, ( args ) => ( {
			path: args.path,
			namespace: 'wp-i18n-ns',
		} ) );
		build.onLoad( { filter: /.*/, namespace: 'wp-i18n-ns' }, () => ( {
			contents: \`module.exports = window.wp.i18n;\`,
			loader: 'js',
		} ) );
	},
};

/** @type {import('esbuild').BuildOptions} */
const buildOptions = {
	entryPoints: [ path.resolve( __dirname, 'src', 'index.tsx' ) ],
	bundle:      true,
	outfile:     path.join( outDir, '${SLUG}.js' ),
	format:      'iife',
	globalName:  'NVoOS_${TITLE_SNAKE}',
	platform:    'browser',
	target:      [ 'es2017', 'chrome80', 'firefox78', 'safari13' ],
	jsx:         'automatic',
	loader:      { '.css': 'css', '.ts': 'ts', '.tsx': 'tsx' },
	define:      { 'process.env.NODE_ENV': isProd ? '"production"' : '"development"' },
	minify:      isProd,
	sourcemap:   ! isProd,
	treeShaking: true,
	plugins:     [
		wpI18nPlugin,
		{
		name: 'css-extract',
		setup( build ) {
			build.onEnd( () => {
				const def = path.join( outDir, '${SLUG}.css' );
				const alt = path.join( outDir, 'index.css' );
				if ( ! fs.existsSync( def ) && fs.existsSync( alt ) ) {
					fs.renameSync( alt, def );
				}
			} );
		},
	} ],
	logLevel: 'info',
};

if ( isWatch ) {
	esbuild.context( buildOptions ).then( ( ctx ) => ctx.watch() );
} else {
	esbuild.build( buildOptions ).catch( () => process.exit( 1 ) );
}
EOF

# --- tsconfig.json -------------------------------------------------------
cat > "$ADDON_DIR/tsconfig.json" <<'EOF'
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "lib": ["DOM", "DOM.Iterable", "ES2020"],
    "jsx": "react-jsx",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "resolveJsonModule": true,
    "noEmit": true,
    "isolatedModules": true
  },
  "include": ["src/**/*"]
}
EOF

# --- .gitignore ----------------------------------------------------------
cat > "$ADDON_DIR/.gitignore" <<'EOF'
node_modules/
*.log
.cache/
.tsbuildinfo
EOF

# --- README.md -----------------------------------------------------------
cat > "$ADDON_DIR/README.md" <<EOF
# NV oOS ${TITLE}

React SPA addon for NV oOS, scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md).

## Quick start

\`\`\`bash
cd addons/${SLUG}
npm ci
npm run build       # produces assets/dist/${SLUG}.{js,css}
\`\`\`

Add \`[nvoos_${LOWER_SNAKE}_app]\` to any post or page.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. \`Version:\` header in \`nvoos-${SLUG}.php\`
2. \`define( 'NVOOS_${UPPER_SNAKE}_VERSION', '…' );\`
3. \`"version"\` in \`package.json\`

This forces \`?ver=\` query strings to invalidate browser caches.

## REST namespace

\`/wp-json/nvoos-${SLUG}/v1/*\` — see [\`includes/rest/class-nvoos-${SLUG}-rest.php\`](includes/rest/class-nvoos-${SLUG}-rest.php).

## Credits

This addon is a scaffold only — no third-party SPA libraries are bundled by
default beyond React. When adding upstream packages, update:

- [\`THIRD_PARTY_NOTICES.md\`](THIRD_PARTY_NOTICES.md)
- The root [\`CREDITS.md\`](../../CREDITS.md)
- This Credits section
EOF

# --- THIRD_PARTY_NOTICES.md ----------------------------------------------
cat > "$ADDON_DIR/THIRD_PARTY_NOTICES.md" <<EOF
# Third-Party Notices — NV oOS ${TITLE}

This addon bundles the following third-party software. Each entry retains its
upstream license; the per-package license texts are reproduced here.

| Package | Version | License | Source |
|---------|---------|---------|--------|
| react | 19.1.0 | MIT | https://github.com/facebook/react |
| react-dom | 19.1.0 | MIT | https://github.com/facebook/react |

When adding a new dependency, append a row above and reproduce the upstream
license text below.

---

## React (MIT)

Copyright (c) Meta Platforms, Inc. and affiliates.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
EOF

# --- uninstall.php -------------------------------------------------------
cat > "$ADDON_DIR/uninstall.php" <<EOF
<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_${TITLE_SNAKE}
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_${LOWER_SNAKE}_settings' );
EOF

# --- tests/ --------------------------------------------------------------
cat > "$ADDON_DIR/tests/test-shortcode.php" <<EOF
<?php
/**
 * Shortcode tests.
 *
 * @package NV_oOS_${TITLE_SNAKE}
 */

class Test_${TITLE_SNAKE}_Shortcode extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_${UPPER_SNAKE}_VERSION' ) ) {
			define( 'NVOOS_${UPPER_SNAKE}_VERSION', '0.1.0' );
		}
		if ( ! defined( 'NVOOS_${UPPER_SNAKE}_PATH' ) ) {
			define( 'NVOOS_${UPPER_SNAKE}_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_${UPPER_SNAKE}_URL' ) ) {
			define( 'NVOOS_${UPPER_SNAKE}_URL', 'http://example.com/wp-content/plugins/nvoos-${SLUG}/' );
		}
		require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/rest/class-nvoos-${SLUG}-rest.php';
		require_once NVOOS_${UPPER_SNAKE}_PATH . 'includes/shortcode/class-nvoos-${SLUG}-shortcode.php';
	}

	public function test_shortcode_returns_root_container() {
		\$out = NV_oOS_${TITLE_SNAKE}_Shortcode::render( array() );
		\$this->assertStringContainsString( 'nvoos-${SLUG}-root', \$out );
		\$this->assertStringContainsString( 'data-config', \$out );
	}

	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_${LOWER_SNAKE}_can_render', '__return_false' );
		\$out = NV_oOS_${TITLE_SNAKE}_Shortcode::render( array() );
		\$this->assertSame( '', \$out );
		remove_filter( 'nvoos_${LOWER_SNAKE}_can_render', '__return_false' );
	}
}
EOF

cat > "$ADDON_DIR/tests/test-rest.php" <<EOF
<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_${TITLE_SNAKE}
 */

class Test_${TITLE_SNAKE}_REST extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_${UPPER_SNAKE}_VERSION' ) ) {
			define( 'NVOOS_${UPPER_SNAKE}_VERSION', '0.1.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-${SLUG}-rest.php';
	}

	public function test_health_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		\$result = NV_oOS_${TITLE_SNAKE}_REST::admin_permission();
		\$this->assertInstanceOf( 'WP_Error', \$result );
	}
}
EOF

# --- assets/dist/ placeholder + .htaccess + index.php --------------------
cat > "$ADDON_DIR/assets/dist/.gitkeep" <<EOF
# Pre-built SPA artifacts go here. Run \`npm run build\` to generate
# ${SLUG}.js and ${SLUG}.css. Both must be committed.
EOF

cat > "$ADDON_DIR/languages/.gitkeep" <<'EOF'
# Generated .pot / .po / .mo files live here.
EOF

# --- Patch CI workflow files to include the new addon ----------------------
#
# spa-a11y.yml  : adds the new addon's src/**  + eslint.config.js to both
#                 push/pull_request path filters, and appends to the matrix.
# spa-bundle-size.yml : adds src/**, esbuild.config.cjs, package.json to
#                 both path filters, and appends a 200 KB matrix entry.
#
# Uses Python (always available in CI) to avoid complex multi-line sed
# escaping.  Variables interpolated via non-quoted heredoc delimiter.

_patch_yml() {
	local YML_FILE="$1"
	local ADDON_SLUG="$2"

	if [ ! -f "$YML_FILE" ]; then
		echo "[scaffold] WARNING: $YML_FILE not found — skipping CI patch."
		return 0
	fi

	python3 - "$YML_FILE" "$ADDON_SLUG" <<-'PY'
		import sys

		path, slug = sys.argv[1], sys.argv[2]

		with open(path) as fh:
			text = fh.read()

		# Bail out if the addon is already registered (idempotent).
		if "addons/" + slug + "/src/**" in text:
			sys.exit(0)

		filename = path.split("/")[-1]

		if filename == "spa-a11y.yml":
			# Extend both push + pull_request path filters (same anchor appears twice).
			OLD_PATHS = "      - 'addons/media-studio/eslint.config.js'"
			NEW_PATHS = (
				OLD_PATHS
				+ "\n      - 'addons/" + slug + "/src/**'"
				+ "\n      - 'addons/" + slug + "/eslint.config.js'"
			)
			text = text.replace(OLD_PATHS, NEW_PATHS)

			# Extend the matrix addon list.
			OLD_MATRIX = "          - media-studio"
			NEW_MATRIX = OLD_MATRIX + "\n          - " + slug
			text = text.replace(OLD_MATRIX, NEW_MATRIX, 1)

		elif filename == "spa-bundle-size.yml":
			# Extend both push + pull_request path filters.
			OLD_PATHS = "      - 'addons/media-studio/package.json'"
			NEW_PATHS = (
				OLD_PATHS
				+ "\n      - 'addons/" + slug + "/src/**'"
				+ "\n      - 'addons/" + slug + "/esbuild.config.cjs'"
				+ "\n      - 'addons/" + slug + "/package.json'"
			)
			text = text.replace(OLD_PATHS, NEW_PATHS)

			# Extend the matrix include list after the media-studio entry.
			OLD_MATRIX = "            limit_kb: 900"
			NEW_MATRIX = (
				OLD_MATRIX
				+ "\n          - addon: " + slug
				+ "\n            limit_kb: 200"
			)
			text = text.replace(OLD_MATRIX, NEW_MATRIX, 1)

		with open(path, "w") as fh:
			fh.write(text)
	PY

	echo "[scaffold] Patched ${YML_FILE##*/}"
}

A11Y_YML="${REPO_ROOT}/.github/workflows/spa-a11y.yml"
BUNDLE_YML="${REPO_ROOT}/.github/workflows/spa-bundle-size.yml"

_patch_yml "$A11Y_YML"    "$SLUG"
_patch_yml "$BUNDLE_YML"  "$SLUG"

# ---------------------------------------------------------------------------
echo ""
echo "[scaffold] Done. Next steps:"
echo "  1. cd addons/${SLUG}"
echo "  2. npm install && npm run build"
echo "  3. Edit includes/rest/class-nvoos-${SLUG}-rest.php to add your routes."
echo "  4. Edit src/App.tsx to render your SPA."
echo "  5. Add a slim .github/agents/${SLUG}-maintainer.agent.md (see examples/agents/toolkit-spa-maintainer.agent.md)."
echo "  6. Update AGENTS.md inventory and CREDITS.md."
echo "  7. Commit. Bump version once before opening the PR."
