<?php
/**
 * Agent Roles Initialization
 *
 * Loads and initializes all agent role classes.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load interface.
require_once __DIR__ . '/interfaces/interface-wp-mcp-ai-agent-role.php';

// Load base class.
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-role-base.php';

// Load role implementations.
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-role-planner.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-role-executor.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-role-critic.php';

/**
 * Get all available agent roles
 *
 * @return array<string, WP_MCP_AI_Agent_Role_Interface> Map of role type to role instance.
 */
function wp_mcp_ai_get_agent_roles() {
	static $roles = null;

	if ( null === $roles ) {
		$roles = array(
			'planner'  => new WP_MCP_AI_Agent_Role_Planner(),
			'executor' => new WP_MCP_AI_Agent_Role_Executor(),
			'critic'   => new WP_MCP_AI_Agent_Role_Critic(),
		);

		/**
		 * Filters the available agent roles.
		 *
		 * Allows plugins to add custom agent role implementations.
		 *
		 * @param array<string, WP_MCP_AI_Agent_Role_Interface> $roles Available agent roles.
		 */
		$roles = apply_filters( 'wp_mcp_ai_agent_roles', $roles );
	}

	return $roles;
}

/**
 * Get an agent role by type
 *
 * @param string $role_type Role type identifier.
 * @return WP_MCP_AI_Agent_Role_Interface|null Role instance or null if not found.
 */
function wp_mcp_ai_get_agent_role( $role_type ) {
	$roles = wp_mcp_ai_get_agent_roles();
	return isset( $roles[ $role_type ] ) ? $roles[ $role_type ] : null;
}

/**
 * Get agent role for an assistant
 *
 * @param int $assistant_id Assistant post ID.
 * @return WP_MCP_AI_Agent_Role_Interface|null Role instance or null if not set.
 */
function wp_mcp_ai_get_assistant_role( $assistant_id ) {
	$role_type = get_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', true );
	
	if ( empty( $role_type ) ) {
		return null;
	}

	return wp_mcp_ai_get_agent_role( $role_type );
}

/**
 * Set agent role for an assistant
 *
 * @param int    $assistant_id Assistant post ID.
 * @param string $role_type Role type identifier.
 * @return bool True on success, false on failure.
 */
function wp_mcp_ai_set_assistant_role( $assistant_id, $role_type ) {
	// Validate role exists.
	$role = wp_mcp_ai_get_agent_role( $role_type );
	if ( ! $role ) {
		return false;
	}

	return update_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', sanitize_key( $role_type ) ) !== false;
}
