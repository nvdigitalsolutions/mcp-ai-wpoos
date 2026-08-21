<?php
/**
 * Tool: composio_create_connect_link — generate a hosted authentication URL.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio — Create Connect Link.
 */
class WP_MCP_AI_Tool_Composio_Create_Connect_Link implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_create_connect_link';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — Create Connect Link', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Create a one-time Composio Connect Link so a user can authenticate their own app account (Gmail, Slack, GitHub, ...). Returns a hosted URL; the user completes the flow on Composio and their credentials stay with Composio.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'toolkit'       => array(
					'type'        => 'string',
					'description' => __( 'Toolkit slug to connect (e.g. "gmail").', 'mcp-ai-wpoos-pro' ),
				),
				'wp_user_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Optional WordPress user ID the connection belongs to (per-user mode).', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Composio connection ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'toolkit' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$toolkit    = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$wp_user_id = isset( $arguments['wp_user_id'] ) ? absint( $arguments['wp_user_id'] ) : 0;

		if ( '' === $toolkit ) {
			return new WP_Error( 'missing_params', __( 'toolkit is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id = isset( $connection['id'] ) ? $connection['id'] : '';

		$result = WP_MCP_AI_Composio_Auth_Handler::create_link( $connection_id, $toolkit, $wp_user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			/* translators: %s: toolkit slug */
			sprintf( __( 'Connect Link for %s created. Share the URL with the user.', 'mcp-ai-wpoos-pro' ), esc_html( $toolkit ) ),
			array(
				'toolkit' => esc_html( $toolkit ),
				'url'     => esc_url( $result['url'] ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'write', 'pro', 'requires-capability', 'remote-api' );
	}

	/**
	 * Get extended tool definition.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'composio',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'medium',
		);
	}
}
