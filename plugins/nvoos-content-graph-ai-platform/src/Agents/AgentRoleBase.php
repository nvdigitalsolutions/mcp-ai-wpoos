<?php
/**
 * Base Agent Role Class
 *
 * Abstract base class for all agent role implementations.
 * Provides common functionality and helper methods.
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
 * Abstract base class for agent roles
 *
 * Provides default implementations for common role functionality.
 * Specific role types should extend this class and override methods as needed.
 *
 * @since 1.1.0
 */
abstract class AgentRoleBase implements AgentRoleInterface {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional structured context forwarded to the base logger.
	 */
	private static function log_event( $level, $message, $context = array() ) {
		if ( ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message, $context );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Role type identifier
	 *
	 * @var string
	 */
	protected $role_type;

	/**
	 * Role name for display
	 *
	 * @var string
	 */
	protected $role_name;

	/**
	 * Role description
	 *
	 * @var string
	 */
	protected $role_description;

	/**
	 * Role capabilities
	 *
	 * @var array<string>
	 */
	protected $capabilities = array();

	/**
	 * Recommended tools for this role
	 *
	 * @var array<string>
	 */
	protected $recommended_tools = array();

	/**
	 * Get the role type identifier
	 *
	 * @return string Role type identifier.
	 */
	public function get_role_type() {
		return $this->role_type;
	}

	/**
	 * Get the human-readable role name
	 *
	 * @return string Role name for UI display.
	 */
	public function get_role_name() {
		return $this->role_name;
	}

	/**
	 * Get the role description
	 *
	 * @return string Description of what this role does.
	 */
	public function get_role_description() {
		return $this->role_description;
	}

	/**
	 * Get capabilities specific to this role
	 *
	 * @return array<string> Array of capability flags.
	 */
	public function get_capabilities() {
		return $this->capabilities;
	}

	/**
	 * Check if this role can delegate tasks
	 *
	 * @return bool True if role can delegate to other agents.
	 */
	public function can_delegate() {
		return in_array( 'can-delegate', $this->capabilities, true );
	}

	/**
	 * Get recommended tools for this role
	 *
	 * @return array<string> Array of recommended tool slugs.
	 */
	public function get_recommended_tools() {
		/**
		 * Filters recommended tools for an agent role.
		 *
		 * @param array  $tools     Recommended tool slugs.
		 * @param string $role_type Role type identifier.
		 */
		return apply_filters(
			'wp_mcp_ai_agent_role_recommended_tools',
			$this->recommended_tools,
			$this->role_type
		);
	}

	/**
	 * Get recommended system prompt additions for this role
	 *
	 * Returns empty string by default. Override in subclasses.
	 *
	 * @return string Additional system prompt text.
	 */
	public function get_system_prompt_additions() {
		return '';
	}

	/**
	 * Execute a role-specific task
	 *
	 * Default implementation returns an error.
	 * Subclasses should override this method.
	 *
	 * @param array $task Task data including description, context, requirements.
	 * @param array $context Execution context including assistant_id, user_id, etc.
	 * @return array|WP_Error Task result or error.
	 */
	public function execute_role_task( $task, $context ) {
		return new \WP_Error(
			'wp_mcp_ai_role_not_implemented',
			sprintf(
				/* translators: %s: role type */
				__( 'Task execution not implemented for role: %s', 'nvoos-content-graph-ai-platform' ),
				$this->role_type
			)
		);
	}

	/**
	 * Validate task structure
	 *
	 * Helper method to ensure task has required fields.
	 *
	 * @param array $task Task data to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_task( $task ) {
		if ( empty( $task['description'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_task',
				__( 'Task description is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Validate execution context
	 *
	 * Helper method to ensure context has required fields.
	 *
	 * @param array $context Execution context to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_context( $context ) {
		if ( empty( $context['assistant_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_context',
				__( 'Assistant ID is required in context.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Log role activity
	 *
	 * Helper method to log role-specific activities.
	 *
	 * @param string $message Log message.
	 * @param string $level Log level (info, warning, error).
	 * @param array  $data Additional data to log.
	 */
	protected function log( $message, $level = 'info', $data = array() ) {
		self::log_event(
			$level,
			$message,
			array_merge(
				$data,
				array(
					'role_type' => $this->role_type,
				)
			)
		);
	}
}
