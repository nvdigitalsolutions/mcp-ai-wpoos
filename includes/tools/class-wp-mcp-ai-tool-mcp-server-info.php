<?php
/**
 * Tool: mcp_server_info — Report the identity of the MCP server the assistant
 * is connected to (plugin version, mode, site, and optional bridge name).
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Server Info — Identity discovery tool.
 *
 * Returns the server-side identity behind the MCP surface: plugin version,
 * distribution mode, site name/URL, the MCP endpoint, and — when the request
 * was fronted by an MCP bridge or gateway that declared itself via the
 * X-MCP-Bridge-Name header — the bridge name. Purely informational: no
 * secrets, tokens, emails, or user data are ever included.
 */
class WP_MCP_AI_Tool_MCP_Server_Info implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum accepted bridge-name length (client-supplied header hygiene).
	 *
	 * @var int
	 */
	const BRIDGE_NAME_MAX_LENGTH = 120;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'mcp_server_info';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'MCP Server Info', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reports the identity of the MCP server the assistant is connected to: plugin version, distribution mode, site name and URL, MCP endpoint, and the optional bridge/gateway name. Purely informational — never includes secrets, tokens, emails, or user data.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'The assistant needs to know which server, site, or bridge it is talking to (e.g. distinguishing between multiple MCP connections).', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Discovering available tools; use list_mcp_tools. Diagnosing site health or environment; use get_site_health or get_environment_status.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_mcp_tools', 'get_site_summary', 'get_environment_status' ),
			'notes'           => __( 'No parameters. Returns only non-sensitive identity metadata; the bridge name appears only when the fronting bridge declared itself via the X-MCP-Bridge-Name header.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Build the server-identity payload shared by this tool and the
	 * list_mcp_tools `_meta` block.
	 *
	 * Returns only non-sensitive, publicly-known identity metadata. The
	 * bridge name is client-supplied, so it is sanitised here again
	 * defensively even though the REST layer sanitises it at entry.
	 *
	 * @param array $context Tool execution context (may include bridge_name).
	 * @return array Identity payload with a `server` key and, when present, a `bridge_name` key.
	 */
	public static function build_server_identity( array $context = array() ) {
		$mode = 'complete';
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			$mode = 'base';
		}

		$identity = array(
			'server' => array(
				'name'         => 'NV oOS',
				'version'      => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
				'mode'         => $mode,
				'pro_active'   => defined( 'WP_MCP_AI_PRO_VERSION' ),
				'site_name'    => get_bloginfo( 'name' ),
				'site_url'     => home_url( '/' ),
				'mcp_endpoint' => rest_url( 'mcp-ai/v1/mcp' ),
			),
		);

		if ( ! empty( $context['bridge_name'] ) && is_string( $context['bridge_name'] ) ) {
			$bridge_name = sanitize_text_field( $context['bridge_name'] );
			if ( function_exists( 'mb_substr' ) ) {
				$bridge_name = mb_substr( $bridge_name, 0, self::BRIDGE_NAME_MAX_LENGTH );
			} else {
				$bridge_name = substr( $bridge_name, 0, self::BRIDGE_NAME_MAX_LENGTH );
			}

			if ( '' !== $bridge_name ) {
				$identity['bridge_name'] = $bridge_name;
			}
		}

		/**
		 * Filter the MCP server identity payload before it is returned.
		 *
		 * Consumers MUST NOT add secrets, tokens, emails, or user data
		 * through this filter — the payload is readable by any authenticated
		 * MCP caller with the `read` capability.
		 *
		 * @param array $identity Identity payload.
		 * @param array $context  Tool execution context.
		 */
		return apply_filters( 'wp_mcp_ai_mcp_server_identity', $identity, $context );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments (none accepted).
	 * @param array $context   Execution context (may include bridge_name).
	 * @return array|WP_Error Canonical success envelope or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — the tool accepts no arguments, so entry sanitisation is the
		// empty case; the identity payload is built from trusted constants and
		// core functions plus the pre-sanitised bridge name.

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$identity = self::build_server_identity( $context );

		// Gate 2 — values are plain non-HTML metadata returned as structured
		// data; the summary message composes only sanitised/trusted strings.

		/* translators: %s: server name */
		$summary = sprintf( __( 'MCP server identity: %s.', 'mcp-ai-wpoos' ), $identity['server']['name'] );

		return $this->format_success_response( $summary, $identity );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'cacheable', 'requires-capability' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'discovery',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'info',
		);
	}
}
