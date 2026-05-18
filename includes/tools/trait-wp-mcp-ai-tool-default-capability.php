<?php
/**
 * Default capability trait for NV oOS tool classes.
 *
 * Phase 4 of the Unix Theory Compliance Enhancement Proposal makes
 * `get_required_capability()` a required member of `WP_MCP_AI_Tool_Interface`.
 * This trait provides a backward-compatible default implementation so that
 * existing tool classes that do not yet override the method still satisfy the
 * interface contract without introducing a PHP fatal error.
 *
 * Resolution order:
 *   1. `WP_MCP_AI_Tool_Capability_Map::get_capability( $this->get_slug() )` —
 *      honours the central capability map built during Phase P2b.
 *   2. `'edit_posts'` — safe, conservative fallback for tools that have not
 *      been explicitly classified.
 *
 * Any tool class that provides its own `get_required_capability()` method
 * takes precedence over the trait implementation — PHP's method-resolution
 * order ensures this automatically.
 *
 * Usage:
 * ```php
 * class WP_MCP_AI_Pro_Tool_Foo implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Default_Capability;
 *     // … other methods …
 * }
 * ```
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a default `get_required_capability()` implementation for tool
 * classes that do not declare one explicitly.
 */
trait WP_MCP_AI_Tool_Default_Capability {

	/**
	 * Return the WordPress capability required to execute this tool.
	 *
	 * Looks up the central capability map first; falls back to `'edit_posts'`.
	 *
	 * @return string WordPress capability string.
	 */
	public function get_required_capability() {
		if ( class_exists( 'WP_MCP_AI_Tool_Capability_Map' ) ) {
			$cap = WP_MCP_AI_Tool_Capability_Map::get_capability( $this->get_slug() );
			if ( null !== $cap ) {
				return $cap;
			}
		}

		return 'edit_posts';
	}
}
