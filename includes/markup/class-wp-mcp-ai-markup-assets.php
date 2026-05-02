<?php
/**
 * Markup subsystem asset registration.
 *
 * Registers the Konva vendor bundle, the markup widget JS modules,
 * and the markup CSS so chat surfaces can lazily enqueue them on
 * demand.
 *
 * The assets are intentionally split into 4 small files so the chat
 * client can request only what it needs (export-only at first; widget
 * + Konva when an inline canvas is required).
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Markup_Assets
 *
 * Static-only helper that registers and enqueues the markup subsystem
 * client-side assets.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Markup_Assets {

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
		if ( wp_script_is( self::HANDLE_WIDGET, 'registered' ) ) {
			return;
		}

		$base_url  = WP_MCP_AI_URL;
		$base_path = WP_MCP_AI_PATH;

		// Konva (vendored, MIT).
		wp_register_script(
			self::HANDLE_KONVA,
			$base_url . 'assets/js/vendor/konva/konva-' . self::KONVA_VERSION . '.min.js',
			array(),
			self::KONVA_VERSION,
			true
		);

		// Export module (no DOM dependency, OK to load eagerly).
		wp_register_script(
			self::HANDLE_EXPORT,
			$base_url . 'assets/js/markup/markup-export.js',
			array(),
			self::asset_version( $base_path . 'assets/js/markup/markup-export.js' ),
			true
		);

		// Fallback module — depends on nothing; used when Konva is absent.
		wp_register_script(
			self::HANDLE_FALLBACK,
			$base_url . 'assets/js/markup/markup-fallback.js',
			array(),
			self::asset_version( $base_path . 'assets/js/markup/markup-fallback.js' ),
			true
		);

		// Main widget — pulls in Konva, export, fallback.
		wp_register_script(
			self::HANDLE_WIDGET,
			$base_url . 'assets/js/markup/markup-widget.js',
			array( self::HANDLE_KONVA, self::HANDLE_EXPORT, self::HANDLE_FALLBACK ),
			self::asset_version( $base_path . 'assets/js/markup/markup-widget.js' ),
			true
		);

		// Chat client shim — listens for tool result events and renders the widget.
		wp_register_script(
			self::HANDLE_CLIENT,
			$base_url . 'assets/js/markup/markup-client.js',
			array( self::HANDLE_WIDGET ),
			self::asset_version( $base_path . 'assets/js/markup/markup-client.js' ),
			true
		);

		// Stylesheet.
		wp_register_style(
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
		wp_enqueue_script( self::HANDLE_CLIENT );
		wp_enqueue_style( self::HANDLE_STYLE );
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
		wp_enqueue_script( self::HANDLE_EXPORT );
		wp_enqueue_script( self::HANDLE_FALLBACK );
		wp_enqueue_style( self::HANDLE_STYLE );
	}

	/**
	 * Compute a cache-busting version string for an asset path.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string
	 */
	private static function asset_version( $path ) {
		if ( file_exists( $path ) ) {
			$mtime = filemtime( $path );
			if ( $mtime ) {
				return defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION . '.' . $mtime : (string) $mtime;
			}
		}
		return defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0';
	}
}
