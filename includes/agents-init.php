<?php
/**
 * Agent Roles Initialization
 *
 * Loads and initializes all agent role classes.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

// CoSAI Secure-by-Design Agentic System (May 2026).
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-audit-trail.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-capability-boundary.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-approval-gate.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-code-sandbox.php';

// Continual Harness — Self-Improving Agent System (Karten et al., 2026).
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-harness-evolver.php';
require_once __DIR__ . '/agents/class-wp-mcp-ai-agent-harness-bootstrap.php';

// Initialise the audit trail system (CPT registration, cron).
WP_MCP_AI_Agent_Audit_Trail::init();

// Register CoSAI gate hooks on the existing tool execution actions.
WP_MCP_AI_Agent_Capability_Boundary_Hooks::register();

// Register destructive operations confirmation gate (1.2.0).
// Runs at priority 0 on wp_mcp_ai_before_tool_execution — before the
// capability boundary so it applies even without an active boundary.
WP_MCP_AI_Destructive_Ops_Gate::register();

// Register request guard (1.2.0) — SSE connection limits, JSON depth,
// and request body size enforcement via rest_pre_dispatch filter.
WP_MCP_AI_Request_Guard::register();

// Register security audit logger (1.2.0) — records security-relevant
// events to a custom table with REST endpoint and daily purge cron.
WP_MCP_AI_Security_Audit_Logger::register();

// Register CSP headers for admin pages (1.2.0) — restrict script,
// style, connect, image, font, frame, and object sources.
WP_MCP_AI_CSP_Headers::register();

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
