<?php
/**
 * WP_MCP_AI_Tool_Restrict_From_Chat_Client (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base plugin's `includes/tools/trait-wp-mcp-ai-tool-restrict-from-chat-client.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. Base-owned in `includes/tools/`
 * (classmap-excluded), so the addon serves its own copy standalone — the monolith serves the base
 * copy.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





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
		$allow_sensitive_tools = isset( $context['allow_sensitive_tools'] ) && true === $context['allow_sensitive_tools'];

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
					__( 'Tool "%s" is not available via chat interface for security reasons. Use the MCP endpoint or enable sensitive tools in shortcode/widget settings.', 'nvoos-content-graph-pro' ),
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
