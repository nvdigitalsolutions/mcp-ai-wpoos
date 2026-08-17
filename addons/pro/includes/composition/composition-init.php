<?php
/**
 * Composition subsystem init (Proposal 029, Phase 5.2).
 *
 * Registers the flag-gated helper surface for the OOS composition
 * service. Everything here is OFF by default: without the
 * enable_oos_composition setting (or the wp_mcp_ai_pro_enable_oos_composition
 * filter) no helper functions are registered and no request path changes.
 *
 * The service itself (WP_MCP_AI_Pro_Composition_Service) is intentionally
 * usable without this gate — the CLI dump and tests construct it directly.
 * This gate controls boot-time exposure only.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Composition
 * @since   1.1.57
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_pro_composition_enabled' ) ) {
	/**
	 * Whether OOS composition (Proposal 029, Phase 5.2) is enabled.
	 *
	 * Off by default; enable via the enable_oos_composition admin setting
	 * or the wp_mcp_ai_pro_enable_oos_composition filter.
	 *
	 * @return bool
	 */
	function wp_mcp_ai_pro_composition_enabled(): bool {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( ! empty( $settings['enable_oos_composition'] ) ) {
			return true;
		}

		return (bool) apply_filters( 'wp_mcp_ai_pro_enable_oos_composition', false );
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_compose' ) && wp_mcp_ai_pro_composition_enabled() ) {
	/**
	 * Compose an assistant's effective toolset + config into a generation.
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $overrides    Optional overrides (see the service).
	 * @return WP_MCP_AI_Pro_Composition
	 */
	function wp_mcp_ai_pro_compose( int $assistant_id, array $overrides = array() ): WP_MCP_AI_Pro_Composition {
		$service = new WP_MCP_AI_Pro_Composition_Service();

		return $service->compose( $assistant_id, $overrides );
	}
}

if ( ! function_exists( 'wp_mcp_ai_pro_compose_from' ) && wp_mcp_ai_pro_composition_enabled() ) {
	/**
	 * Bind a child agent to its parent's exact composition generation.
	 *
	 * @param WP_MCP_AI_Pro_Composition $parent_composition Parent composition.
	 * @param int                       $child_assistant_id  Child assistant post ID.
	 * @param array                     $overrides           Optional overrides.
	 * @return WP_MCP_AI_Pro_Composition
	 */
	function wp_mcp_ai_pro_compose_from( WP_MCP_AI_Pro_Composition $parent_composition, int $child_assistant_id, array $overrides = array() ): WP_MCP_AI_Pro_Composition {
		$service = new WP_MCP_AI_Pro_Composition_Service();

		return $service->compose_from( $parent_composition, $child_assistant_id, $overrides );
	}
}
