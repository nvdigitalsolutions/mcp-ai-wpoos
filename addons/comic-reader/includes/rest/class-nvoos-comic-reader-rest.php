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
				'permission_callback' => '__return_true',
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
				'permission_callback' => '__return_true',
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
				'permission_callback' => '__return_true',
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
				'permission_callback' => '__return_true',
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
	}

	/**
	 * Health check endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_COMIC_READER_VERSION' ) ? NVOOS_COMIC_READER_VERSION : 'unknown',
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

		$query   = new WP_Query( $args );
		$comics  = array();

		foreach ( $query->posts as $post ) {
			$comics[] = self::format_comic_item( $post );
		}

		return rest_ensure_response(
			array(
				'comics'     => $comics,
				'total'      => (int) $query->found_posts,
				'page'       => $page,
				'per_page'   => $per_page,
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
		$id      = $request->get_param( 'id' );
		$post    = get_post( $id );

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
		$content  = file_get_contents( $file_path );
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
						'id'       => (int) $cover_id,
						'url'      => $cover_url,
						'cached'   => true,
					)
				);
			}
		}

		// No cached cover — client will extract from archive.
		return rest_ensure_response(
			array(
				'id'       => $id,
				'url'      => '',
				'cached'   => false,
				'extract'  => rest_url( self::REST_NAMESPACE . '/comics/' . $id . '/file' ),
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
			'id'          => (int) $post->ID,
			'title'       => get_the_title( $post ),
			'filename'    => basename( get_attached_file( $post->ID ) ?: '' ),
			'format'      => strtoupper( $ext ),
			'file_size'   => $file_size,
			'file_url'    => $file_url ?: '',
			'file_endpoint' => rest_url( self::REST_NAMESPACE . '/comics/' . $post->ID . '/file' ),
			'cover_url'   => '',
			'date'        => get_the_date( 'c', $post ),
			'modified'    => get_the_modified_date( 'c', $post ),
			'mime_type'   => $post->post_mime_type,
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
