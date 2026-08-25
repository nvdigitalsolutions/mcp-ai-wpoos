<?php
/**
 * Tool: composio_get_tool_schema — fetch a tool's input/output schema.
 *
 * Pro tool (PHP 8.1+). Requires edit_posts capability.
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
 * Composio — Get tool schema.
 */
class WP_MCP_AI_Tool_Composio_Get_Tool_Schema implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_get_tool_schema';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — Get Tool Schema', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Fetch the input and output schema for a Composio tool (SCREAMING_SNAKE slug such as GMAIL_SEND_EMAIL). Use before calling composio_execute_tool to construct valid arguments.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_slug'     => array(
					'type'        => 'string',
					'description' => __( 'Composio tool slug, e.g. GMAIL_SEND_EMAIL.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional NV oOS Composio connection ID ("conn_..."), identifying this site\'s Composio project integration. NOT a connected-account ID — do not pass a "ca_..." value here. Omit it to use the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'tool_slug' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability(): string {
		return 'edit_posts';
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
		$tool_slug = isset( $arguments['tool_slug'] ) ? sanitize_text_field( $arguments['tool_slug'] ) : '';

		if ( '' === $tool_slug ) {
			return new WP_Error( 'missing_params', __( 'tool_slug is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$client = WP_MCP_AI_Composio_Tools::build_client( $connection );
		$schema = $client->get_tool_schema( $tool_slug );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			/* translators: %s: tool slug */
			sprintf( __( 'Schema for %s retrieved.', 'mcp-ai-wpoos-pro' ), esc_html( $tool_slug ) ),
			array(
				'tool_slug' => esc_html( $tool_slug ),
				'schema'    => $schema,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'read', 'pro', 'requires-capability', 'remote-api' );
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
			'risk_level'            => 'low',
		);
	}
}
