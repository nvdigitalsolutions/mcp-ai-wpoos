<?php
/**
 * Markup asset registration (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Markup_Assets`
 * (`includes/markup/`): byte-identical handles
 * (`wp-mcp-ai-konva`, `wp-mcp-ai-markup-export|fallback|widget|client`,
 * `wp-mcp-ai-markup-style`), the Konva 9.3.16 vendor bundle, the
 * dependency graph (widget → konva/export/fallback, client → widget),
 * the idempotent `register()`, the `enqueue_widget()` /
 * `enqueue_minimal()` surfaces, and the filemtime cache-busting
 * version helper.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - Asset URLs/paths resolve per install mode via `asset_base_url()` /
 *    `asset_base_path()`: base constants monolith, this addon's
 *    constants standalone (the asset files are copied byte-identically
 *    into this addon's assets tree). Handles stay byte-identical
 *    (public surface per gap 8.4) — the ported class registers them
 *    standalone-only, so no handle collision exists monolith.
 *  - The version suffix resolves per mode: base `WP_MCP_AI_VERSION`
 *    monolith, `NVOOS_CONTENT_GRAPH_AI_VERSION` standalone.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Static-only helper that registers and enqueues the markup subsystem
 * client-side assets.
 *
 * @since 1.1.0
 */
class MarkupAssets {

	/**
	 * Konva bundle version.
	 *
	 * @var string
	 */
	const KONVA_VERSION = '9.3.16';

	/**
	 * Script handle for Konva.
	 */
	const HANDLE_KONVA = 'wp-mcp-ai-konva';

	/**
	 * Script handle for the export module.
	 */
	const HANDLE_EXPORT = 'wp-mcp-ai-markup-export';

	/**
	 * Script handle for the fallback module.
	 */
	const HANDLE_FALLBACK = 'wp-mcp-ai-markup-fallback';

	/**
	 * Script handle for the main widget.
	 */
	const HANDLE_WIDGET = 'wp-mcp-ai-markup-widget';

	/**
	 * Script handle for the chat client integration shim.
	 */
	const HANDLE_CLIENT = 'wp-mcp-ai-markup-client';

	/**
	 * Style handle.
	 */
	const HANDLE_STYLE = 'wp-mcp-ai-markup-style';

	/**
	 * Register all markup assets.
	 *
	 * Idempotent — safe to call multiple times.
	 *
	 * @return void
	 */
	public static function register() {
		if ( \wp_script_is( self::HANDLE_WIDGET, 'registered' ) ) {
			return;
		}

		$base_url  = self::asset_base_url();
		$base_path = self::asset_base_path();

		// Konva (vendored, MIT).
		\wp_register_script(
			self::HANDLE_KONVA,
			$base_url . 'assets/js/vendor/konva/konva-' . self::KONVA_VERSION . '.min.js',
			array(),
			self::KONVA_VERSION,
			true
		);

		// Export module (no DOM dependency, OK to load eagerly).
		\wp_register_script(
			self::HANDLE_EXPORT,
			$base_url . 'assets/js/markup/markup-export.js',
			array(),
			self::asset_version( $base_path . 'assets/js/markup/markup-export.js' ),
			true
		);

		// Fallback module — depends on nothing; used when Konva is absent.
		\wp_register_script(
			self::HANDLE_FALLBACK,
			$base_url . 'assets/js/markup/markup-fallback.js',
			array(),
			self::asset_version( $base_path . 'assets/js/markup/markup-fallback.js' ),
			true
		);

		// Main widget — pulls in Konva, export, fallback.
		\wp_register_script(
			self::HANDLE_WIDGET,
			$base_url . 'assets/js/markup/markup-widget.js',
			array( self::HANDLE_KONVA, self::HANDLE_EXPORT, self::HANDLE_FALLBACK ),
			self::asset_version( $base_path . 'assets/js/markup/markup-widget.js' ),
			true
		);

		// Chat client shim — listens for tool result events and renders the widget.
		\wp_register_script(
			self::HANDLE_CLIENT,
			$base_url . 'assets/js/markup/markup-client.js',
			array( self::HANDLE_WIDGET ),
			self::asset_version( $base_path . 'assets/js/markup/markup-client.js' ),
			true
		);

		// Stylesheet.
		\wp_register_style(
			self::HANDLE_STYLE,
			$base_url . 'assets/css/markup.css',
			array(),
			self::asset_version( $base_path . 'assets/css/markup.css' )
		);
	}

	/**
	 * Enqueue the full inline canvas widget (Konva + widget + style).
	 *
	 * Call this from chat surfaces that may host a markup elicitation.
	 *
	 * @return void
	 */
	public static function enqueue_widget() {
		self::register();
		\wp_enqueue_script( self::HANDLE_CLIENT );
		\wp_enqueue_style( self::HANDLE_STYLE );
	}

	/**
	 * Enqueue only the export + fallback modules (no Konva).
	 *
	 * Useful when the host can submit a pre-built envelope but does
	 * not need the inline editor (e.g. external MCP client renders
	 * its own UI and uses our REST endpoint to submit).
	 *
	 * @return void
	 */
	public static function enqueue_minimal() {
		self::register();
		\wp_enqueue_script( self::HANDLE_EXPORT );
		\wp_enqueue_script( self::HANDLE_FALLBACK );
		\wp_enqueue_style( self::HANDLE_STYLE );
	}

	/**
	 * Resolve the asset URL base for this install mode.
	 *
	 * @return string
	 */
	protected static function asset_base_url() {
		return defined( 'WP_MCP_AI_PATH' ) && defined( 'WP_MCP_AI_URL' )
			? WP_MCP_AI_URL
			: NVOOS_CONTENT_GRAPH_AI_URL;
	}

	/**
	 * Resolve the asset path base for this install mode.
	 *
	 * @return string
	 */
	protected static function asset_base_path() {
		return defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_PATH
			: NVOOS_CONTENT_GRAPH_AI_PATH;
	}

	/**
	 * Compute a cache-busting version string for an asset path.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string
	 */
	private static function asset_version( $path ) {
		if ( \file_exists( $path ) ) {
			$mtime = \filemtime( $path );
			if ( $mtime ) {
				return self::plugin_version() . '.' . $mtime;
			}
		}
		return self::plugin_version();
	}

	/**
	 * Resolve the plugin version for this install mode.
	 *
	 * @return string
	 */
	private static function plugin_version() {
		if ( defined( 'WP_MCP_AI_PATH' ) && defined( 'WP_MCP_AI_VERSION' ) ) {
			return WP_MCP_AI_VERSION;
		}

		return NVOOS_CONTENT_GRAPH_AI_VERSION;
	}
}
