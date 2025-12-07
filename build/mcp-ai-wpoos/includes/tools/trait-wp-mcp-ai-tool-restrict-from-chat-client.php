<?php
/**
 * Trait for tools that should be restricted from chat-client context.
 *
 * This trait provides a default implementation for tools that perform
 * sensitive operations (code execution, system modifications, etc.) and
 * should only be available via controlled MCP endpoints, not public chat interfaces.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for restricting tools from chat-client context.
 *
 * Tools that use this trait will be blocked from execution when called via
 * the /chat-client endpoint unless explicitly allowed via the
 * 'allow_sensitive_tools' parameter.
 */
trait WP_MCP_AI_Tool_Restrict_From_Chat_Client {
	/**
	 * Determine if the tool can be used in the given context.
	 *
	 * By default, this blocks execution from chat-client endpoint unless
	 * 'allow_sensitive_tools' is explicitly set to true.
	 *
	 * @param array $context Execution context with 'endpoint' or 'source' keys.
	 * @return true|WP_Error True if allowed, WP_Error if restricted.
	 */
	public function is_allowed_in_context( $context ) {
		// Get the endpoint from context.
		$endpoint = isset( $context['endpoint'] ) ? $context['endpoint'] : '';

		// Check if explicitly allowed.
		$allow_sensitive_tools = isset( $context['allow_sensitive_tools'] ) && $context['allow_sensitive_tools'] === true;

		// If allow_sensitive_tools is true, allow execution everywhere.
		if ( $allow_sensitive_tools ) {
			return true;
		}

		// Block execution from chat-client endpoint.
		if ( false !== strpos( $endpoint, '/chat-client' ) ) {
			return new WP_Error(
				'tool_restricted_from_chat_client',
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool "%s" is not available via chat interface for security reasons. Use the MCP endpoint or enable sensitive tools in shortcode/widget settings.', 'wp-mcp-ai' ),
					method_exists( $this, 'get_name' ) ? $this->get_name() : 'Unknown'
				),
				array(
					'tool'     => method_exists( $this, 'get_slug' ) ? $this->get_slug() : 'unknown',
					'endpoint' => $endpoint,
					'reason'   => 'sensitive_operation',
				)
			);
		}

		return true;
	}
}
