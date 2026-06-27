<?php
/**
 * NV oOS Extended Cognition — Sensor Session CPT
 *
 * Registers the `ext_cog_session` CPT for tracking active sensor sessions,
 * storing pending sensor requests, and persisting captured sensory snapshots.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sensor session CPT handler.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Ext_Cog_Sensor_Session {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_cog_session';

	/**
	 * Meta key for pending sensor requests queue.
	 *
	 * @var string
	 */
	const META_QUEUE = '_ext_cog_sensor_queue';

	/**
	 * Meta key for captured sensor data.
	 *
	 * @var string
	 */
	const META_DATA = '_ext_cog_sensor_data';

	/**
	 * Meta key for rate-limit counters.
	 *
	 * @var string
	 */
	const META_RATE = '_ext_cog_rate_counters';

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the sensor session CPT.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Sensor Sessions', 'post type general name', 'mcp-ai-wpoos' ),
			'singular_name'      => _x( 'Sensor Session', 'post type singular name', 'mcp-ai-wpoos' ),
			'menu_name'          => _x( 'Sensor Sessions', 'admin menu', 'mcp-ai-wpoos' ),
			'add_new'            => __( 'New Session', 'mcp-ai-wpoos' ),
			'add_new_item'       => __( 'Start New Session', 'mcp-ai-wpoos' ),
			'edit_item'          => __( 'Edit Session', 'mcp-ai-wpoos' ),
			'view_item'          => __( 'View Session', 'mcp-ai-wpoos' ),
			'search_items'       => __( 'Search Sessions', 'mcp-ai-wpoos' ),
			'not_found'          => __( 'No sessions found.', 'mcp-ai-wpoos' ),
			'not_found_in_trash' => __( 'No sessions found in Trash.', 'mcp-ai-wpoos' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'options-general.php',
			'show_in_rest'       => false,
			'query_var'          => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'author', 'custom-fields' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Get or create a session post for the given session ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id Unique session identifier.
	 * @param int    $user_id    WordPress user ID (0 for guest).
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function get_or_create( $session_id, $user_id = 0 ) {
		$session_id = sanitize_text_field( $session_id );
		// Use post_name (indexed slug) for fast session lookup — avoids slow meta_key query.
		$slug = sanitize_title( $session_id );

		// Look for existing post by post_name (indexed).
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'name'           => $slug,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $existing ) ) {
			return $existing[0];
		}

		// Create a new session post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $session_id,
				'post_name'   => $slug,
				'post_author' => absint( $user_id ),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_ext_cog_session_id', $session_id );
		update_post_meta( $post_id, '_ext_cog_created', time() );

		return $post_id;
	}

	/**
	 * Push a sensor request onto the session queue.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Session post ID.
	 * @param array $request Sensor request data.
	 * @return void
	 */
	public static function push_request( $post_id, array $request ) {
		$queue   = get_post_meta( $post_id, self::META_QUEUE, true );
		$queue   = is_array( $queue ) ? $queue : array();
		$queue[] = array_merge(
			$request,
			array(
				'request_id' => wp_generate_uuid4(),
				'queued_at'  => time(),
			)
		);
		update_post_meta( $post_id, self::META_QUEUE, $queue );
	}

	/**
	 * Pop and return all pending sensor requests, clearing the queue.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Session post ID.
	 * @return array Array of pending sensor requests.
	 */
	public static function pop_requests( $post_id ) {
		$queue = get_post_meta( $post_id, self::META_QUEUE, true );
		$queue = is_array( $queue ) ? $queue : array();
		update_post_meta( $post_id, self::META_QUEUE, array() );
		return $queue;
	}

	/**
	 * Store captured sensor data for a request.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id    Session post ID.
	 * @param string $request_id Unique request identifier.
	 * @param array  $data       Captured sensor data.
	 * @return void
	 */
	public static function store_data( $post_id, $request_id, array $data ) {
		$all                = get_post_meta( $post_id, self::META_DATA, true );
		$all                = is_array( $all ) ? $all : array();
		$all[ $request_id ] = array_merge(
			$data,
			array( 'captured_at' => time() )
		);
		update_post_meta( $post_id, self::META_DATA, $all );
	}

	/**
	 * Retrieve and remove captured data for a request.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id    Session post ID.
	 * @param string $request_id Unique request identifier.
	 * @return array|null Captured data or null if not yet available.
	 */
	public static function consume_data( $post_id, $request_id ) {
		$all = get_post_meta( $post_id, self::META_DATA, true );
		$all = is_array( $all ) ? $all : array();

		if ( ! isset( $all[ $request_id ] ) ) {
			return null;
		}

		$data = $all[ $request_id ];
		unset( $all[ $request_id ] );
		update_post_meta( $post_id, self::META_DATA, $all );

		return $data;
	}

	/**
	 * Check and increment rate limit counter for a session+sensor.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id    Session post ID.
	 * @param string $sensor     Sensor type (camera, audio, screen, motion).
	 * @param int    $max_per_min Maximum captures per minute.
	 * @return bool True if within rate limit, false if exceeded.
	 */
	public static function check_rate_limit( $post_id, $sensor, $max_per_min = 10 ) {
		$counters = get_post_meta( $post_id, self::META_RATE, true );
		$counters = is_array( $counters ) ? $counters : array();

		$now    = time();
		$key    = sanitize_key( $sensor );
		$window = isset( $counters[ $key ] ) ? $counters[ $key ] : array(
			'count'        => 0,
			'window_start' => $now,
		);

		// Reset window if a minute has passed.
		if ( ( $now - $window['window_start'] ) >= 60 ) {
			$window = array(
				'count'        => 0,
				'window_start' => $now,
			);
		}

		if ( $window['count'] >= $max_per_min ) {
			return false;
		}

		++$window['count'];
		$counters[ $key ] = $window;
		update_post_meta( $post_id, self::META_RATE, $counters );

		return true;
	}
}

WP_MCP_AI_Ext_Cog_Sensor_Session::init();
