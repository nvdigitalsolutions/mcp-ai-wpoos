<?php
/**
 * Tool Service for WP oOS
 *
 * Orchestrates tool execution workflows using Tool_Registry.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Service' ) ) {
	/**
	 * Service layer for tool execution orchestration.
	 *
	 * This service handles the orchestration of tool execution workflows,
	 * coordinating validation, capability checks, and execution through
	 * the tool registry.
	 */
	class WP_MCP_AI_Tool_Service {
		/**
		 * Tool registry instance.
		 *
		 * @var WP_MCP_AI_Tool_Registry
		 */
		private $tool_registry;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Tool_Registry $tool_registry Optional. Tool registry instance.
		 */
		public function __construct( $tool_registry = null ) {
			$this->tool_registry = $tool_registry ?? WP_MCP_AI_Tool_Registry::instance();
		}

		/**
		 * Execute a tool with orchestration.
		 *
		 * @param string $tool_slug Tool identifier.
		 * @param array  $params    Tool execution parameters.
		 * @param array  $context   Execution context (user_id, assistant_id, etc.).
		 * @return mixed|WP_Error Tool execution result or error.
		 */
		public function execute_tool( $tool_slug, $params, $context = array() ) {
			// Validate tool slug.
			if ( empty( $tool_slug ) ) {
				return new WP_Error( 'missing_tool_slug', __( 'Tool slug is required.', 'wp-mcp-ai' ) );
			}

			// Check if tool exists.
			$tool = $this->tool_registry->get_tool( $tool_slug );
			if ( ! $tool ) {
				return new WP_Error(
					'tool_not_found',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool "%s" not found.', 'wp-mcp-ai' ),
						$tool_slug
					)
				);
			}

			// Validate capabilities.
			$capability_check = $this->validate_capabilities( $tool, $context );
			if ( is_wp_error( $capability_check ) ) {
				return $capability_check;
			}

			// Validate parameters.
			$validation_result = $this->validate_parameters( $tool, $params );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}

			// Execute the tool.
			$result = $this->execute_with_logging( $tool_slug, $params, $context );

			// Process result.
			return $this->process_result( $result, $tool_slug, $context );
		}

		/**
		 * Get available tools for a user/assistant.
		 *
		 * @param array $context Execution context (user_id, assistant_id, etc.).
		 * @return array List of available tools.
		 */
		public function get_available_tools( $context = array() ) {
			$all_tools       = $this->tool_registry->get_all_tools();
			$available_tools = array();

			foreach ( $all_tools as $tool_slug => $tool ) {
				// Check if user has capability for this tool.
				$capability_check = $this->validate_capabilities( $tool, $context );
				if ( ! is_wp_error( $capability_check ) ) {
					$available_tools[ $tool_slug ] = $this->format_tool_definition( $tool );
				}
			}

			return $available_tools;
		}

		/**
		 * Validate user capabilities for tool execution.
		 *
		 * @param object $tool    Tool instance.
		 * @param array  $context Execution context.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_capabilities( $tool, $context ) {
			// Get required capability.
			$required_capability = 'edit_posts';
			if ( method_exists( $tool, 'get_required_capability' ) ) {
				$required_capability = $tool->get_required_capability();
			}

			// Get user ID from context.
			$user_id = $context['user_id'] ?? get_current_user_id();

			// Check capability.
			if ( ! user_can( $user_id, $required_capability ) ) {
				return new WP_Error(
					'insufficient_permissions',
					sprintf(
						/* translators: %s: required capability */
						__( 'This tool requires the "%s" capability.', 'wp-mcp-ai' ),
						$required_capability
					)
				);
			}

			return true;
		}

		/**
		 * Validate tool parameters.
		 *
		 * @param object $tool   Tool instance.
		 * @param array  $params Tool parameters.
		 * @return true|WP_Error True on success, WP_Error on failure.
		 */
		private function validate_parameters( $tool, $params ) {
			// Get parameter schema if available.
			if ( ! method_exists( $tool, 'get_parameter_schema' ) ) {
				return true;
			}

			$schema = $tool->get_parameter_schema();
			if ( empty( $schema ) ) {
				return true;
			}

			// Validate required parameters.
			$required = $schema['required'] ?? array();
			foreach ( $required as $param_name ) {
				if ( ! isset( $params[ $param_name ] ) ) {
					return new WP_Error(
						'missing_parameter',
						sprintf(
							/* translators: %s: parameter name */
							__( 'Required parameter "%s" is missing.', 'wp-mcp-ai' ),
							$param_name
						)
					);
				}
			}

			return true;
		}

		/**
		 * Execute tool with logging.
		 *
		 * @param string $tool_slug Tool identifier.
		 * @param array  $params    Tool parameters.
		 * @param array  $context   Execution context.
		 * @return mixed Tool execution result.
		 */
		private function execute_with_logging( $tool_slug, $params, $context ) {
			// Log execution start.
			$this->log_tool_execution( $tool_slug, $params, $context, 'start' );

			// Execute tool through registry.
			$result = $this->tool_registry->execute_tool( $tool_slug, $params, $context );

			// Log execution end.
			$this->log_tool_execution( $tool_slug, $params, $context, 'end', $result );

			return $result;
		}

		/**
		 * Log tool execution.
		 *
		 * @param string $tool_slug Tool identifier.
		 * @param array  $params    Tool parameters.
		 * @param array  $context   Execution context.
		 * @param string $event     Event type (start/end).
		 * @param mixed  $result    Optional. Execution result.
		 * @return void
		 */
		private function log_tool_execution( $tool_slug, $params, $context, $event, $result = null ) {
			/**
			 * Action fired when a tool execution event occurs.
			 *
			 * @param string $tool_slug Tool identifier.
			 * @param array  $params    Tool parameters.
			 * @param array  $context   Execution context.
			 * @param string $event     Event type (start/end).
			 * @param mixed  $result    Execution result (null for start events).
			 */
			do_action( 'wp_mcp_ai_tool_execution_event', $tool_slug, $params, $context, $event, $result );

			// Log to error log if logging is enabled.
			$settings = WP_MCP_AI_Admin_Settings_Base::get_settings();
			if ( ! empty( $settings['enable_logging'] ) ) {
				error_log(
					sprintf(
						'[WP_MCP_AI] Tool execution %s: %s by user %d',
						$event,
						$tool_slug,
						$context['user_id'] ?? 0
					)
				);
			}
		}

		/**
		 * Process tool execution result.
		 *
		 * @param mixed  $result    Tool execution result.
		 * @param string $tool_slug Tool identifier.
		 * @param array  $context   Execution context.
		 * @return mixed Processed result.
		 */
		private function process_result( $result, $tool_slug, $context ) {
			/**
			 * Filter the tool execution result.
			 *
			 * @param mixed  $result    Tool execution result.
			 * @param string $tool_slug Tool identifier.
			 * @param array  $context   Execution context.
			 */
			return apply_filters( 'wp_mcp_ai_tool_execution_result', $result, $tool_slug, $context );
		}

		/**
		 * Format tool definition for API response.
		 *
		 * @param object $tool Tool instance.
		 * @return array Formatted tool definition.
		 */
		private function format_tool_definition( $tool ) {
			$definition = array(
				'name' => method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '',
			);

			if ( method_exists( $tool, 'get_description' ) ) {
				$definition['description'] = $tool->get_description();
			}

			if ( method_exists( $tool, 'get_parameter_schema' ) ) {
				$definition['parameters'] = $tool->get_parameter_schema();
			}

			return $definition;
		}

		/**
		 * Get the tool registry instance.
		 *
		 * @return WP_MCP_AI_Tool_Registry
		 */
		public function get_tool_registry() {
			return $this->tool_registry;
		}
	}
}
