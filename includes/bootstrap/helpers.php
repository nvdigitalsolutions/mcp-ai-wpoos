<?php
/**
 * Global Helper Functions
 *
 * Early-available utility functions used throughout the plugin and during the
 * class-loading chain (before the DI container and service layer are available).
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_core_loaded' ) ) {
	/**
	 * Check if Open Operator System (NV oOS) Core is loaded.
	 *
	 * This function serves as a marker for add-ons (like Open Operator System Pro) to verify that
	 * the core plugin is active and ready before registering their features.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always returns true when plugin is loaded.
	 */
	function wp_mcp_ai_core_loaded() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_base_version' ) ) {
	/**
	 * Check if base version mode is enabled.
	 *
	 * Full version is enabled by default, providing all available tools.
	 * Base version mode is only active if explicitly set to true in wp-config.php:
	 * define( 'WP_MCP_AI_BASE_VERSION', true );
	 *
	 * Base version mode limits the plugin to core tools only, excluding tools that require
	 * third-party plugins (WooCommerce, JetEngine, Elementor, etc.) and external API integrations.
	 *
	 * @return bool Whether base version mode is active.
	 */
	function wp_mcp_ai_is_base_version() {
		return defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION;
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_jetengine_available' ) ) {
	/**
	 * Check if JetEngine plugin is available and active.
	 *
	 * @since 1.1.1
	 * @return bool Whether JetEngine is available.
	 */
	function wp_mcp_ai_is_jetengine_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}
}

if ( ! function_exists( 'wp_mcp_ai_should_load_integrations' ) ) {
	/**
	 * Determine whether third-party plugin integrations should be loaded.
	 *
	 * Integration classes are always loaded — they guard themselves against
	 * missing dependencies internally. This ensures that tools for WooCommerce,
	 * JetEngine, Cloudways, QuickBooks, etc. are available to any site that has
	 * those plugins active, regardless of whether the Pro addon is installed.
	 *
	 * The Pro addon (PHP 8.1+) adds genuinely new tools on top of these; it does
	 * not "unlock" tools that are already present in the base plugin.
	 *
	 * @since 1.1.0
	 * @return bool Always true — integration files are always loaded.
	 */
	function wp_mcp_ai_should_load_integrations() {
		return true;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_required_chat_capability' ) ) {
	/**
	 * Retrieve the capability required to access the chat interface.
	 *
	 * Site owners can filter the returned capability to relax access controls.
	 * For example, allow subscribers (with the `read` capability) or even
	 * unauthenticated visitors by returning `'public'` or an empty value.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID, when known.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_required_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );
		$context      = $context ? sanitize_key( $context ) : 'general';

		/**
		 * Filters the capability required to use the front-end chat interface.
		 *
		 * Returning `'public'`, `false`, or an empty string disables the capability
		 * check, making the chat available to all visitors who satisfy the
		 * authentication requirements.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability  Capability required to access the chat. Defaults to `edit_posts`.
		 * @param int    $assistant_id Assistant post ID, when available.
		 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
		 */
		$capability = apply_filters( 'wp_mcp_ai_chat_capability', 'edit_posts', $assistant_id, $context );

		if ( is_string( $capability ) ) {
			$capability = sanitize_key( $capability );
		}

		return $capability;
	}
}

if ( ! function_exists( 'wp_mcp_ai_get_effective_chat_capability' ) ) {
	/**
	 * Get the effective capability required for a specific assistant.
	 *
	 * This function checks the assistant's required_capability meta first,
	 * then falls back to the global capability filter.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $context      Context for the capability check (e.g. 'shortcode', 'rest').
	 *
	 * @return string|false Capability string. Return `'public'` to allow any visitor,
	 *                      or a falsy value to skip the check entirely.
	 */
	function wp_mcp_ai_get_effective_chat_capability( $assistant_id = 0, $context = 'general' ) {
		$assistant_id = absint( $assistant_id );

		// Check if assistant has a specific capability requirement.
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$required_capability = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_REQUIRED_CAPABILITY, true );

			if ( is_string( $required_capability ) ) {
				$required_capability = WP_MCP_AI_Assistant_CPT::sanitize_required_capability_meta( $required_capability );

				// If assistant has a specific capability set (even if empty), use it.
				if ( '' !== $required_capability ) {
					return $required_capability;
				}
			}
		}

		// Fall back to the global capability setting.
		return wp_mcp_ai_get_required_chat_capability( $assistant_id, $context );
	}
}

if ( ! function_exists( 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	/**
	 * Provide a fallback Crawl4AI base URL from the environment when available.
	 *
	 * @param string $base_url Base URL stored in the plugin settings.
	 * @param array  $settings Entire plugin settings array.
	 * @param array  $context  Execution context passed to the tool.
	 * @return string
	 */
	function wp_mcp_ai_filter_crawl4ai_base_url( $base_url, $settings, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter callback signature requires these parameters.
		if ( ! empty( $base_url ) ) {
			return $base_url;
		}

		if ( defined( 'WP_MCP_AI_CRAWL4AI_BASE_URL' ) && WP_MCP_AI_CRAWL4AI_BASE_URL ) {
			return WP_MCP_AI_CRAWL4AI_BASE_URL;
		}

		$environment_candidates = array(
			'WP_MCP_AI_CRAWL4AI_BASE_URL',
			'CRAWL4AI_BASE_URL',
		);

		foreach ( $environment_candidates as $env_key ) {
			$candidate = getenv( $env_key );
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return $candidate;
			}
		}

		return $base_url;
	}
}

if ( ! has_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url' ) ) {
	add_filter( 'wp_mcp_ai_crawl4ai_base_url', 'wp_mcp_ai_filter_crawl4ai_base_url', 5, 3 );
}
