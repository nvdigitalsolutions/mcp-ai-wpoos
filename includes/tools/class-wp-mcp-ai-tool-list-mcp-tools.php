<?php
/**
 * Tool: list_mcp_tools — Discover all MCP tools available to the current assistant.
 *
 * @package WP_MCP_AI
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List MCP Tools — Discovery tool.
 *
 * Returns every MCP tool available to the current assistant context,
 * including name, description, and full JSON Schema for each tool.
 * Useful for self-discovery when an AI agent needs to know what
 * capabilities are available before calling other tools.
 */
class WP_MCP_AI_Tool_List_MCP_Tools implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_mcp_tools';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List MCP Tools', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns a list of all available MCP tools and their schemas. Useful for agent self-discovery — call this first to learn what tools are available before invoking them. Optionally filter by toolkit (e.g. "paper_store", "ecommerce") or search by name/description.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'toolkit' => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter tools by toolkit namespace (e.g. "paper_store", "wordpress_core", "ecommerce").', 'mcp-ai-wpoos' ),
				),
				'search'  => array(
					'type'        => 'string',
					'description' => __( 'Optional. Search tools by name or description (case-insensitive).', 'mcp-ai-wpoos' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tools to return. Default 50. Max 200.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'offset'  => array(
					'type'        => 'integer',
					'description' => __( 'Offset for pagination. Default 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context (may include assistant_id).
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$toolkit = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$search  = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$limit   = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;
		$offset  = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$registry         = WP_MCP_AI_Tool_Registry::get_instance();
		$assistant_id     = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$all_tool_objects = $registry->get_tools();

		// If assistant context is available, filter to allowed tools only.
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$allowed_slugs    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			if ( ! empty( $allowed_slugs ) ) {
				$filtered = array();
				foreach ( $all_tool_objects as $tool ) {
					if ( in_array( $tool->get_slug(), $allowed_slugs, true ) ) {
						$filtered[] = $tool;
					}
				}
				$all_tool_objects = $filtered;
			}
		}

		// Build result list.
		$result     = array();
		$capability = array( 'read-only', 'write', 'state-changing', 'destructive' );
		$total      = 0;

		foreach ( $all_tool_objects as $tool ) {
			try {
				$slug        = $tool->get_slug();
				$description = $tool->get_description();
				$schema      = $tool->get_parameters_schema();

				// Skip self to avoid infinite recursion in tool listing.
				if ( 'list_mcp_tools' === $slug ) {
					continue;
				}

				// Determine risk level from capability flags.
				$risk = 'info';
				if ( method_exists( $tool, 'get_capability_flags' ) ) {
					$flags = $tool->get_capability_flags();
					if ( in_array( 'destructive', $flags, true ) ) {
						$risk = 'high';
					} elseif ( in_array( 'state-changing', $flags, true ) || in_array( 'write', $flags, true ) ) {
						$risk = 'medium';
					} elseif ( in_array( 'read-only', $flags, true ) ) {
						$risk = 'low';
					}
				}

				// Determine toolkit from definition.
				$tool_toolkit = '';
				if ( method_exists( $tool, 'get_definition' ) ) {
					$def          = $tool->get_definition();
					$tool_toolkit = isset( $def['toolkit'] ) ? $def['toolkit'] : '';
				}

				// Apply toolkit filter.
				if ( ! empty( $toolkit ) && $toolkit !== $tool_toolkit ) {
					continue;
				}

				// Apply search filter.
				if ( ! empty( $search ) ) {
					if ( false === stripos( $slug, $search )
						&& false === stripos( $description, $search )
					) {
						continue;
					}
				}

				$result[] = array(
					'name'        => $slug,
					'description' => $description,
					'toolkit'     => $tool_toolkit,
					'risk_level'  => $risk,
					'inputSchema' => is_array( $schema ) ? $schema : array(),
				);
				++$total;
			} catch ( Exception $e ) {
				continue;
			} catch ( Error $e ) {
				continue;
			}
		}

		$total_matching = $total;
		$result         = array_slice( $result, $offset, $limit );

		// Gate 2 — Escape at exit.
		$summary = sprintf(
			/* translators: 1: displayed count, 2: total matching count */
			__( 'Found %1$d tool(s) (total matching: %2$d).', 'mcp-ai-wpoos' ),
			count( $result ),
			$total_matching
		);

		return $this->format_success_response(
			$summary,
			array(
				'total'  => $total_matching,
				'count'  => count( $result ),
				'limit'  => $limit,
				'offset' => $offset,
				'tools'  => $result,
			)
		);
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
