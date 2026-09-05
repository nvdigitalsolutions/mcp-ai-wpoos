<?php
/**
 * List MCP Tools tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_List_MCP_Tools — byte-identical slug, schema, error
 * codes, and envelope; per-mode registry seam).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Lists the tools available in the active registry.
 */
class ListMcpToolsTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'list_mcp_tools';
	}

	public function getName(): string {
		return __( 'List MCP Tools', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Lists all available MCP tools with their descriptions, toolkits, risk levels, and input schemas. Use this to discover available tools before calling them.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'toolkit' => array(
					'type'        => 'string',
					'description' => __( 'Optional toolkit slug to filter tools by.', 'nvoos-content-graph-ai' ),
				),
				'search'  => array(
					'type'        => 'string',
					'description' => __( 'Optional search string to filter tools by name or description.', 'nvoos-content-graph-ai' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tools to return.', 'nvoos-content-graph-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'offset'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of tools to skip (pagination offset).', 'nvoos-content-graph-ai' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Gate 1 — sanitize at entry.
		$toolkit = isset( $arguments['toolkit'] ) ? sanitize_key( $arguments['toolkit'] ) : '';
		$search  = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$limit   = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;
		$offset  = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-content-graph-ai' ) );
		}

		// Per-mode registry seam.
		$all_tool_objects = $this->get_registry_tools();
		$assistant_id     = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		// If assistant context is available, filter to allowed tools only.
		if ( $assistant_id ) {
			$allowed_slugs = get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true );
			$allowed_slugs = is_array( $allowed_slugs ) ? $allowed_slugs : array();

			if ( ! empty( $allowed_slugs ) ) {
				$filtered = array();
				foreach ( $all_tool_objects as $tool ) {
					if ( in_array( $this->tool_slug( $tool ), $allowed_slugs, true ) ) {
						$filtered[] = $tool;
					}
				}
				$all_tool_objects = $filtered;
			}
		}

		// Build result list.
		$result = array();
		$total  = 0;

		foreach ( $all_tool_objects as $tool ) {
			try {
				$slug        = $this->tool_slug( $tool );
				$description = $this->tool_description( $tool );
				$schema      = $this->tool_schema( $tool );

				// Skip self to avoid infinite recursion in tool listing.
				if ( 'list_mcp_tools' === $slug ) {
					continue;
				}

				// Determine risk level from capability flags.
				$risk = 'info';
				if ( method_exists( $tool, 'getCapabilityFlags' ) ) {
					$flags = $tool->getCapabilityFlags();
				} elseif ( method_exists( $tool, 'get_capability_flags' ) ) {
					$flags = $tool->get_capability_flags();
				} else {
					$flags = array();
				}

				if ( in_array( 'destructive', $flags, true ) ) {
					$risk = 'high';
				} elseif ( in_array( 'state-changing', $flags, true ) || in_array( 'write', $flags, true ) ) {
					$risk = 'medium';
				} elseif ( in_array( 'read-only', $flags, true ) ) {
					$risk = 'low';
				}

				// Standalone: toolkit metadata lives on the manifest, not the
				// core tool classes (documented deviation — base tools carry
				// a get_definition() toolkit key).
				$tool_toolkit = '';

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
			} catch ( \Exception $e ) {
				continue;
			} catch ( \Error $e ) {
				continue;
			}
		}

		$result = array_slice( $result, $offset, $limit );

		// Gate 2 — escape at exit.
		$summary_text = sprintf(
			/* translators: 1: total matching tools, 2: returned count */
			__( 'Found %1$d tool(s), returning %2$d.', 'nvoos-content-graph-ai' ),
			$total,
			count( $result )
		);

		return array(
			'message'        => $summary_text,
			'summary'        => $summary_text,
			'total_found'    => $total,
			'count_returned' => count( $result ),
			'limit'          => $limit,
			'offset'         => $offset,
			'tools'          => $result,
		);
	}

	/**
	 * Resolve the active registry's tool list (per-install-mode seam).
	 *
	 * @return array
	 */
	private function get_registry_tools() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$tools = \WP_MCP_AI_Tool_Registry::get_instance()->get_tools();
			return is_array( $tools ) ? $tools : array();
		}

		return array_values( CoreBridge::instance()->tools->enabled() );
	}

	/**
	 * Read a tool's slug across the two contract styles.
	 *
	 * @param object $tool Tool instance.
	 * @return string
	 */
	private function tool_slug( $tool ) {
		return method_exists( $tool, 'getSlug' ) ? $tool->getSlug() : $tool->get_slug();
	}

	/**
	 * Read a tool's description across the two contract styles.
	 *
	 * @param object $tool Tool instance.
	 * @return string
	 */
	private function tool_description( $tool ) {
		return method_exists( $tool, 'getDescription' ) ? $tool->getDescription() : $tool->get_description();
	}

	/**
	 * Read a tool's parameter schema across the two contract styles.
	 *
	 * @param object $tool Tool instance.
	 * @return array
	 */
	private function tool_schema( $tool ) {
		return method_exists( $tool, 'getParametersSchema' ) ? $tool->getParametersSchema() : $tool->get_parameters_schema();
	}
}
