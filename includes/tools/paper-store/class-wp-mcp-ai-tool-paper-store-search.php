<?php
/**
 * Tool: paper_store_search — Full-text search across Paper Store collections.
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
 * Paper Store — Search tool.
 *
 * Searches across one or all collections using LIKE matching on title
 * and description fields, plus optional tag/status/type filters.
 */
class WP_MCP_AI_Tool_Paper_Store_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Paper_Store_Remote;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'paper_store_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Paper Store — Search', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search for records across one or all Paper Store collections by title, description, or tags. Use this for free-text discovery when you don\'t know the exact record ID.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Search query string. Matches against title, description, and tag values.', 'mcp-ai-wpoos' ),
				),
				'collection'    => array(
					'type'        => 'string',
					'description' => __( 'Optional. Limit search to a specific collection. If omitted, searches all collections.', 'mcp-ai-wpoos' ),
				),
				'tags'          => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter results by tag.', 'mcp-ai-wpoos' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional. Filter by status.', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum results. Default 20. Max 100.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'connection_id' => $this->get_connection_id_schema(),
			),
			'required'   => array( 'query' ),
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
		$query_str     = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$collection    = isset( $arguments['collection'] ) ? sanitize_key( $arguments['collection'] ) : null;
		$tag           = isset( $arguments['tags'] ) ? sanitize_text_field( $arguments['tags'] ) : null;
		$status        = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : null;
		$limit         = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20;
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		// Remote dispatch.
		if ( ! empty( $connection_id ) ) {
			$query_args = array( 'q' => $query_str );
			if ( ! empty( $collection ) ) {
				$query_args['collection'] = $collection;
			}
			if ( ! empty( $tag ) ) {
				$query_args['tag'] = $tag;
			}
			if ( ! empty( $status ) ) {
				$query_args['status'] = $status;
			}
			$query_args['limit'] = $limit;
			$endpoint            = 'mcp-ai/v1/paper-store/search?' . http_build_query( $query_args );
			return $this->execute_remote( $connection_id, $endpoint, 'GET' );
		}

		if ( empty( $query_str ) ) {
			return new WP_Error( 'missing_query', __( 'Search query is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();

		// Determine which collections to search.
		if ( ! empty( $collection ) ) {
			$collections = array( $collection );
		} else {
			$collections = $manager->list_collections();
		}

		$all_results = array();

		foreach ( $collections as $col ) {
			$repo  = $manager->get_repository( $col );
			$query = $repo->query();

			if ( ! empty( $tag ) ) {
				$query = $query->where( 'tags', '=', $tag );
			}

			if ( ! empty( $status ) ) {
				$query = $query->where( 'status', '=', $status );
			}

			// Get all matching first, then filter by text (LIKE on title/description).
			$query   = $query->limit( $limit * 2 ); // Fetch more to account for post-filtering.
			$records = $query->get();

			foreach ( $records as $record ) {
				$title       = isset( $record['title'] ) ? $record['title'] : '';
				$description = isset( $record['description'] ) ? $record['description'] : '';

				if ( false !== stripos( $title, $query_str ) || false !== stripos( $description, $query_str ) ) {
					$all_results[] = array(
						'collection' => $col,
						'record'     => $record,
					);
				}
			}

			if ( count( $all_results ) >= $limit ) {
				break;
			}
		}

		// Trim to limit.
		$all_results = array_slice( $all_results, 0, $limit );

		$summary = sprintf(
			/* translators: 1: result count, 2: search query */
			__( 'Found %1$d result(s) for "%2$s".', 'mcp-ai-wpoos' ),
			count( $all_results ),
			$query_str
		);

		// Gate 2 — Escape at exit.
		return $this->format_success_response(
			$summary,
			array(
				'query'   => esc_html( $query_str ),
				'count'   => count( $all_results ),
				'results' => $all_results,
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
			'toolkit'               => 'paper_store',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'risk_level'            => 'info',
		);
	}
}
