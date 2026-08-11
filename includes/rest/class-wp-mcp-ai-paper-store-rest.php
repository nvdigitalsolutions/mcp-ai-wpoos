<?php
/**
 * Paper Store REST API Controller.
 *
 * Exposes Paper Store CRUD operations via the WordPress REST API at
 * /wp-json/mcp-ai/v1/paper-store/ so that remote WordPress sites
 * (configured as Remote Connections) can read and write Paper Store
 * records through the remote_wp_connection tool family.
 *
 * Follows the standalone REST controller pattern (no base-class DI
 * dependency) so it loads safely before the plugin container is available.
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
 * Paper Store REST API controller.
 *
 * Registers routes under mcp-ai/v1 that mirror the local
 * paper_store_* tool operations.
 */
class WP_MCP_AI_Paper_Store_REST {

	/**
	 * Namespace for REST API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Constructor — hooks into rest_api_init.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function register_routes() {
		$ns = self::NAMESPACE;

		// GET  /paper-store  — list collections.
		register_rest_route(
			$ns,
			'/paper-store',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_collections' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// GET  /paper-store/search  — search across collections.
		register_rest_route(
			$ns,
			'/paper-store/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_records' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'q'          => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'collection' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'tag'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'     => array(
						'type'              => 'string',
						'enum'              => array( 'published', 'draft', 'archived' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'      => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 100,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET/POST  /paper-store/{collection}  — list / create records.
		register_rest_route(
			$ns,
			'/paper-store/(?P<collection>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_records' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'collection' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'tag'        => array(
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'     => array(
							'enum'              => array( 'published', 'draft', 'archived' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'type'       => array(
							'sanitize_callback' => 'sanitize_key',
						),
						'limit'      => array(
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 200,
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'offset'     => array(
							'type'              => 'integer',
							'minimum'           => 0,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_record' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'collection'  => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'id'          => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'title'       => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description' => array(
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tags'        => array(
							'type'              => 'array',
							'items'             => array( 'type' => 'string' ),
							'sanitize_callback' => function ( $value ) {
								return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
							},
						),
						'status'      => array(
							'enum'              => array( 'published', 'draft', 'archived' ),
							'default'           => 'published',
							'sanitize_callback' => 'sanitize_key',
						),
						'body'        => array( 'type' => 'object' ),
						'meta'        => array( 'type' => 'object' ),
					),
				),
			)
		);

		// GET / PUT / DELETE  /paper-store/{collection}/{record_id}.
		register_rest_route(
			$ns,
			'/paper-store/(?P<collection>[a-zA-Z0-9_-]+)/(?P<record_id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_record' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_record' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'description' => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'tags'        => array(
							'type'              => 'array',
							'items'             => array( 'type' => 'string' ),
							'sanitize_callback' => function ( $value ) {
								return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
							},
						),
						'status'      => array(
							'enum'              => array( 'published', 'draft', 'archived' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'body'        => array( 'type' => 'object' ),
						'meta'        => array( 'type' => 'object' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_record' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// GET  /paper-store/{collection}/export  — export collection.
		register_rest_route(
			$ns,
			'/paper-store/(?P<collection>[a-zA-Z0-9_-]+)/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_collection' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'tag'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'status' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		// POST /paper-store/{collection}/import  — import records.
		register_rest_route(
			$ns,
			'/paper-store/(?P<collection>[a-zA-Z0-9_-]+)/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_records' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'records'   => array(
						'required' => true,
						'type'     => 'array',
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * List all Paper Store collections.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_collections( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API callback signature.
		$manager     = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$collections = $manager->list_collections();

		return new WP_REST_Response(
			array(
				'collections' => $collections,
				'count'       => count( $collections ),
			),
			200
		);
	}

	/**
	 * List records in a collection.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_records( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$tag        = $request->get_param( 'tag' );
		$status     = $request->get_param( 'status' );
		$type       = $request->get_param( 'type' );
		$limit      = $request->get_param( 'limit' ) ?? 50;
		$offset     = $request->get_param( 'offset' ) ?? 0;

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

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'total'      => $total,
				'count'      => count( $records ),
				'records'    => $records,
				'offset'     => $offset,
				'limit'      => $limit,
			),
			200
		);
	}

	/**
	 * Read a single record.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_record( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$record_id  = $request->get_param( 'record_id' );

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );
		$record  = $repo->find( $record_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( null === $record ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: 1: record ID, 2: collection name */
					__( 'Record "%1$s" not found in collection "%2$s".', 'mcp-ai-wpoos' ),
					$record_id,
					$collection
				),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'record'     => $record,
			),
			200
		);
	}

	/**
	 * Create a new record.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_record( WP_REST_Request $request ) {
		$collection  = $request->get_param( 'collection' );
		$id          = $request->get_param( 'id' );
		$title       = $request->get_param( 'title' );
		$description = $request->get_param( 'description' );
		$description = null !== $description ? $description : '';
		$tags        = $request->get_param( 'tags' );
		$tags        = null !== $tags ? $tags : array();
		$status      = $request->get_param( 'status' );
		$status      = null !== $status ? $status : 'published';
		$body        = $request->get_param( 'body' );
		$meta        = $request->get_param( 'meta' );

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		if ( $repo->exists( $id ) ) {
			return new WP_Error(
				'duplicate_id',
				sprintf(
					/* translators: %s: record ID */
					__( 'A record with ID "%s" already exists in this collection.', 'mcp-ai-wpoos' ),
					$id
				),
				array( 'status' => 409 )
			);
		}

		$record = array(
			'id'          => $id,
			'type'        => $collection,
			'title'       => $title,
			'description' => $description,
			'tags'        => $tags,
			'status'      => $status,
		);

		if ( null !== $body ) {
			$record['body'] = $body;
		}
		if ( null !== $meta ) {
			$record['meta'] = $meta;
		}

		$saved = $repo->save( $record );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'record'     => $saved,
			),
			201
		);
	}

	/**
	 * Update an existing record.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_record( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$record_id  = $request->get_param( 'record_id' );

		$params      = $request->get_params();
		$update_data = array();

		$updatable = array( 'title', 'description', 'status', 'body', 'meta', 'tags' );
		foreach ( $updatable as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$update_data[ $field ] = $params[ $field ];
			}
		}

		if ( empty( $update_data ) ) {
			return new WP_Error(
				'no_changes',
				__( 'No fields provided to update.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$updated = $repo->update( $record_id, $update_data );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'record'     => $updated,
			),
			200
		);
	}

	/**
	 * Delete a record.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_record( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$record_id  = $request->get_param( 'record_id' );

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo    = $manager->get_repository( $collection );

		$result = $repo->delete( $record_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'record_id'  => $record_id,
				'deleted'    => true,
			),
			200
		);
	}

	/**
	 * Search records across collections.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_records( WP_REST_Request $request ) {
		$query_str  = $request->get_param( 'q' );
		$collection = $request->get_param( 'collection' );
		$tag        = $request->get_param( 'tag' );
		$status     = $request->get_param( 'status' );
		$limit      = $request->get_param( 'limit' ) ?? 20;

		$manager = WP_MCP_AI_Paper_Store_Manager::get_instance();

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

			$query   = $query->limit( $limit * 2 );
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

		$all_results = array_slice( $all_results, 0, $limit );

		return new WP_REST_Response(
			array(
				'query'   => $query_str,
				'count'   => count( $all_results ),
				'results' => $all_results,
			),
			200
		);
	}

	/**
	 * Export all records from a collection.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_collection( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$tag        = $request->get_param( 'tag' );
		$status     = $request->get_param( 'status' );

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

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'count'      => count( $records ),
				'records'    => $records,
			),
			200
		);
	}

	/**
	 * Bulk import records into a collection.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_records( WP_REST_Request $request ) {
		$collection = $request->get_param( 'collection' );
		$records    = $request->get_param( 'records' );
		$overwrite  = $request->get_param( 'overwrite' ) ?? true;

		if ( ! is_array( $records ) || empty( $records ) ) {
			return new WP_Error(
				'missing_records',
				__( 'Records array is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$manager  = WP_MCP_AI_Paper_Store_Manager::get_instance();
		$repo     = $manager->get_repository( $collection );
		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['id'] ) ) {
				++$skipped;
				continue;
			}

			if ( ! $overwrite && $repo->exists( sanitize_key( $record['id'] ) ) ) {
				++$skipped;
				continue;
			}

			$result = $repo->save( $record );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'id'    => $record['id'] ?? 'unknown',
					'error' => $result->get_error_message(),
				);
			} else {
				++$imported;
			}
		}

		return new WP_REST_Response(
			array(
				'collection' => $collection,
				'imported'   => $imported,
				'skipped'    => $skipped,
				'errors'     => $errors,
			),
			201
		);
	}

	/**
	 * Permission callback — requires read capability.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function check_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API permission callback signature.
		return current_user_can( 'read' );
	}
}

// Initialize REST API.
new WP_MCP_AI_Paper_Store_REST();
