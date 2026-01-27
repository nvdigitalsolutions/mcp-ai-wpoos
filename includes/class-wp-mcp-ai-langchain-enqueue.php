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
		// Only register if embedded provider is enabled.
		$embedded_enabled = get_option( 'wp_mcp_ai_enable_embedded_llm', false );
		if ( ! $embedded_enabled ) {
			return;
		}

		// Tool adapter (NEW - thin wrapper, ~3KB minified).
		wp_register_script(
			'wp-mcp-ai-langchain-tool-adapter',
			plugins_url( 'assets/js/langchain-tool-adapter.min.js', WP_MCP_AI_FILE ),
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		// LangChain orchestration client (NEW - thin wrapper, ~5KB minified).
		wp_register_script(
			'wp-mcp-ai-langchain-orchestration',
			plugins_url( 'assets/js/langchain-orchestration.min.js', WP_MCP_AI_FILE ),
			array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-langchain-tool-adapter' ),
			WP_MCP_AI_VERSION,
			true
		);

		// LangChain libraries from local bundles (loaded on-demand).
		// Note: These are loaded via import() in the orchestration client for lazy loading.
		// We register them here for dependency management only.
		$langchain_version           = '0.3.6';
		$langchain_core_version      = '0.3.20';
		$langchain_community_version = '0.3.14';

		// Register local bundled scripts (for reference, not directly enqueued).
		wp_register_script(
			'langchain-core',
			plugins_url( 'assets/js/vendor/langchain-core.bundle.min.js', WP_MCP_AI_FILE ),
			array(),
			$langchain_core_version,
			true
		);

		wp_register_script(
			'langchain',
			plugins_url( 'assets/js/vendor/langchain.bundle.min.js', WP_MCP_AI_FILE ),
			array( 'langchain-core' ),
			$langchain_version,
			true
		);

		wp_register_script(
			'langchain-community',
			plugins_url( 'assets/js/vendor/langchain-community.bundle.min.js', WP_MCP_AI_FILE ),
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
		// Check if embedded provider is enabled.
		$embedded_enabled = get_option( 'wp_mcp_ai_enable_embedded_llm', false );
		if ( ! $embedded_enabled ) {
			return;
		}

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
		wp_localize_script(
			'wp-mcp-ai-langchain-orchestration',
			'wpMcpAiLangChain',
			array(
				'enabled'       => true,
				'maxIterations' => apply_filters( 'wp_mcp_ai_langchain_max_iterations', 10 ),
				'verbose'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'localUrls'     => array(
					'core'      => plugins_url( 'assets/js/vendor/langchain-core.bundle.min.js', WP_MCP_AI_FILE ),
					'langchain' => plugins_url( 'assets/js/vendor/langchain.bundle.min.js', WP_MCP_AI_FILE ),
					'community' => plugins_url( 'assets/js/vendor/langchain-community.bundle.min.js', WP_MCP_AI_FILE ),
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
		return array(
			'enabled'                   => get_option( 'wp_mcp_ai_enable_langchain_orchestration', false ),
			'embedded_provider_enabled' => get_option( 'wp_mcp_ai_enable_embedded_llm', false ),
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
