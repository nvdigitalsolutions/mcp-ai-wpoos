<?php
/**
 * NV oOS Document Editor — REST API Controller
 *
 * Registers:
 *   GET    /nvoos-document-editor/v1/health
 *   GET    /nvoos-document-editor/v1/documents
 *   GET    /nvoos-document-editor/v1/documents/{id}
 *   POST   /nvoos-document-editor/v1/documents
 *   PUT    /nvoos-document-editor/v1/documents/{id}
 *   DELETE /nvoos-document-editor/v1/documents/{id}
 *
 * Documents are stored as posts with post_type 'nvoos_document'.
 * The editor SPA loads / persists documents through these routes.
 *
 * @package NV_oOS_Document_Editor
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Document Editor addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Document_Editor_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-document-editor/v1';

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'nvoos_document';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		// Collection: list + create.
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_documents' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'per_page' => array(
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_document' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'title'   => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'content' => array(
							'default'           => '',
							'sanitize_callback' => 'wp_kses_post',
						),
					),
				),
			)
		);

		// Item: get + update + delete.
		register_rest_route(
			self::REST_NAMESPACE,
			'/documents/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_document' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_document' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'id'      => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'title'   => array(
							'sanitize_callback' => 'sanitize_text_field',
						),
						'content' => array(
							'sanitize_callback' => 'wp_kses_post',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_document' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Require manage_options (admin/health routes).
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You do not have permission to access this endpoint.', 'nvoos-document-editor' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Require edit_posts (document CRUD).
	 *
	 * @return bool|WP_Error
	 */
	public static function edit_permission() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You do not have permission to manage documents.', 'nvoos-document-editor' ),
			array( 'status' => 403 )
		);
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_DOCUMENT_EDITOR_VERSION' ) ? NVOOS_DOCUMENT_EDITOR_VERSION : 'unknown',
			)
		);
	}

	/**
	 * List documents (paginated, optional search).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function list_documents( $request ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $request->get_param( 'per_page' ),
			'paged'          => (int) $request->get_param( 'page' ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::prepare_document( $post );
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Get one document.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_document( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Document not found.', 'nvoos-document-editor' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response( self::prepare_document( $post ) );
	}

	/**
	 * Create a new document.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_document( $request ) {
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => (string) $request->get_param( 'title' ),
				'post_content' => (string) $request->get_param( 'content' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		return rest_ensure_response( self::prepare_document( $post ) );
	}

	/**
	 * Update an existing document.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_document( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Document not found.', 'nvoos-document-editor' ),
				array( 'status' => 404 )
			);
		}

		$update = array( 'ID' => $id );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$update['post_title'] = sanitize_text_field( (string) $title );
		}
		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$update['post_content'] = wp_kses_post( (string) $content );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::prepare_document( get_post( $id ) ) );
	}

	/**
	 * Delete a document (trash it).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_document( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Document not found.', 'nvoos-document-editor' ),
				array( 'status' => 404 )
			);
		}
		wp_trash_post( $id );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a WP_Post to the API representation.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function prepare_document( $post ) {
		return array(
			'id'         => (int) $post->ID,
			'title'      => esc_html( $post->post_title ),
			'content'    => $post->post_content, // HTML, already sanitized on write via wp_kses_post.
			'created_at' => get_post_time( 'c', true, $post ),
			'updated_at' => get_post_modified_time( 'c', true, $post ),
		);
	}
}
