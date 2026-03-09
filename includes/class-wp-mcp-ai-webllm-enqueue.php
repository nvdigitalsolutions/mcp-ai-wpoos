<?php
/**
 * WebLLM Script Enqueue Manager
 *
 * Conditionally loads WebLLM scripts only when embedded provider is active.
 * Uses CDN for heavy dependencies, bundles only thin wrappers.
 *
 * Phase 1: Advanced WebLLM Integration
 * - Tool calling support
 * - Multi-modal (vision) support
 * - Enhanced streaming and diagnostics
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_WebLLM_Enqueue
 *
 * Manages conditional script loading for WebLLM enhanced features.
 * Feature flags control which scripts are loaded to minimize bundle size.
 */
class WP_MCP_AI_WebLLM_Enqueue {

	/**
	 * Feature flag option names
	 */
	const OPTION_ENABLE_TOOL_CALLING = 'wp_mcp_ai_enable_webllm_tools';
	const OPTION_ENABLE_MULTIMODAL   = 'wp_mcp_ai_enable_webllm_vision';

	/**
	 * Initialize the enqueue manager
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_scripts' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_scripts' ), 20 );
	}

	/**
	 * Register scripts (don't enqueue yet - wait until needed)
	 * Registered early so other code can enqueue them if needed
	 */
	public static function register_scripts() {
		$plugin_version = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.2.0';
		$plugin_file    = defined( 'WP_MCP_AI_FILE' ) ? WP_MCP_AI_FILE : __FILE__;

		// Core WebLLM loader (already exists, loads from CDN)
		// This is already registered in WP_MCP_AI_Shortcode, but we check here for safety.
		if ( ! wp_script_is( 'webllm-loader', 'registered' ) ) {
			wp_register_script(
				'webllm-loader',
				plugins_url( 'assets/js/webllm-loader.js', $plugin_file ),
				array(),
				$plugin_version,
				true
			);
		}

		// Embedded LLM client (already exists).
		if ( ! wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-embedded-llm-client',
				plugins_url( 'assets/js/embedded-llm-client.js', $plugin_file ),
				array( 'webllm-loader' ),
				$plugin_version,
				true
			);
		}

		// NEW: Tool adapter (Phase 1 - thin wrapper, ~1.7KB minified).
		wp_register_script(
			'wp-mcp-ai-webllm-tool-adapter',
			plugins_url( 'assets/js/webllm-tool-adapter.min.js', $plugin_file ),
			array(),
			$plugin_version,
			true
		);

		// NEW: Function calling client (Phase 1 - thin wrapper, ~5.1KB minified).
		wp_register_script(
			'wp-mcp-ai-webllm-function-calling',
			plugins_url( 'assets/js/webllm-function-calling-client.min.js', $plugin_file ),
			array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-webllm-tool-adapter' ),
			$plugin_version,
			true
		);
	}

	/**
	 * Enqueue scripts only when embedded provider is active and on appropriate pages
	 */
	public static function maybe_enqueue_scripts() {
		// Only load if Pro plugin is present (embedded provider is Pro-only feature).
		$is_pro_available = defined( 'WP_MCP_AI_PRO_VERSION' );
		if ( ! $is_pro_available ) {
			return;
		}

		// Only load on pages with chat interface.
		// The shortcode and Elementor widget already enqueue base scripts,
		// so we just add the enhanced features here.
		if ( ! self::is_chat_page() ) {
			return;
		}

		// Base scripts are already enqueued by shortcode/widget.
		// We just need to enqueue enhanced features if enabled.

		// Check if tool calling is enabled.
		$enable_tool_calling = get_option( self::OPTION_ENABLE_TOOL_CALLING, false );
		if ( $enable_tool_calling ) {
			// Enqueue tool calling enhancement.
			wp_enqueue_script( 'wp-mcp-ai-webllm-tool-adapter' );
			wp_enqueue_script( 'wp-mcp-ai-webllm-function-calling' );

			// Log for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic trace confirming WebLLM tool-calling script enqueue.
				error_log( '[NV oOS WebLLM] Tool calling scripts enqueued' );
			}
		}

		// Multi-modal support will be added in future commits.
		// For now, the function calling client is the foundation.
	}

	/**
	 * Check if current page has chat interface
	 *
	 * @return bool
	 */
	private static function is_chat_page() {
		global $post;

		if ( ! $post ) {
			return false;
		}

		// Check for shortcode in post content.
		if ( has_shortcode( $post->post_content, 'mcp_ai_chat' ) ) {
			return true;
		}

		// Check for Elementor widget.
		if ( self::has_elementor_chat_widget() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if page has Elementor chat widget
	 *
	 * @return bool
	 */
	private static function has_elementor_chat_widget() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$document = \Elementor\Plugin::$instance->documents->get( get_the_ID() );
		if ( ! $document ) {
			return false;
		}

		// Check if document contains our chat widget.
		$data = $document->get_elements_data();
		$json = wp_json_encode( $data );

		return strpos( $json, 'mcp-ai-chat' ) !== false;
	}

	/**
	 * Check if tool calling feature is enabled
	 *
	 * @return bool
	 */
	public static function is_tool_calling_enabled() {
		return (bool) get_option( self::OPTION_ENABLE_TOOL_CALLING, false );
	}

	/**
	 * Check if multi-modal feature is enabled
	 *
	 * @return bool
	 */
	public static function is_multimodal_enabled() {
		return (bool) get_option( self::OPTION_ENABLE_MULTIMODAL, false );
	}
}

// Initialize.
WP_MCP_AI_WebLLM_Enqueue::init();
