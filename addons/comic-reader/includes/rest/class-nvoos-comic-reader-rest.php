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
			'/comics/(?P<id>\d+)/cover/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate_cover' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
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

		// ─── Progress & Metadata Routes ───────────────────────────

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_progress_map' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)/progress',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_comic_progress' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_comic_progress' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
					'args'                => array(
						'id'        => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'page'      => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'total'     => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'completed' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)/metadata',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_comic_metadata' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_comic_metadata' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comics/(?P<id>\d+)',
			array(
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
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_comic' ),
					'permission_callback' => array( __CLASS__, 'edit_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/series',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_series' ),
				'permission_callback' => array( __CLASS__, 'read_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/collections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_collections' ),
					'permission_callback' => array( __CLASS__, 'read_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_collection' ),
					'permission_callback' => array( __CLASS__, 'collections_edit_permission' ),
					'args'                => array(
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/collections/(?P<id>\d+)/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_collection_item' ),
				'permission_callback' => array( __CLASS__, 'collections_edit_permission' ),
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'comic_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/collections/(?P<id>\d+)/items/(?P<comic_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'remove_collection_item' ),
				'permission_callback' => array( __CLASS__, 'collections_edit_permission' ),
				'args'                => array(
					'id'       => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'comic_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
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
						'series'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'orderby'  => array(
							'type'    => 'string',
							'enum'    => array( 'date', 'title' ),
							'default' => 'date',
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
			'server_progress'   => NV_oOS_Comic_Reader_Settings::progress_sync_enabled(),
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
		$series   = $request->get_param( 'series' );

		$orderby_map = array(
			'date'  => 'date',
			'title' => 'title',
		);
		$orderby     = $request->get_param( 'orderby' );
		$orderby     = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'date';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- extension matching on _wp_attached_file is the only way to find comics in the Media Library.
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'value'   => self::get_mime_regex(),
					'compare' => 'REGEXP',
				),
			),
			'orderby'        => $orderby,
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		if ( ! empty( $series ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- series filtering is an explicit user choice.
				array(
					'taxonomy' => NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $series ),
				),
			);
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

		if ( ! in_array( self::get_comic_ext( $id ), self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( self::format_comic_item( $post ) );
	}

	/**
	 * Get the current user's progress map for all comics.
	 *
	 * @param WP_REST_Request $_request Request object (unused).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_progress_map( $_request ) {
		$user_id = get_current_user_id();
		return rest_ensure_response(
			array(
				'progress' => self::get_user_progress( $user_id ),
			)
		);
	}

	/**
	 * Get the current user's progress for a single comic.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_comic_progress( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$progress = self::get_user_progress( get_current_user_id() );

		return rest_ensure_response(
			array(
				'progress' => isset( $progress[ $id ] ) ? $progress[ $id ] : null,
			)
		);
	}

	/**
	 * Record the current user's reading progress for a comic.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_comic_progress( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$user_id  = get_current_user_id();
		$progress = self::get_user_progress( $user_id );

		$progress[ $id ] = array(
			'page'      => (int) $request->get_param( 'page' ),
			'total'     => (int) $request->get_param( 'total' ),
			'completed' => (bool) $request->get_param( 'completed' ),
			'ts'        => time(),
		);

		self::set_user_progress( $user_id, $progress );

		return rest_ensure_response(
			array(
				'saved'    => true,
				'progress' => $progress[ $id ],
			)
		);
	}

	/**
	 * Get the stored (ComicInfo.xml-derived) metadata for a comic.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_comic_metadata( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$metadata = get_post_meta( $id, '_nvoos_comic_metadata', true );

		return rest_ensure_response(
			array(
				'metadata' => is_array( $metadata ) ? $metadata : array(),
			)
		);
	}

	/**
	 * Store ComicInfo.xml metadata parsed client-side from the archive.
	 *
	 * Mirrors Komga's embedded-metadata import: the parsed fields are
	 * persisted as attachment meta and the series is applied as a term so
	 * the library groups comics by series.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_comic_metadata( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( self::get_comic_ext( $id ), self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_payload', __( 'A JSON metadata payload is required.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$allowed_fields = array( 'title', 'series', 'number', 'volume', 'writer', 'penciller', 'publisher', 'genre', 'page_count', 'rtl', 'manga' );
		$metadata       = array();
		foreach ( $allowed_fields as $field ) {
			if ( isset( $payload[ $field ] ) ) {
				$metadata[ $field ] = sanitize_text_field( (string) $payload[ $field ] );
			}
		}

		update_post_meta( $id, '_nvoos_comic_metadata', $metadata );

		// Apply the series as a taxonomy term.
		if ( ! empty( $metadata['series'] ) ) {
			wp_set_object_terms( $id, $metadata['series'], NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY, false );
		}

		// Persist the reading-direction hint for auto-detection.
		if ( isset( $metadata['rtl'] ) && '' !== $metadata['rtl'] ) {
			update_post_meta( $id, '_nvoos_comic_reading_direction', 'Yes' === $metadata['rtl'] ? 'rtl' : 'ltr' );
		}

		return rest_ensure_response(
			array(
				'saved'    => true,
				'metadata' => $metadata,
			)
		);
	}

	/**
	 * Edit a comic's user-facing details (title, series, credits…).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_comic( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'invalid_payload', __( 'A JSON payload is required.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		if ( isset( $payload['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( (string) $payload['title'] ),
				)
			);
		}

		if ( isset( $payload['series'] ) ) {
			$series = sanitize_text_field( (string) $payload['series'] );
			if ( '' === $series ) {
				wp_set_object_terms( $id, array(), NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY, false );
			} else {
				wp_set_object_terms( $id, $series, NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY, false );
			}
		}

		// Scalar text fields stored both as dedicated meta and in the
		// combined metadata blob so both consumers see the same data.
		$scalar_fields = array( 'number', 'volume', 'writer', 'publisher', 'reading_direction' );
		$metadata      = get_post_meta( $id, '_nvoos_comic_metadata', true );
		$metadata      = is_array( $metadata ) ? $metadata : array();

		foreach ( $scalar_fields as $field ) {
			if ( ! isset( $payload[ $field ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $payload[ $field ] );
			update_post_meta( $id, '_nvoos_comic_' . $field, $value );
			$metadata[ $field ] = $value;
		}

		update_post_meta( $id, '_nvoos_comic_metadata', $metadata );

		return rest_ensure_response( self::format_comic_item( get_post( $id ) ) );
	}

	/**
	 * List series facets with comic counts.
	 *
	 * @param WP_REST_Request $_request Request object (unused).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_series( $_request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( array( 'series' => array() ) );
		}

		$series = array_map(
			function ( $term ) {
				return array(
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'count' => (int) $term->count,
				);
			},
			$terms
		);

		return rest_ensure_response( array( 'series' => $series ) );
	}

	/**
	 * List collections with their ordered comic IDs.
	 *
	 * @param WP_REST_Request $_request Request object (unused).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_collections( $_request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => NV_oOS_Comic_Reader_Taxonomy::COLLECTION_TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( array( 'collections' => array() ) );
		}

		$collections = array_map(
			function ( $term ) {
				return array(
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'items' => NV_oOS_Comic_Reader_Taxonomy::get_collection_items( $term->term_id ),
				);
			},
			$terms
		);

		return rest_ensure_response( array( 'collections' => $collections ) );
	}

	/**
	 * Create a new collection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_collection( $request ) {
		$name = $request->get_param( 'name' );
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'A collection name is required.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$term = wp_insert_term( $name, NV_oOS_Comic_Reader_Taxonomy::COLLECTION_TAXONOMY );
		if ( is_wp_error( $term ) ) {
			// Reuse an existing term with the same name.
			if ( isset( $term->error_data['term_exists'] ) ) {
				$term_id = (int) $term->error_data['term_exists'];
			} else {
				return new WP_Error( 'create_failed', $term->get_error_message(), array( 'status' => 400 ) );
			}
		} else {
			$term_id = (int) $term['term_id'];
		}

		// WP 6.9 removed the $status parameter from rest_ensure_response().
		return new WP_REST_Response(
			array(
				'id'    => $term_id,
				'name'  => $name,
				'items' => array(),
			),
			201
		);
	}

	/**
	 * Add a comic to a collection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_collection_item( $request ) {
		$term_id  = $request->get_param( 'id' );
		$comic_id = $request->get_param( 'comic_id' );

		if ( ! term_exists( $term_id, NV_oOS_Comic_Reader_Taxonomy::COLLECTION_TAXONOMY ) ) {
			return new WP_Error( 'not_found', __( 'Collection not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$post = get_post( $comic_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		NV_oOS_Comic_Reader_Taxonomy::add_collection_item( (int) $term_id, (int) $comic_id );

		return rest_ensure_response(
			array(
				'added' => true,
				'items' => NV_oOS_Comic_Reader_Taxonomy::get_collection_items( (int) $term_id ),
			)
		);
	}

	/**
	 * Remove a comic from a collection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_collection_item( $request ) {
		$term_id  = $request->get_param( 'id' );
		$comic_id = $request->get_param( 'comic_id' );

		if ( ! term_exists( $term_id, NV_oOS_Comic_Reader_Taxonomy::COLLECTION_TAXONOMY ) ) {
			return new WP_Error( 'not_found', __( 'Collection not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		NV_oOS_Comic_Reader_Taxonomy::remove_collection_item( (int) $term_id, (int) $comic_id );

		return rest_ensure_response(
			array(
				'removed' => true,
				'items'   => NV_oOS_Comic_Reader_Taxonomy::get_collection_items( (int) $term_id ),
			)
		);
	}

	/**
	 * Read the current user's stored progress map.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{page:int,total:int,completed:bool,ts:int}> Progress keyed by comic ID.
	 */
	private static function get_user_progress( $user_id ) {
		$progress = get_user_meta( (int) $user_id, 'nvoos_comic_reader_progress', true );
		return is_array( $progress ) ? $progress : array();
	}

	/**
	 * Persist the user's progress map, pruned to the most recent 500 comics.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $progress Progress keyed by comic ID.
	 * @return void
	 */
	private static function set_user_progress( $user_id, $progress ) {
		uasort(
			$progress,
			function ( $a, $b ) {
				return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
			}
		);
		$progress = array_slice( $progress, 0, 500, true );
		update_user_meta( (int) $user_id, 'nvoos_comic_reader_progress', $progress );
	}

	/**
	 * Get the raw comic file for client-side extraction.
	 *
	 * Serves the archive with HTTP Range support (206 Partial Content) so
	 * large files can be streamed/resumed, and reads the file in bounded
	 * chunks instead of loading it fully into memory.
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

		$file_size = (int) filesize( $file_path );

		// Parse and validate a single byte range (e.g. "bytes=0-1023").
		$range = self::parse_byte_range( $request->get_header( 'Range' ), $file_size );
		if ( false === $range ) {
			$response = new WP_REST_Response( '', 416 );
			$response->header( 'Content-Range', 'bytes */' . $file_size );
			return $response;
		}

		if ( null !== $range ) {
			list( $start, $end ) = $range;
			$length              = $end - $start + 1;
			$status              = 206;
			$content             = self::read_file_chunk( $file_path, $start, $length );
		} else {
			$status  = 200;
			$content = self::read_file_chunk( $file_path, 0, $file_size );
		}

		if ( false === $content ) {
			return new WP_Error( 'read_error', __( 'Failed to read comic file.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $content, $status );
		$response->header( 'Content-Type', self::get_mime_type( $ext ) );
		$response->header( 'Content-Length', (string) ( isset( $range ) && null !== $range ? $range[1] - $range[0] + 1 : $file_size ) );
		$response->header( 'Content-Disposition', 'inline; filename="' . basename( $file_path ) . '"' );
		$response->header( 'Accept-Ranges', 'bytes' );

		if ( 206 === $status ) {
			$response->header( 'Content-Range', 'bytes ' . $start . '-' . $end . '/' . $file_size );
		}

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
	 * Generate and cache a cover thumbnail for a comic (CBZ only, server-side).
	 *
	 * Extracts the first image page of a CBZ archive with ZipArchive,
	 * sideloads it into the Media Library as a child of the comic attachment,
	 * and stores the generated attachment ID in `_nvoos_comic_cover_id`.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate_cover( $request ) {
		$id   = $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Comic not found.', 'nvoos-comic-reader' ), array( 'status' => 404 ) );
		}

		$file_path = get_attached_file( $id );
		$ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$cover_id = self::extract_cover_attachment( $id );
		if ( is_wp_error( $cover_id ) ) {
			return $cover_id;
		}

		$cover_medium = wp_get_attachment_image_url( $cover_id, 'medium' );
		$cover_full   = wp_get_attachment_url( $cover_id );

		return rest_ensure_response(
			array(
				'id'       => $id,
				'cover_id' => $cover_id,
				'url'      => $cover_medium ? $cover_medium : $cover_full,
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

		if ( ! in_array( self::get_comic_ext( $id ), self::COMIC_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_format', __( 'File is not a supported comic format.', 'nvoos-comic-reader' ), array( 'status' => 400 ) );
		}

		$cover_id = get_post_meta( $id, '_nvoos_comic_cover_id', true );

		$result = wp_delete_attachment( $id, true );
		if ( false === $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete comic.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		// Remove the generated cover thumbnail alongside the comic.
		if ( $cover_id && get_post( (int) $cover_id ) ) {
			wp_delete_attachment( (int) $cover_id, true );
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

		// Enforce a configurable upload size cap (settings page + filter).
		$max_bytes = NV_oOS_Comic_Reader_Settings::get_max_upload_bytes();
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: human-readable maximum file size */
					__( 'Comic file exceeds the maximum allowed size of %s.', 'nvoos-comic-reader' ),
					size_format( $max_bytes )
				),
				array( 'status' => 413 )
			);
		}

		// Validate the archive signature (magic bytes) so a renamed text file
		// cannot pass as a comic archive.
		if ( ! empty( $file['tmp_name'] ) && ! NV_oOS_Comic_Reader_Mime::has_valid_archive_signature( $file['tmp_name'] ) ) {
			return new WP_Error(
				'invalid_archive',
				__( 'The uploaded file is not a valid comic archive. Only genuine CBR, CBZ, CB7 and CBT files are accepted.', 'nvoos-comic-reader' ),
				array( 'status' => 400 )
			);
		}

		// Use WordPress media upload handling by default. A filterable handler
		// seam lets integrations (off-site storage, custom sanitizers) replace
		// media_handle_upload entirely; it receives the raw file array and
		// must return an attachment ID or WP_Error.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload_handler = apply_filters( 'nvoos_comic_reader_upload_handler', null );
		$attachment_id  = is_callable( $upload_handler )
			? call_user_func( $upload_handler, $file )
			: media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'upload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Best-effort server-side cover extraction (CBZ only, when enabled).
		// Failures must never fail the upload — the client can extract covers instead.
		if ( NV_oOS_Comic_Reader_Settings::cover_extraction_enabled() ) {
			self::extract_cover_attachment( (int) $attachment_id );
		}

		$post = get_post( $attachment_id );

		// WP 6.9 removed the $status parameter from rest_ensure_response(),
		// so construct the 201 response explicitly.
		return new WP_REST_Response( self::format_comic_item( $post ), 201 );
	}

	/**
	 * Read permission — user must be logged in with the (filterable) read capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function read_permission() {
		$cap = NV_oOS_Comic_Reader_Settings::get_capability( 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( is_user_logged_in() && current_user_can( $cap ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You must be logged in to access comics.', 'nvoos-comic-reader' ), array( 'status' => 401 ) );
	}

	/**
	 * Upload permission — user must hold the (filterable) upload capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function upload_permission() {
		$cap = NV_oOS_Comic_Reader_Settings::get_capability( 'upload' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( current_user_can( $cap ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to upload files.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
	}

	/**
	 * Edit permission — the user must hold the (filterable) edit capability
	 * and pass the per-object check on the attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function edit_permission( $request ) {
		$cap = NV_oOS_Comic_Reader_Settings::get_capability( 'edit' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to edit comics.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
		}

		$id = (int) $request->get_param( 'id' );
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to edit this comic.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Collections edit permission — capability-only gate (the route ID is a
	 * taxonomy term, not a post, so no per-object check applies).
	 *
	 * @return bool|WP_Error
	 */
	public static function collections_edit_permission() {
		$cap = NV_oOS_Comic_Reader_Settings::get_capability( 'edit' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to manage collections.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Delete permission — scoped to the specific attachment.
	 *
	 * The broad capability is filterable; the per-object `delete_post` check
	 * ensures non-admin users can only delete their own comics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function delete_permission( $request ) {
		$cap = NV_oOS_Comic_Reader_Settings::get_capability( 'delete' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete files.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
		}

		$id = (int) $request->get_param( 'id' );
		if ( $id && ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this comic.', 'nvoos-comic-reader' ), array( 'status' => 403 ) );
		}

		return true;
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

		$series_names = wp_get_object_terms(
			$post->ID,
			NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY,
			array( 'fields' => 'names' )
		);

		$metadata = get_post_meta( $post->ID, '_nvoos_comic_metadata', true );

		$progress = null;
		$user_id  = get_current_user_id();
		if ( $user_id ) {
			$user_progress = self::get_user_progress( $user_id );
			if ( isset( $user_progress[ $post->ID ] ) ) {
				$progress = $user_progress[ $post->ID ];
			}
		}

		$direction = get_post_meta( $post->ID, '_nvoos_comic_reading_direction', true );

		return array(
			'id'                => (int) $post->ID,
			'title'             => get_the_title( $post ),
			'filename'          => basename( $file_path ? $file_path : '' ),
			'format'            => strtoupper( self::get_comic_ext( $post->ID ) ),
			'file_size'         => $file_size,
			'file_url'          => $file_url ? $file_url : '',
			'file_endpoint'     => rest_url( self::REST_NAMESPACE . '/comics/' . $post->ID . '/file' ),
			'cover_url'         => self::get_cover_url( $post->ID ),
			'date'              => get_the_date( 'c', $post ),
			'modified'          => get_the_modified_date( 'c', $post ),
			'mime_type'         => $post->post_mime_type,
			'series'            => is_array( $series_names ) ? array_values( $series_names ) : array(),
			'metadata'          => is_array( $metadata ) ? $metadata : array(),
			'progress'          => $progress,
			'reading_direction' => 'rtl' === $direction ? 'rtl' : 'ltr',
		);
	}

	/**
	 * Get the lowercase file extension of a comic attachment from its real
	 * path on disk. The post GUID is not reliable — it can be a permalink.
	 *
	 * @param int $post_id Attachment ID.
	 * @return string Lowercase extension (may be empty).
	 */
	private static function get_comic_ext( $post_id ) {
		$file_path = get_attached_file( $post_id );
		$file_path = $file_path ? $file_path : '';
		return strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get the cached cover URL for a comic attachment, if one was generated.
	 *
	 * @param int $attachment_id Comic attachment ID.
	 * @return string Cover URL or empty string.
	 */
	private static function get_cover_url( $attachment_id ) {
		$cover_id = get_post_meta( $attachment_id, '_nvoos_comic_cover_id', true );
		if ( ! $cover_id ) {
			return '';
		}

		// Prefer the medium thumbnail; fall back to the full image for
		// covers too small to have generated intermediate sizes.
		$url = wp_get_attachment_image_url( (int) $cover_id, 'medium' );
		if ( $url ) {
			return $url;
		}

		$full = wp_get_attachment_url( (int) $cover_id );
		return $full ? $full : '';
	}

	/**
	 * Extract the first image page of a CBZ archive into a cached cover
	 * attachment (child of the comic).
	 *
	 * @param int $attachment_id Comic attachment ID.
	 * @return int|WP_Error Generated cover attachment ID, or WP_Error.
	 */
	private static function extract_cover_attachment( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, '_nvoos_comic_cover_id', true );
		if ( $existing && get_post( (int) $existing ) ) {
			return (int) $existing;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'unsupported', __( 'Server-side cover extraction requires the ZipArchive PHP extension.', 'nvoos-comic-reader' ), array( 'status' => 501 ) );
		}

		$file_path = get_attached_file( $attachment_id );
		$ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'cbz' !== $ext ) {
			return new WP_Error( 'unsupported_format', __( 'Server-side cover extraction currently supports CBZ archives only.', 'nvoos-comic-reader' ), array( 'status' => 501 ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'extract_failed', __( 'Failed to open the comic archive.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		// Collect image entries, sorted naturally (cover = first page).
		$entries = array();
		$count   = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( is_string( $name ) && preg_match( '/\.(jpe?g|png|gif|webp|bmp)$/i', $name ) ) {
				$entries[] = $name;
			}
		}
		sort( $entries, SORT_NATURAL | SORT_FLAG_CASE );

		if ( empty( $entries ) ) {
			$zip->close();
			return new WP_Error( 'no_pages', __( 'No image pages were found inside the archive.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		$cover_name = $entries[0];
		$stream     = $zip->getStream( $cover_name );
		if ( false === $stream ) {
			$zip->close();
			return new WP_Error( 'extract_failed', __( 'Failed to read the cover page from the archive.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$cover_bytes = stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();

		if ( false === $cover_bytes || '' === $cover_bytes ) {
			return new WP_Error( 'extract_failed', __( 'Failed to read the cover page from the archive.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		// Write to a temp file so media_handle_sideload can move it.
		$tmp = wp_tempnam( 'nvoos-comic-cover-' );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_file_failed', __( 'Could not create a temporary file for the cover.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $cover_bytes ) ) {
			return new WP_Error( 'temp_file_failed', __( 'Could not write the cover to a temporary file.', 'nvoos-comic-reader' ), array( 'status' => 500 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file_array = array(
			'name'     => basename( $cover_name ),
			'tmp_name' => $tmp,
		);

		$cover_id = media_handle_sideload( $file_array, $attachment_id );
		if ( is_wp_error( $cover_id ) ) {
			return $cover_id;
		}

		update_post_meta( $attachment_id, '_nvoos_comic_cover_id', (int) $cover_id );

		return (int) $cover_id;
	}

	/**
	 * Parse a single HTTP byte range header against a file size.
	 *
	 * @param string|null $header    Raw Range header value (e.g. "bytes=0-1023").
	 * @param int         $file_size Total file size in bytes.
	 * @return array{int,int}|false|null [start, end], false when unsatisfiable, null when absent.
	 */
	private static function parse_byte_range( $header, $file_size ) {
		if ( ! is_string( $header ) || ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $matches ) ) {
			return null;
		}

		$start = '' === $matches[1] ? null : (int) $matches[1];
		$end   = '' === $matches[2] ? null : (int) $matches[2];

		if ( null === $start && null !== $end ) {
			// Suffix range: the final N bytes.
			$start = max( 0, $file_size - $end );
			$end   = $file_size - 1;
		} elseif ( null === $end ) {
			$end = $file_size - 1;
		}

		if ( $start < 0 || $start >= $file_size || $end < $start ) {
			return false;
		}

		return array( $start, min( $end, $file_size - 1 ) );
	}

	/**
	 * Read a bounded byte range from a file without loading it fully in memory.
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $offset Start offset in bytes.
	 * @param int    $length Number of bytes to read.
	 * @return string|false File contents, or false on failure.
	 */
	private static function read_file_chunk( $path, $offset, $length ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
		}

		$remaining = $length;
		$chunk     = '';
		while ( $remaining > 0 && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$read = fread( $handle, min( 1048576, $remaining ) );
			if ( false === $read || '' === $read ) {
				break;
			}
			$chunk    .= $read;
			$remaining = $remaining - strlen( $read );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $chunk;
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
