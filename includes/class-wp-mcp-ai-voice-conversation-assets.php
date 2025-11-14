<?php
/**
 * Voice Conversation Asset Manager
 *
 * Handles registration and enqueuing of voice conversation widget assets.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Voice Conversation Asset Manager class.
 */
class WP_MCP_AI_Voice_Conversation_Assets {
	/**
	 * Script handle.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-voice-conversation';

	/**
	 * Style handle.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-voice-conversation';

	/**
	 * Constructor - register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Register voice conversation assets.
	 */
	public function register_assets() {
		$script_relative = 'assets/js/voice-conversation.js';
		$style_relative  = 'assets/css/voice-conversation.css';

		$script_path = WP_MCP_AI_URL . $script_relative;
		$style_path  = WP_MCP_AI_URL . $style_relative;

		$script_version = $this->get_asset_version( $script_relative );
		$style_version  = $this->get_asset_version( $style_relative );

		// Register script.
		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array( 'jquery' ),
			$script_version,
			true
		);

		// Register style.
		wp_register_style(
			self::STYLE_HANDLE,
			$style_path,
			array(),
			$style_version
		);

		// Localize script with API endpoint and nonce.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiVoice',
			array(
				'apiUrl' => rest_url( 'mcp-ai/v1' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Maybe enqueue assets if the widget is being used.
	 */
	public function maybe_enqueue_assets() {
		// Assets will be enqueued automatically by Elementor when widget is used
		// This is a fallback for manual enqueueing if needed
	}

	/**
	 * Get asset version based on file modification time.
	 *
	 * @param string $relative_path Relative path to asset file.
	 * @return string Version string.
	 */
	protected function get_asset_version( $relative_path ) {
		$file_path = WP_MCP_AI_PATH . $relative_path;

		if ( file_exists( $file_path ) ) {
			return (string) filemtime( $file_path );
		}

		return WP_MCP_AI_VERSION;
	}
}

// Initialize the asset manager.
new WP_MCP_AI_Voice_Conversation_Assets();
