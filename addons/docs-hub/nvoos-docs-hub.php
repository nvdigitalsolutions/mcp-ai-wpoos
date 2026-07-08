<?php
/**
 * Plugin Name: NV oOS Docs Hub
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: React-based documentation browser for NV oOS. Discovers, indexes, and renders Markdown docs from the base plugin and every addon in a GitBook-style SPA. Shortcode [nvoos_docs] embeds it on any page.
 * Version:     0.3.9
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: nvoos-docs-hub
 * Domain Path: /languages
 *
 * @package NV_oOS_Docs_Hub
 *
 * Copyright (c) 2025-2026 NV Digital Solutions (https://nvdigitalsolutions.com)
 * This plugin is licensed under the GNU General Public License v3 or later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_DOCS_HUB_VERSION', '0.3.9' );

/** Absolute path to this plugin file. */
define( 'NVOOS_DOCS_HUB_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_DOCS_HUB_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_DOCS_HUB_URL', plugin_dir_url( __FILE__ ) );

// Polyfill fnmatch() for Windows (POSIX-only function, unavailable on Windows PHP).
if ( ! function_exists( 'fnmatch' ) ) {
	/**
	 * Match filename against a shell glob pattern.
	 *
	 * Native polyfill for Windows where fnmatch() does not exist.
	 * Supports: * (any chars except /), ? (single char except /),
	 * [...] (character class), [!...] (negated class).
	 *
	 * @since 0.3.9
	 *
	 * @param string $pattern Shell glob pattern.
	 * @param string $string  String to match against.
	 * @param int    $flags   FNM_PATHNAME (1), FNM_NOESCAPE (2), FNM_PERIOD (4),
	 *                        FNM_CASEFOLD (16). 0 for default.
	 * @return bool True if the string matches the pattern.
	 */
	function fnmatch( $pattern, $string, $flags = 0 ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames -- $string matches native fnmatch() signature
		// Translate a shell glob into a PCRE pattern.
		$regex  = '';
		$len    = strlen( $pattern );
		$escape = ! ( $flags & 2 ); // FNM_NOESCAPE = 2.

		// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer -- inner case blocks intentionally advance $i past character classes
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $pattern[ $i ];

			if ( $escape && '\\' === $ch && $i + 1 < $len ) {
				// Escaped character — pass through literally.
				++$i;
				$regex .= preg_quote( $pattern[ $i ], '#' );
				continue;
			}

			switch ( $ch ) {
				case '*':
					// FNM_PATHNAME (1) => * does NOT match /.
					$regex .= ( $flags & 1 ) ? '[^/]*' : '.*';
					break;

				case '?':
					$regex .= ( $flags & 1 ) ? '[^/]' : '.';
					break;

				case '[':
					// Character class — consume until matching ].
					$class  = '[';
					$closed = false;
					++$i;
					if ( $i < $len && '!' === $pattern[ $i ] ) {
						$class .= '^';
						++$i;
					} elseif ( $i < $len && '^' === $pattern[ $i ] ) {
						$class .= '\\^';
						++$i;
					}
					for ( ; $i < $len; $i++ ) {
						if ( ']' === $pattern[ $i ] ) {
							$class .= ']';
							$closed = true;
							break;
						}
						$class .= '\\' . $pattern[ $i ];
					}
					// Unclosed bracket — treat as literal.
					$regex .= $closed ? $class : '\\[';
					break;

				default:
					$regex .= preg_quote( $ch, '#' );
					break;
			}
		}

		// Anchor and apply case-folding.
		$regex = '#^' . $regex . '$#';
		$mods  = 'us';
		if ( $flags & 16 ) { // FNM_CASEFOLD = 16.
			$mods .= 'i';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; polyfill handles regex errors gracefully
		$result = @preg_match( $regex . $mods, $string );
		return false !== $result && $result > 0;
	}
}

// Load core classes.
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-pipeline.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/shortcode/class-nvoos-docs-hub-shortcode.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/block/class-nvoos-docs-hub-block.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-sitemap-provider.php';
require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-link-fixer.php';

// Register rebuild job-source for the cron-status Tasks Drawer.
if ( interface_exists( 'Interface_WP_MCP_AI_Cron_Status_Job_Source' ) ) {
	require_once NVOOS_DOCS_HUB_PATH . 'includes/job-sources/class-nvoos-docs-hub-rebuild-job-source.php';
	add_filter(
		'wp_mcp_ai_cron_status_job_sources',
		static function ( array $sources ) {
			$sources['docs_hub_rebuild'] = new NV_oOS_Docs_Hub_Rebuild_Job_Source();
			return $sources;
		},
		10,
		1
	);
}

// Load admin classes.
if ( is_admin() ) {
	require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';
}

// Load WP-CLI command.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cli.php';
}

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 1.0.0
 *
 * @return bool True when the base plugin is available.
 */
function nvoos_docs_hub_is_base_active() {
	return defined( 'WP_MCP_AI_VERSION' );
}

/**
 * Check whether the docs hub addon is fully ready.
 *
 * @since 1.0.0
 *
 * @return bool True when the addon is operational.
 */
function nvoos_docs_hub_is_ready() {
	return nvoos_docs_hub_is_base_active() && NV_oOS_Docs_Hub_Plugin::is_enabled();
}

// Boot the plugin.
NV_oOS_Docs_Hub_Plugin::init();
