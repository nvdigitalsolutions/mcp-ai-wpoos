<?php
/**
 * Agent Role Interface
 *
 * Defines the contract for agent roles in the multi-agent coordination framework.
 * Inspired by DeepSeek V4's role-based orchestration patterns.
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for agent role implementations
 *
 * Agent roles enable sophisticated multi-agent workflows by defining
 * specialized behaviors for different types of assistants:
 * - Planner: Decomposes complex tasks into subtasks
 * - Executor: Performs specific operations using tools
 * - Critic: Validates and improves results
 * - Specialist: Domain-specific expertise
 *
 * @since 1.1.0
 */
interface AgentRoleInterface {
	/**
	 * Get the role type identifier
	 *
	 * Standard role types:
	 * - 'planner': Task decomposition and coordination
	 * - 'executor': Tool execution and operations
	 * - 'critic': Result validation and improvement
	 * - 'specialist': Domain-specific expertise
	 * - 'generalist': General-purpose assistant (default)
	 *
	 * @return string Role type identifier.
	 */
	public function get_role_type();

	/**
	 * Get the human-readable role name
	 *
	 * @return string Role name for UI display.
	 */
	public function get_role_name();

	/**
	 * Get the role description
	 *
	 * @return string Description of what this role does.
	 */
	public function get_role_description();

	/**
	 * Get capabilities specific to this role
	 *
	 * Returns an array of capability flags that define what this role can do:
	 * - 'can-delegate': Can delegate tasks to other agents
	 * - 'can-validate': Can validate results from other agents
	 * - 'can-coordinate': Can coordinate multi-agent workflows
	 * - 'can-specialize': Has domain-specific knowledge
	 * - 'requires-tools': Requires tool access to function
	 * - 'autonomous': Can operate independently
	 *
	 * @return array<string> Array of capability flags.
	 */
	public function get_capabilities();

	/**
	 * Check if this role can delegate tasks
	 *
	 * @return bool True if role can delegate to other agents.
	 */
	public function can_delegate();

	/**
	 * Execute a role-specific task
	 *
	 * This method is called when an agent with this role
	 * receives a task to perform. The implementation should
	 * handle the task according to the role's specialty.
	 *
	 * @param array $task Task data including description, context, requirements.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context );

	/**
	 * Get recommended tools for this role
	 *
	 * Returns an array of tool slugs that are most useful
	 * for agents with this role.
	 *
	 * @return array<string> Array of recommended tool slugs.
	 */
	public function get_recommended_tools();

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * Returns text to be appended to the assistant's system prompt
	 * to help guide role-specific behavior.
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions();
}
