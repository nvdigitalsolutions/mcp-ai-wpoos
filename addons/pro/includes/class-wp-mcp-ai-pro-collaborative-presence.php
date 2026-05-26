<?php
/**
 * Pro Collaborative Presence — Real-time user presence tracking for
 * collaborative AI-assisted content authoring.
 *
 * Uses WordPress Heartbeat API to track which users are editing which
 * posts/pages, enabling awareness of concurrent editors and their
 * active AI assistant threads.
 *
 * @package NV_oOS_Pro
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Collaborative_Presence
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Pro_Collaborative_Presence {

	/**
	 * Option key for presence data.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_collaborative_presence';

	/**
	 * Presence TTL in seconds (users who haven't pinged in this time are considered offline).
	 *
	 * @since 1.7.0
	 * @var int
	 */
	const PRESENCE_TTL = 30;

	/**
	 * Initialize hooks.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'heartbeat_received', array( __CLASS__, 'handle_heartbeat' ), 10, 3 );
		add_filter( 'heartbeat_nopriv_received', array( __CLASS__, 'handle_heartbeat' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes for collaborative features.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'mcp-ai-pro/v1',
			'/collaboration/presence',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_presence' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			'mcp-ai-pro/v1',
			'/collaboration/presence',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_presence' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'post_id'   => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'thread_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'activity'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Handle WordPress heartbeat tick.
	 *
	 * Updates the current user's presence and returns presence data
	 * for other users editing the same post.
	 *
	 * @since 1.7.0
	 *
	 * @param array  $response  Heartbeat response data.
	 * @param array  $data      Data received from the client.
	 * @param string $screen_id The current admin screen ID.
	 * @return array
	 */
	public static function handle_heartbeat( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $screen_id is required by the WordPress heartbeat filter signature.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}

		// Get client data.
		$post_id   = isset( $data['nvoos_post_id'] ) ? absint( $data['nvoos_post_id'] ) : 0;
		$thread_id = isset( $data['nvoos_thread_id'] ) ? absint( $data['nvoos_thread_id'] ) : 0;

		if ( $post_id > 0 ) {
			self::set_user_presence( $user_id, $post_id, $thread_id );
		}

		// Return presence data for the current post.
		if ( isset( $data['nvoos_get_presence'] ) && $data['nvoos_get_presence'] ) {
			$response['nvoos_presence'] = self::get_post_presence( $post_id );
		}

		return $response;
	}

	/**
	 * Get all active presences for a post (REST endpoint).
	 *
	 * GET /mcp-ai-pro/v1/collaboration/presence
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_presence( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'presence' => self::get_post_presence( $post_id ),
				),
			)
		);
	}

	/**
	 * Update the current user's presence (REST endpoint).
	 *
	 * POST /mcp-ai-pro/v1/collaboration/presence
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function update_presence( $request ) {
		$user_id   = get_current_user_id();
		$post_id   = absint( $request->get_param( 'post_id' ) );
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		$activity  = sanitize_text_field( $request->get_param( 'activity' ) );

		if ( $post_id > 0 ) {
			self::set_user_presence( $user_id, $post_id, $thread_id, $activity );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'presence' => self::get_post_presence( $post_id ),
				),
			)
		);
	}

	/**
	 * Record a user's presence on a post.
	 *
	 * @since 1.7.0
	 *
	 * @param int    $user_id   WordPress user ID.
	 * @param int    $post_id   Post being edited.
	 * @param int    $thread_id Active AI thread ID (0 = none).
	 * @param string $activity  Optional activity description.
	 * @return void
	 */
	private static function set_user_presence( $user_id, $post_id, $thread_id = 0, $activity = '' ) {
		$presence = get_option( self::OPTION_KEY, array() );
		$now      = time();

		// Initialize post presence array if needed.
		if ( ! isset( $presence[ $post_id ] ) ) {
			$presence[ $post_id ] = array();
		}

		$user = get_userdata( $user_id );

		$presence[ $post_id ][ $user_id ] = array(
			'user_id'      => $user_id,
			'display_name' => $user ? $user->display_name : sprintf( 'User #%d', $user_id ),
			'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 32 ) ),
			'thread_id'    => $thread_id,
			'activity'     => $activity ? $activity : 'editing',
			'last_seen'    => $now,
		);

		// Clean up expired entries.
		$presence[ $post_id ] = array_filter(
			$presence[ $post_id ],
			function ( $entry ) use ( $now ) {
				return ( $now - $entry['last_seen'] ) <= self::PRESENCE_TTL;
			}
		);

		// Remove empty post entries.
		if ( empty( $presence[ $post_id ] ) ) {
			unset( $presence[ $post_id ] );
		}

		update_option( self::OPTION_KEY, $presence, false );
	}

	/**
	 * Get all active users on a post.
	 *
	 * @since 1.7.0
	 *
	 * @param int $post_id Post ID (0 = all posts).
	 * @return array       Array of user presence entries.
	 */
	private static function get_post_presence( $post_id = 0 ) {
		$presence = get_option( self::OPTION_KEY, array() );
		$now      = time();
		$result   = array();

		if ( $post_id > 0 && isset( $presence[ $post_id ] ) ) {
			// Filter out expired.
			foreach ( $presence[ $post_id ] as $user_id => $entry ) {
				if ( ( $now - $entry['last_seen'] ) <= self::PRESENCE_TTL ) {
					$result[] = $entry;
				}
			}
		} elseif ( 0 === $post_id ) {
			// Return all active presences across all posts.
			foreach ( $presence as $pid => $entries ) {
				foreach ( $entries as $user_id => $entry ) {
					if ( ( $now - $entry['last_seen'] ) <= self::PRESENCE_TTL ) {
						$entry['post_id'] = $pid;
						$result[]         = $entry;
					}
				}
			}
		}

		return $result;
	}
}
