<?php
/**
 * Tool: paper_store_list — List records in a Paper Store collection.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paper Store — List tool.
 *
 * Lists records in a collection, optionally filtered by tags or status.
 */
class WP_MCP_AI_Tool_Paper_Store_List implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'paper_store_list';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Paper Store — List Records', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists records in a Paper Store collection, with optional filtering by tags, status, or type. Use this to discover what records exist in a collection.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'collection' => array(
					'type'        => 'string',
					'description' => __( 'Collection name (e.g. "knowledge", "prompts", "workflows").', 'mcp-ai-wpoos' ),
				),
				'tags'       => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter by a single tag value.', 'mcp-ai-wpoos' ),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter by status (e.g. "published", "draft").', 'mcp-ai-wpoos' ),
					'enum'        => array( 'published', 'draft', 'archived' ),
				),
				'type'       => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter by record type.', 'mcp-ai-wpoos' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum records to return. Default 50. Max 200.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'offset'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of records to skip (for pagination). Default 0.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
			),
			'required'  => array( 'collection' ),
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — Sanitize at entry.
		$collection = sanitize_key( $arguments['collection'] );
		$tag        = isset( $arguments['tags'] ) ? sanitize_text_field( $arguments['tags'] ) : null;
		$status     = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : null;
		$type       = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : null;
		$limit      = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;
		$offset     = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		if ( empty( $collection ) ) {
			return new WP_Error( 'missing_collection', __( 'Collection name is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
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

		if ( ! empty( $type ) ) {
			$query = $query->where( 'type', '=', $type );
		}

		$total   = $query->count();
		$records = $query->order_by( 'updated_at', 'desc' )->limit( $limit )->offset( $offset )->get();

		$summary = sprintf(
			/* translators: 1: record count, 2: collection name, 3: total matching */
			__( 'Found %1$d record(s) in collection "%2$s" (total matching: %3$d).', 'mcp-ai-wpoos' ),
			count( $records ),
			$collection,
			$total
		);

		// Gate 2 — Escape at exit.
		$result = array(
			'collection'  => esc_html( $collection ),
			'total'       => $total,
			'count'       => count( $records ),
			'records'     => $records,
			'offset'      => $offset,
			'limit'       => $limit,
		);

		return $this->format_success_response( $summary, $result );
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
			'toolkit'               => 'paper_store',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'info',
		);
	}
}
