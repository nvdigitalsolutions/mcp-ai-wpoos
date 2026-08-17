<?php
/**
 * Tool: composio_list_tools — search the Composio tool catalog.
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
 * Composio — List tools.
 */
class WP_MCP_AI_Tool_Composio_List_Tools implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'composio_list_tools';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Composio — List Tools', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Search the Composio tool catalog for tools your assistants can use (Gmail, Slack, GitHub, Notion and 1,000+ more apps). Use a natural-language search to find tools by intent, or filter by toolkit.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Optional natural-language use-case query (e.g. "send an email").', 'mcp-ai-wpoos-pro' ),
				),
				'toolkit'       => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit slug to scope results (e.g. "gmail").', 'mcp-ai-wpoos-pro' ),
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Result page (default 1).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Composio connection ID. Defaults to the first enabled Composio connection.', 'mcp-ai-wpoos-pro' ),
				),
			),
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
		$search  = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$toolkit = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$page    = isset( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;
		$resolved   = WP_MCP_AI_Composio_Tools::resolve_connection( $arguments, $connection );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( '' !== $toolkit && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_toolkit_denied', __( 'This toolkit is not in the connection allowlist.', 'mcp-ai-wpoos-pro' ) );
		}

		$filters = array(
			'page'  => $page,
			'limit' => 20,
		);

		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		if ( '' !== $toolkit ) {
			$filters['toolkits'] = $toolkit;
		}

		$client = WP_MCP_AI_Composio_Tools::build_client( $connection );
		$result = $client->list_tools( $filters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tools = array();
		foreach ( $result as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$tool_slug    = isset( $tool['tool_slug'] ) ? $tool['tool_slug'] : ( isset( $tool['slug'] ) ? $tool['slug'] : '' );
			$toolkit_slug = isset( $tool['toolkit'] ) ? $tool['toolkit'] : '';

			if ( '' !== $toolkit && '' !== $toolkit_slug && ! WP_MCP_AI_Composio_Tools::is_toolkit_allowed( $connection, $toolkit_slug ) ) {
				continue;
			}

			$tools[] = array(
				'slug'        => esc_html( (string) $tool_slug ),
				'name'        => isset( $tool['name'] ) ? esc_html( (string) $tool['name'] ) : '',
				'description' => isset( $tool['description'] ) ? esc_html( (string) $tool['description'] ) : '',
				'toolkit'     => esc_html( (string) $toolkit_slug ),
			);
		}

		// Gate 2 — Escape at exit (message composed from escaped fields only).
		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of tools */
				__( 'Found %d Composio tools.', 'mcp-ai-wpoos-pro' ),
				count( $tools )
			),
			array(
				'tools' => $tools,
				'count' => count( $tools ),
				'page'  => $page,
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
