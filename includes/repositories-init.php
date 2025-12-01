<?php
/**
 * Repository Layer Initialization
 *
 * Loads and initializes repository layer classes.
 * Part of Phase 4 refactoring (Milestone 9).
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load repository classes.
require_once plugin_dir_path( __FILE__ ) . 'repositories/class-wp-mcp-ai-assistant-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'repositories/class-wp-mcp-ai-credential-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'repositories/class-wp-mcp-ai-settings-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'repositories/class-wp-mcp-ai-transcript-repository.php';

/**
 * Initialize repositories
 *
 * Creates and returns repository instances from DI container.
 *
 * @return array Array of repository instances keyed by repository name.
 */
function wp_mcp_ai_init_repositories() {
	$container = WP_MCP_AI_Container::get_instance();

	return array(
		'assistant'  => $container->get( 'repository.assistant' ),
		'credential' => $container->get( 'repository.credential' ),
		'settings'   => $container->get( 'repository.settings' ),
		'transcript' => $container->get( 'repository.transcript' ),
	);
}

/**
 * Get assistant repository instance
 *
 * @return WP_MCP_AI_Assistant_Repository Assistant repository instance.
 */
function wp_mcp_ai_get_assistant_repository() {
	$repositories = wp_mcp_ai_init_repositories();
	return $repositories['assistant'];
}

/**
 * Get credential repository instance
 *
 * @return WP_MCP_AI_Credential_Repository Credential repository instance.
 */
function wp_mcp_ai_get_credential_repository() {
	$repositories = wp_mcp_ai_init_repositories();
	return $repositories['credential'];
}

/**
 * Get settings repository instance
 *
 * @return WP_MCP_AI_Settings_Repository Settings repository instance.
 */
function wp_mcp_ai_get_settings_repository() {
	$repositories = wp_mcp_ai_init_repositories();
	return $repositories['settings'];
}

/**
 * Get transcript repository instance
 *
 * @return WP_MCP_AI_Transcript_Repository Transcript repository instance.
 */
function wp_mcp_ai_get_transcript_repository() {
	$repositories = wp_mcp_ai_init_repositories();
	return $repositories['transcript'];
}
