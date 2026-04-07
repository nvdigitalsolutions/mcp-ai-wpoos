<?php
/**
 * NV oOS Algorave — Session Custom Post Type
 *
 * Registers the `algorave_session` CPT for tracking live coding sessions,
 * including collaborative and performance sessions.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session CPT handler.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Session_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'algorave_session';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the session CPT.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Sessions', 'post type general name', 'nvoos-algorave' ),
			'singular_name'      => _x( 'Session', 'post type singular name', 'nvoos-algorave' ),
			'menu_name'          => _x( 'Algorave Sessions', 'admin menu', 'nvoos-algorave' ),
			'add_new'            => __( 'New Session', 'nvoos-algorave' ),
			'add_new_item'       => __( 'Start New Session', 'nvoos-algorave' ),
			'edit_item'          => __( 'Edit Session', 'nvoos-algorave' ),
			'view_item'          => __( 'View Session', 'nvoos-algorave' ),
			'search_items'       => __( 'Search Sessions', 'nvoos-algorave' ),
			'not_found'          => __( 'No sessions found.', 'nvoos-algorave' ),
			'not_found_in_trash' => __( 'No sessions found in Trash.', 'nvoos-algorave' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=' . NV_oOS_Algorave_Pattern_CPT::POST_TYPE,
			'show_in_rest'       => true,
			'query_var'          => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'author', 'custom-fields' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Create a new session.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Session data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_session( $data ) {
		$title = ! empty( $data['name'] )
			? sanitize_text_field( $data['name'] )
			/* translators: %s: date/time string */
			: sprintf( __( 'Session %s', 'nvoos-algorave' ), current_time( 'Y-m-d H:i' ) );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => self::POST_TYPE,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_algorave_session_status', 'active' );
		update_post_meta( $post_id, '_algorave_session_started', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_algorave_session_bpm', absint( $data['bpm'] ?? 120 ) );
		update_post_meta( $post_id, '_algorave_session_patterns', array() );

		return $post_id;
	}

	/**
	 * End an active session.
	 *
	 * @since 1.0.0
	 *
	 * @param int $session_id Session post ID.
	 * @return bool True on success.
	 */
	public static function end_session( $session_id ) {
		$session_id = absint( $session_id );
		$post       = get_post( $session_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		update_post_meta( $session_id, '_algorave_session_status', 'ended' );
		update_post_meta( $session_id, '_algorave_session_ended', current_time( 'mysql' ) );

		return true;
	}
}

// Initialize.
NV_oOS_Algorave_Session_CPT::init();
