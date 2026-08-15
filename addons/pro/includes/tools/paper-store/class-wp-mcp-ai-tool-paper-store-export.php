<?php
/**
 * Tool: paper_store_export — Export a Paper Store collection.
 *
 * Pro tool (PHP 8.1+). Requires manage_options capability.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

/**
 * Paper Store — Export tool.
 */
class WP_MCP_AI_Tool_Paper_Store_Export implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Paper_Store_Remote;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'paper_store_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Paper Store — Export Records', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Exports all records from a Paper Store collection as a JSON array. Optionally filter by tags or status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection'    => array(
					'type'        => 'string',
					'description' => __( 'Collection name to export.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'          => array(
					'type'        => 'string',
					'description' => __( 'Optional. Export only records with this tag.', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional. Export only records with this status.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => $this->get_connection_id_schema(),
			),
			'required'   => array( 'collection' ),
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
		$collection    = sanitize_key( $arguments['collection'] );
		$tag           = isset( $arguments['tags'] ) ? sanitize_text_field( $arguments['tags'] ) : null;
		$status        = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : null;
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $collection ) ) {
			return new WP_Error( 'missing_params', __( 'Collection name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Remote dispatch.
		if ( ! empty( $connection_id ) ) {
			$query_args = array();
			if ( ! empty( $tag ) ) {
				$query_args['tag'] = $tag;
			}
			if ( ! empty( $status ) ) {
				$query_args['status'] = $status;
			}
			$endpoint = 'mcp-ai/v1/paper-store/' . $collection . '/export';
			if ( ! empty( $query_args ) ) {
				$endpoint .= '?' . http_build_query( $query_args );
			}
			return $this->execute_remote( $connection_id, $endpoint, 'GET' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$query = $repo->query();

		if ( ! empty( $tag ) ) {
			$query = $query->where( 'tags', '=', $tag );
		}

		if ( ! empty( $status ) ) {
			$query = $query->where( 'status', '=', $status );
		}

		$records = $query->get();

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			sprintf(
				/* translators: 1: count, 2: collection */
				__( 'Exported %1$d record(s) from "%2$s".', 'mcp-ai-wpoos-pro' ),
				count( $records ),
				$collection
			),
			array(
				'collection' => esc_html( $collection ),
				'count'      => count( $records ),
				'records'    => $records,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'read-only', 'local-only', 'cacheable', 'requires-capability', 'pro' );
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
			'toolkit'               => 'paper_store',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'info',
		);
	}
}
