<?php
/**
 * LangChain.js Enqueue Manager
 *
 * Handles conditional loading of LangChain.js orchestration scripts.
 * Only loads when embedded provider is active and feature flag is enabled.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 * @version 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_LangChain_Enqueue
 *
 * Manages enqueue of LangChain.js orchestration scripts with CDN-first approach.
 * Loads heavy dependencies from CDN, bundles only thin wrapper code.
 */
class WP_MCP_AI_LangChain_Enqueue {

	/**
	 * Initialize the enqueue manager
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_scripts' ), 20 );
	}

	/**
	 * Register LangChain scripts (don't enqueue yet - wait until needed)
	 *
	 * @return void
	 */
	public function register_scripts() {
		// No need to check for pro - this file is in pro addon.
		
		// Tool adapter (NEW - thin wrapper, ~3KB minified).
		wp_register_script(
			'wp-mcp-ai-langchain-tool-adapter',
			plugins_url( 'assets/js/langchain-tool-adapter.min.js', WP_MCP_AI_PRO_FILE ),
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		// LangChain orchestration client (NEW - thin wrapper, ~5KB minified).
		wp_register_script(
			'wp-mcp-ai-langchain-orchestration',
			plugins_url( 'assets/js/langchain-orchestration.min.js', WP_MCP_AI_PRO_FILE ),
			array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-langchain-tool-adapter' ),
			WP_MCP_AI_VERSION,
			true
		);

		// LangChain libraries from CDN (loaded on-demand via dynamic import() in orchestration client).
		// Versions aligned with package.json optionalDependencies.
		// Note: These are registered for reference; actual loading uses import() in JS.
		$langchain_version           = '0.3.x';
		$langchain_core_version      = '0.3.x';
		$langchain_community_version = '0.3.x';

		// Register CDN scripts (for reference, not directly enqueued).
		wp_register_script(
			'langchain-core',
			"https://cdn.jsdelivr.net/npm/@langchain/core/+esm",
			array(),
			$langchain_core_version,
			true
		);

		wp_register_script(
			'langchain',
			"https://cdn.jsdelivr.net/npm/langchain/+esm",
			array( 'langchain-core' ),
			$langchain_version,
			true
		);

		wp_register_script(
			'langchain-community',
			"https://cdn.jsdelivr.net/npm/@langchain/community/+esm",
			array( 'langchain-core' ),
			$langchain_community_version,
			true
		);
	}

	/**
	 * Conditionally enqueue scripts only when LangChain orchestration is enabled
	 *
	 * @return void
	 */
	public function maybe_enqueue_scripts() {
		// No need to check for pro - this file is in pro addon.
		
		// Check if LangChain orchestration feature flag is enabled.
		$langchain_enabled = get_option( 'wp_mcp_ai_enable_langchain_orchestration', false );
		if ( ! $langchain_enabled ) {
			return;
		}

		// Only load on pages with chat interface.
		if ( ! $this->is_chat_page() ) {
			return;
		}

		// Enqueue scripts.
		wp_enqueue_script( 'wp-mcp-ai-langchain-tool-adapter' );
		wp_enqueue_script( 'wp-mcp-ai-langchain-orchestration' );

		// Pass configuration to JavaScript.
		$webllm_settings = get_option( 'wp_mcp_ai_webllm_settings', array() );
		wp_localize_script(
			'wp-mcp-ai-langchain-orchestration',
			'wpMcpAiLangChain',
			array(
				'enabled'         => true,
				'maxIterations'   => apply_filters( 'wp_mcp_ai_langchain_max_iterations', 10 ),
				'maxRetries'      => absint( $webllm_settings['langchain_max_retries'] ?? 3 ),
				'memoryWindowK'   => absint( $webllm_settings['langchain_memory_window'] ?? 10 ),
				'enableStreaming'  => ! empty( $webllm_settings['langchain_enable_streaming'] ),
				'verbose'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'cdnUrls'         => array(
					'core'      => 'https://cdn.jsdelivr.net/npm/@langchain/core/+esm',
					'langchain' => 'https://cdn.jsdelivr.net/npm/langchain/+esm',
					'community' => 'https://cdn.jsdelivr.net/npm/@langchain/community/+esm',
				),
			)
		);

		do_action( 'wp_mcp_ai_langchain_scripts_enqueued' );
	}

	/**
	 * Check if current page has chat interface
	 *
	 * @return bool True if chat interface is present
	 */
	private function is_chat_page() {
		// Check for chat shortcode in post content.
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'mcp_ai_chat' ) ) {
			return true;
		}

		// Check for Elementor chat widget.
		if ( $this->has_elementor_chat_widget() ) {
			return true;
		}

		// Allow other plugins to indicate chat page.
		return apply_filters( 'wp_mcp_ai_is_chat_page', false );
	}

	/**
	 * Check if page has Elementor chat widget
	 *
	 * @return bool True if Elementor chat widget is present
	 */
	private function has_elementor_chat_widget() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return false;
		}

		// Check if Elementor is used on this page.
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document ) {
			return false;
		}

		// Check if page has mcp-ai-chat widget.
		$elements_data = $document->get_elements_data();
		$json_data     = wp_json_encode( $elements_data );

		return ( false !== strpos( $json_data, 'mcp-ai-chat' ) ||
				false !== strpos( $json_data, 'wp-mcp-ai-chat' ) );
	}

	/**
	 * Get LangChain feature status
	 *
	 * @return array Status information
	 */
	public static function get_feature_status() {
		// Always true since we're in pro addon.
		return array(
			'enabled'                   => get_option( 'wp_mcp_ai_enable_langchain_orchestration', false ),
			'embedded_provider_enabled' => true,
			'has_transformers'          => defined( 'WP_MCP_AI_TRANSFORMERS_VERSION' ),
			'has_webllm'                => class_exists( 'WP_MCP_AI_WebLLM_Enqueue' ),
		);
	}

	/**
	 * Check if LangChain orchestration is available
	 *
	 * @return bool True if available
	 */
	public static function is_available() {
		$status = self::get_feature_status();
		return $status['enabled'] && $status['embedded_provider_enabled'];
	}
}

// Initialize the enqueue manager.
new WP_MCP_AI_LangChain_Enqueue();
