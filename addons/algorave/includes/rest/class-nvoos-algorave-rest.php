<?php
/**
 * NV oOS Algorave — REST API Controller
 *
 * Provides REST endpoints for pattern CRUD, session management,
 * and sample browsing.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the Algorave addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_REST {

	/**
	 * Namespace for all endpoints.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-algorave/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Patterns.
		register_rest_route(
			self::NAMESPACE,
			'/patterns',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_patterns' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 12,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'genre'    => array(
							'type' => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_pattern' ),
					'permission_callback' => array( __CLASS__, 'check_write_permission' ),
					'args'                => array(
						'name'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'code'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'engine' => array(
							'type'    => 'string',
							'default' => 'strudel',
							'enum'    => array( 'strudel', 'tonejs' ),
						),
						'bpm'    => array(
							'type'    => 'integer',
							'default' => 120,
							'minimum' => 20,
							'maximum' => 300,
						),
						'scale'  => array(
							'type'    => 'string',
							'default' => 'C minor',
						),
						'genre'  => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		// Single pattern.
		register_rest_route(
			self::NAMESPACE,
			'/patterns/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_pattern' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		// Samples.
		register_rest_route(
			self::NAMESPACE,
			'/samples',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_samples' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
						),
						'category' => array(
							'type' => 'string',
						),
						'search'   => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Check read permission.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function check_read_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WordPress REST API.
		$settings = NV_oOS_Algorave::get_settings();
		if ( ! empty( $settings['guest_access'] ) ) {
			return true;
		}
		return current_user_can( 'read' );
	}

	/**
	 * Check write permission.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function check_write_permission( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WordPress REST API.
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get patterns list.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_patterns( $request ) {
		$args = array(
			'post_type'      => NV_oOS_Algorave_Pattern_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$genre = $request->get_param( 'genre' );
		if ( ! empty( $genre ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'algorave_genre',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $genre ),
				),
			);
		}

		$query    = new WP_Query( $args );
		$patterns = array();

		foreach ( $query->posts as $post ) {
			$pattern = NV_oOS_Algorave_Pattern_CPT::get_pattern( $post->ID );
			if ( $pattern ) {
				$patterns[] = $pattern;
			}
		}

		return rest_ensure_response(
			array(
				'patterns' => $patterns,
				'total'    => $query->found_posts,
				'pages'    => $query->max_num_pages,
			)
		);
	}

	/**
	 * Get a single pattern.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_pattern( $request ) {
		$pattern = NV_oOS_Algorave_Pattern_CPT::get_pattern( $request->get_param( 'id' ) );

		if ( ! $pattern ) {
			return new WP_Error(
				'not_found',
				__( 'Pattern not found.', 'nvoos-algorave' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $pattern );
	}

	/**
	 * Create a new pattern.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_pattern( $request ) {
		$post_id = NV_oOS_Algorave_Pattern_CPT::save_pattern(
			array(
				'name'        => $request->get_param( 'name' ),
				'code'        => $request->get_param( 'code' ),
				'engine'      => $request->get_param( 'engine' ),
				'bpm'         => $request->get_param( 'bpm' ),
				'scale'       => $request->get_param( 'scale' ),
				'genre'       => $request->get_param( 'genre' ),
				'description' => $request->get_param( 'description' ),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$pattern = NV_oOS_Algorave_Pattern_CPT::get_pattern( $post_id );

		return rest_ensure_response( $pattern );
	}

	/**
	 * Get audio samples.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_samples( $request ) {
		$result = NV_oOS_Algorave_Sample_Library::browse(
			array(
				'posts_per_page' => $request->get_param( 'per_page' ),
				'category'       => $request->get_param( 'category' ),
				'search'         => $request->get_param( 'search' ),
			)
		);

		return rest_ensure_response( $result );
	}
}

// Initialize.
NV_oOS_Algorave_REST::init();
