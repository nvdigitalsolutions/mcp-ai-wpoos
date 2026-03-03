<?php
/**
 * Model Manager AJAX Handler
 *
 * Handles AJAX requests for the Model Manager UI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model Manager AJAX Handler class
 *
 * Provides AJAX endpoints for:
 * - Discovering new models
 * - Researching model specifications
 * - Adding models to configuration
 */
class WP_MCP_AI_Model_Manager_Ajax {

	/**
	 * Initialize AJAX handlers
	 */
	public static function init() {
		add_action( 'wp_ajax_wp_mcp_ai_discover_models', array( __CLASS__, 'discover_models' ) );
		add_action( 'wp_ajax_wp_mcp_ai_research_model', array( __CLASS__, 'research_model' ) );
		add_action( 'wp_ajax_wp_mcp_ai_add_model_config', array( __CLASS__, 'add_model_config' ) );
	}

	/**
	 * AJAX handler for discovering new models
	 */
	public static function discover_models() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_model_manager', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to discover models.', 'mcp-ai-wpoos' ) );
		}

		// Get tool registry.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'discover_new_models' );

		if ( ! $tool ) {
			wp_send_json_error( __( 'Model discovery tool not available.', 'mcp-ai-wpoos' ) );
		}

		// Execute tool.
		$result = $tool->execute(
			array(
				'providers'     => array(), // Empty = check all configured.
				'auto_research' => false,   // Don't auto-research for faster results.
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for researching a specific model
	 */
	public static function research_model() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_model_manager', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to research models.', 'mcp-ai-wpoos' ) );
		}

		// Validate inputs.
		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';

		if ( empty( $model_id ) || empty( $provider ) ) {
			wp_send_json_error( __( 'Model ID and provider are required.', 'mcp-ai-wpoos' ) );
		}

		// Get tool registry.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'research_model' );

		if ( ! $tool ) {
			wp_send_json_error( __( 'Model research tool not available.', 'mcp-ai-wpoos' ) );
		}

		// Execute tool.
		$result = $tool->execute(
			array(
				'model_id'       => $model_id,
				'provider'       => $provider,
				'use_web_search' => true,
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for adding a model to configuration
	 */
	public static function add_model_config() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_model_manager', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to add models.', 'mcp-ai-wpoos' ) );
		}

		// Validate inputs.
		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		$config   = isset( $_POST['config'] ) ? json_decode( wp_unslash( $_POST['config'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$config   = is_array( $config ) ? wp_mcp_ai_sanitize_recursive( $config ) : array();

		if ( empty( $model_id ) || empty( $config ) ) {
			wp_send_json_error( __( 'Model ID and configuration are required.', 'mcp-ai-wpoos' ) );
		}

		// Get tool registry.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'add_model_config' );

		if ( ! $tool ) {
			wp_send_json_error( __( 'Model configuration tool not available.', 'mcp-ai-wpoos' ) );
		}

		// Execute tool.
		$result = $tool->execute(
			array(
				'model_id'  => $model_id,
				'config'    => $config,
				'overwrite' => false, // Don't overwrite existing configs from UI.
			),
			array(
				'user_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}
}

// Initialize AJAX handlers.
WP_MCP_AI_Model_Manager_Ajax::init();
