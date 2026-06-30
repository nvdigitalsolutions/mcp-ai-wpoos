<?php
/**
 * NV oOS Comic Reader — REST API Controller
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Comic Reader addon.
 *
 * Provides endpoints for listing comics from the WordPress Media Library,
 * retrieving comic metadata (page count, cover thumbnail), and serving
 * individual pages from CBR/CBZ archives.
 *
 * @since 0.1.0
 */
class NV_oOS_Comic_Reader_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-comic-reader/v1';

	/**
	 * Comic file extensions recognised by the addon.
	 *
	 * @var string[]
	 */
	const COMIC_EXTENSIONS = array( 'cbr', 'cbz', 'cb7', 'cbt' );

	/**
	 * Register REST routes.
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
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_comics' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_comic' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)/file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_comic_file' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)/cover',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_comic_cover' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)/delete',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_comic' ),
				'permission_callback' => array( __CLASS__, 'delete_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload_comic' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
			)
		);

		// ─── Creator Routes ───────────────────────────────────────

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/comics',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_creator_comic' ),
					'permission_callback' => array( __CLASS__, 'creator_permission' ),
					'args'                => array(
						'title'             => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'style'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reading_direction' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page_layout'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'series_name'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'issue_number'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_creator_comics' ),
					'permission_callback' => array( __CLASS__, 'creator_permission' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/comics/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_creator_comic' ),
					'permission_callback' => array( __CLASS__, 'creator_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_creator_comic' ),
					'permission_callback' => array( __CLASS__, 'creator_permission' ),
					'args'                => array(
						'id'                => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'title'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'style'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'reading_direction' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page_layout'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'series_name'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'issue_number'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/comics/(?P<id>\d+)/panels',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_creator_panels' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/comics/(?P<id>\d+)/panels/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate_creator_panels' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
				'args'                => array(
					'id'        => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'panel_ids' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/comics/(?P<id>\d+)/characters',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_creator_characters' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/characters/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_creator_character' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/scripts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_creator_script' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/creator/styles',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_creator_styles' ),
				'permission_callback' => array( __CLASS__, 'creator_permission' ),
			)
		);
	}

	/**
	 * Health check endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'           => 'ok',
				'version'          => defined( 'NVOOS_COMIC_READER_VERSION' ) ? NVOOS_COMIC_READER_VERSION : 'unknown',
				'supports_creator' => true,
			)
		);
	}

	/**
	 * Manifest endpoint — addon metadata and bundle info.
	 *
	 * @return WP_REST_Response
	 */
	public static function manifest() {
		$payload = array(
			'slug'              => 'comic-reader',
			'name'              => __( 'NV oOS Comic Reader', 'nvoos-comic-reader' ),
			'version'           => defined( 'NVOOS_COMIC_READER_VERSION' ) ? NVOOS_COMIC_READER_VERSION : 'unknown',
			'surface'           => 'reader',
			'supported_formats' => self::COMIC_EXTENSIONS,
			'bundle'            => array(
				'js'  => defined( 'NVOOS_COMIC_READER_URL' ) ? NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.js' : '',
				'css' => defined( 'NVOOS_COMIC_READER_URL' ) ? NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.css' : '',
			),
		);
		return rest_ensure_response( apply_filters( 'nvoos_comic_reader_manifest', $payload ) );
	}

	/**
	 * List comics from the WordPress Media Library.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_comics( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'value'   => self::get_mime_regex(),
					'compare' => 'REGEXP',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query  = new WP_Query( $args );
		$comics = array();

		foreach ( $query->posts as $post ) {
			$comics[] = self::format_comic_item( $post );
		}

		return rest_ensure_response(
			array(
				'comics'      => $comics,
				'total'       => (int) $query->found_posts,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Get a single comic's metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_comic( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$ext = strtolower( pathinfo( $post->guid, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( self::format_comic_item( $post ) );
	}

	/**
	 * Get the raw comic file for client-side extraction.
	 *
	 * Forces download of the archive so the browser can process it.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_comic_file( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$file_path = get_attached_file( $id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_missing', __( 'Comic file is missing from disk.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return new WP_Error( 'read_error', __( 'Failed to read comic file.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $content, 200 );
		$response->header( 'Content-Type', self::get_mime_type( $ext ) );
		$response->header( 'Content-Length', (string) filesize( $file_path ) );
		$response->header( 'Content-Disposition', 'inline; filename="' . basename( $file_path ) . '"' );

		return $response;
	}

	/**
	 * Get the cover image for a comic (first page thumbnail).
	 *
	 * Returns a cached thumbnail URL if available, otherwise the comic
	 * file itself (cover extraction happens client-side).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_comic_cover( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$cover_id = get_post_meta( $id, '_nvoos_comic_cover_id', true );

		if ( $cover_id ) {
			$cover_url = wp_get_attachment_image_url( $cover_id, 'medium' );
			if ( $cover_url ) {
				return rest_ensure_response(
					array(
						'id'     => (int) $cover_id,
						'url'    => $cover_url,
						'cached' => true,
					)
				);
			}
		}

		// No cached cover — client will extract from archive.
		return rest_ensure_response(
			array(
				'id'      => $id,
				'url'     => '',
				'cached'  => false,
				'extract' => rest_url( self::REST_NAMESPACE . '/comics/' . $id . '/file' ),
			)
		);
	}

	/**
	 * Delete a comic from the media library.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_comic( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$ext = strtolower( pathinfo( $post->guid, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$result = wp_delete_attachment( $id, true );
		if ( false === $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete comic.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Upload a comic file via REST.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_comic( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];

		// Validate extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error(
				'invalid_format',
				sprintf(
					/* translators: %s: comma-separated list of supported extensions */
					__( 'Unsupported file format. Supported formats: %s', 'nvoos-comic-reader' ),
					implode( ', ', self::COMIC_EXTENSIONS )
				),
				array( 'status' => 400 )
			);
		}

		// Use WordPress media upload handling.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'upload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$post = get_post( $attachment_id );

		return rest_ensure_response(
			self::format_comic_item( $post ),
			201
		);
	}

	/**
	 * Read permission — user must be logged in with the read capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function read_permission() {
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You must be logged in to access comics.', 'nvoos-comic-reader' ), array( 'status' => 401 ) );
	}

	/**
	 * Upload permission — user must be able to upload files.
	 *
	 * @return bool|WP_Error
	 */
	public static function upload_permission() {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload files.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
	}

	/**
	 * Delete permission — user must be able to delete posts.
	 *
	 * @return bool|WP_Error
	 */
	public static function delete_permission() {
		if ( current_user_can( 'delete_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to delete files.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
	}

	/**
	 * Creator permission — user must be able to edit posts.
	 *
	 * @return bool|WP_Error
	 */
	public static function creator_permission() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to use the creator.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
	}

	/**
	 * Create a new creator comic CPT.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_creator_comic( $request ) {
		$title             = $request->get_param( 'title' );
		$style             = $request->get_param( 'style' );
		$reading_direction = $request->get_param( 'reading_direction' );
		$page_layout       = $request->get_param( 'page_layout' );
		$series_name       = $request->get_param( 'series_name' );
		$issue_number      = $request->get_param( 'issue_number' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_comic',
				'post_title'  => $title,
				'post_status' => 'publish',
				'meta_input'  => array(
					'_nvoos_comic_style'             => $style,
					'_nvoos_comic_reading_direction' => $reading_direction,
					'_nvoos_comic_page_layout'       => $page_layout,
					'_nvoos_comic_series_name'       => $series_name,
					'_nvoos_comic_issue_number'      => $issue_number,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'create_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( self::format_creator_comic( $post_id ), 201 );
	}

	/**
	 * List creator comics from the mcp_ai_comic CPT.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_creator_comics( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );

		$args = array(
			'post_type'      => 'mcp_ai_comic',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query  = new WP_Query( $args );
		$comics = array();

		foreach ( $query->posts as $post ) {
			$comics[] = self::format_creator_comic( $post->ID );
		}

		return rest_ensure_response(
			array(
				'comics'      => $comics,
				'total'       => (int) $query->found_posts,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Get a single creator comic CPT with all meta.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_creator_comic( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_comic' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( self::format_creator_comic( $id ) );
	}

	/**
	 * Update comic metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_creator_comic( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_comic' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$updates = array( 'ID' => $id );
		if ( null !== $request->get_param( 'title' ) ) {
			$updates['post_title'] = $request->get_param( 'title' );
		}

		$result = wp_update_post( $updates, true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$meta_fields = array(
			'style'             => '_nvoos_comic_style',
			'reading_direction' => '_nvoos_comic_reading_direction',
			'page_layout'       => '_nvoos_comic_page_layout',
			'series_name'       => '_nvoos_comic_series_name',
			'issue_number'      => '_nvoos_comic_issue_number',
		);

		foreach ( $meta_fields as $param => $meta_key ) {
			if ( null !== $request->get_param( $param ) ) {
				update_post_meta( $id, $meta_key, $request->get_param( $param ) );
			}
		}

		return rest_ensure_response( self::format_creator_comic( $id ) );
	}

	/**
	 * List panels for a comic ordered by page, then panel number.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_creator_panels( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_comic' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$panels = get_post_meta( $id, '_nvoos_comic_panels', true );
		if ( ! is_array( $panels ) ) {
			$panels = array();
		}

		// Ensure numeric ordering.
		usort(
			$panels,
			function ( $a, $b ) {
				$page_a = isset( $a['page'] ) ? (int) $a['page'] : 0;
				$page_b = isset( $b['page'] ) ? (int) $b['page'] : 0;
				if ( $page_a !== $page_b ) {
					return $page_a - $page_b;
				}
				$pn_a = isset( $a['panel'] ) ? (int) $a['panel'] : 0;
				$pn_b = isset( $b['panel'] ) ? (int) $b['panel'] : 0;
				return $pn_a - $pn_b;
			}
		);

		return rest_ensure_response(
			array(
				'panels'   => $panels,
				'total'    => count( $panels ),
				'comic_id' => $id,
			)
		);
	}

	/**
	 * Trigger batch panel generation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate_creator_panels( $request ) {
		$id        = $request->get_param( 'id' );
		$panel_ids = $request->get_param( 'panel_ids' );

		$post = get_post( $id );
		if ( ! $post || 'mcp_ai_comic' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		/**
		 * Fires when panel generation is requested.
		 *
		 * @param int   $comic_id  The comic post ID.
		 * @param array $panel_ids Optional array of specific panel IDs to generate.
		 */
		do_action( 'nvoos_comic_reader_generate_panels', $id, $panel_ids );

		return rest_ensure_response(
			array(
				'status'    => 'generating',
				'comic_id'  => $id,
				'panel_ids' => $panel_ids,
				'message'   => __( 'Panel generation started.', 'nvoos-comic-reader' ),
			)
		);
	}

	/**
	 * List characters for a comic.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_creator_characters( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_comic' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$characters = get_post_meta( $id, '_nvoos_comic_characters', true );
		if ( ! is_array( $characters ) ) {
			$characters = array();
		}

		return rest_ensure_response(
			array(
				'characters' => $characters,
				'total'      => count( $characters ),
				'comic_id'   => $id,
			)
		);
	}

	/**
	 * Get a single character with meta.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_creator_character( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_character' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Character not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$meta_keys = array(
			'_nvoos_character_name',
			'_nvoos_character_description',
			'_nvoos_character_style_notes',
			'_nvoos_character_role',
			'_nvoos_character_reference_image',
			'_nvoos_character_comic_id',
		);

		$meta = array();
		foreach ( $meta_keys as $key ) {
			$meta[ $key ] = get_post_meta( $id, $key, true );
		}

		return rest_ensure_response(
			array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'meta'  => $meta,
			)
		);
	}

	/**
	 * Get a single script with meta and scene breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_creator_script( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_script' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Script not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$premise     = get_post_meta( $id, '_nvoos_script_premise', true );
		$genre       = get_post_meta( $id, '_nvoos_script_genre', true );
		$panel_count = get_post_meta( $id, '_nvoos_script_panel_count', true );
		$scenes      = get_post_meta( $id, '_nvoos_script_scenes', true );

		if ( ! is_array( $scenes ) ) {
			$scenes = array();
		}

		return rest_ensure_response(
			array(
				'id'          => (int) $post->ID,
				'title'       => get_the_title( $post ),
				'premise'     => $premise,
				'genre'       => $genre,
				'panel_count' => $panel_count ? (int) $panel_count : 0,
				'scenes'      => $scenes,
			)
		);
	}

	/**
	 * Return list of available comic style presets.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_creator_styles() {
		$styles = array(
			array(
				'slug'        => 'manga',
				'name'        => __( 'Manga', 'nvoos-comic-reader' ),
				'description' => __( 'Japanese comic style with expressive characters and dynamic panel layouts.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'american-comic',
				'name'        => __( 'American Comic', 'nvoos-comic-reader' ),
				'description' => __( 'Bold superhero style with strong inking and vibrant colours.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'webtoon',
				'name'        => __( 'Webtoon', 'nvoos-comic-reader' ),
				'description' => __( 'Vertical scrolling format optimised for digital reading.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'graphic-novel',
				'name'        => __( 'Graphic Novel', 'nvoos-comic-reader' ),
				'description' => __( 'Long-form storytelling with literary depth and detailed artwork.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'noir',
				'name'        => __( 'Noir', 'nvoos-comic-reader' ),
				'description' => __( 'High-contrast black and white with dramatic shadows.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'silver-age',
				'name'        => __( 'Silver Age', 'nvoos-comic-reader' ),
				'description' => __( 'Retro 1950s–1970s comic style with classic halftone colouring.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'euro-comic',
				'name'        => __( 'Euro Comic', 'nvoos-comic-reader' ),
				'description' => __( 'European bande dessinée style with rich painted colours.', 'nvoos-comic-reader' ),
			),
			array(
				'slug'        => 'comic-strip',
				'name'        => __( 'Comic Strip', 'nvoos-comic-reader' ),
				'description' => __( 'Newspaper-style strips with simple, clean linework.', 'nvoos-comic-reader' ),
			),
		);

		return rest_ensure_response( apply_filters( 'nvoos_comic_reader_creator_styles', $styles ) );
	}

	/**
	 * Format a creator comic CPT into a response array.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private static function format_creator_comic( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		return array(
			'id'                => (int) $post->ID,
			'title'             => get_the_title( $post ),
			'style'             => get_post_meta( $post_id, '_nvoos_comic_style', true ),
			'reading_direction' => get_post_meta( $post_id, '_nvoos_comic_reading_direction', true ),
			'page_layout'       => get_post_meta( $post_id, '_nvoos_comic_page_layout', true ),
			'series_name'       => get_post_meta( $post_id, '_nvoos_comic_series_name', true ),
			'issue_number'      => get_post_meta( $post_id, '_nvoos_comic_issue_number', true ),
			'date'              => get_the_date( 'c', $post ),
			'modified'          => get_the_modified_date( 'c', $post ),
		);
	}

	/**
	 * Format a media attachment post into a comic item response.
	 *
	 * @param WP_Post $post Attachment post object.
	 * @return array
	 */
	private static function format_comic_item( $post ) {
		$file_path = get_attached_file( $post->ID );
		$file_size = $file_path && file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;
		$file_url  = wp_get_attachment_url( $post->ID );
		$ext       = strtolower( pathinfo( $post->guid, PATHINFO_EXTENSION ) );

		return array(
			'id'            => (int) $post->ID,
			'title'         => get_the_title( $post ),
			'filename'      => basename( get_attached_file( $post->ID ) ?: '' ),
			'format'        => strtoupper( $ext ),
			'file_size'     => $file_size,
			'file_url'      => $file_url ?: '',
			'file_endpoint' => rest_url( self::REST_NAMESPACE . '/comics/' . $post->ID . '/file' ),
			'cover_url'     => '',
			'date'          => get_the_date( 'c', $post ),
			'modified'      => get_the_modified_date( 'c', $post ),
			'mime_type'     => $post->post_mime_type,
		);
	}

	/**
	 * Build a regex pattern matching comic file extensions in attachment paths.
	 *
	 * @return string
	 */
	private static function get_mime_regex() {
		return '\.(' . implode( '|', self::COMIC_EXTENSIONS ) . ')$';
	}

	/**
	 * Get the appropriate MIME type for a comic file extension.
	 *
	 * @param string $ext File extension.
	 * @return string
	 */
	private static function get_mime_type( $ext ) {
		switch ( $ext ) {
			case 'cbr':
				return 'application/vnd.rar';
			case 'cbz':
				return 'application/zip';
			case 'cb7':
				return 'application/x-7z-compressed';
			case 'cbt':
				return 'application/x-tar';
			default:
				return 'application/octet-stream';
		}
	}
}
