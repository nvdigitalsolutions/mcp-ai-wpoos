<?php
/**
 * Web Worker Enqueue Manager
 *
 * Handles conditional loading of Web Worker scripts for LLM operations.
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
 * Class WP_MCP_AI_WebWorker_Enqueue
 *
 * Manages enqueue of Web Worker scripts for non-blocking LLM operations.
 */
class WP_MCP_AI_WebWorker_Enqueue {

	/**
	 * Initialize the enqueue manager
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_scripts' ), 20 );
	}

	/**
	 * Register Web Worker scripts (don't enqueue yet - wait until needed)
	 *
	 * @return void
	 */
	public function register_scripts() {
		// Worker manager (main thread).
		wp_register_script(
			'wp-mcp-ai-llm-worker-manager',
			plugins_url( 'assets/js/llm-worker-manager.min.js', WP_MCP_AI_FILE ),
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		// Note: Worker script is loaded dynamically by the worker manager.
		// We don't enqueue it here as it's loaded via new Worker().
	}

	/**
	 * Conditionally enqueue scripts only when Web Workers are enabled
	 *
	 * @return void
	 */
	public function maybe_enqueue_scripts() {
		// Check if Web Workers feature flag is enabled.
		$workers_enabled = get_option( 'wp_mcp_ai_enable_web_workers', false );
		if ( ! $workers_enabled ) {
			return;
		}

		// Only load on pages with chat interface.
		if ( ! $this->is_chat_page() ) {
			return;
		}

		// Enqueue worker manager.
		wp_enqueue_script( 'wp-mcp-ai-llm-worker-manager' );

		// Pass configuration to JavaScript.
		wp_localize_script(
			'wp-mcp-ai-llm-worker-manager',
			'wpMcpAiWebWorker',
			array(
				'enabled'    => true,
				'workerUrl'  => plugins_url( 'assets/js/workers/llm-worker.min.js', WP_MCP_AI_FILE ),
				'pluginUrl'  => plugins_url( '', WP_MCP_AI_FILE ),
				'maxWorkers' => apply_filters( 'wp_mcp_ai_max_web_workers', 1 ),
			)
		);

		do_action( 'wp_mcp_ai_web_worker_scripts_enqueued' );
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
	 * Get Web Worker feature status
	 *
	 * @return array Status information
	 */
	public static function get_feature_status() {
		$is_pro_available = defined( 'WP_MCP_AI_PRO_VERSION' );
		return array(
			'enabled'                   => get_option( 'wp_mcp_ai_enable_web_workers', false ),
			'embedded_provider_enabled' => $is_pro_available,
			'browser_support'           => true, // Checked client-side.
		);
	}

	/**
	 * Check if Web Workers are available
	 *
	 * @return bool True if available
	 */
	public static function is_available() {
		$status = self::get_feature_status();
		return $status['enabled'] && $status['embedded_provider_enabled'];
	}
}

// Initialize the enqueue manager.
new WP_MCP_AI_WebWorker_Enqueue();
