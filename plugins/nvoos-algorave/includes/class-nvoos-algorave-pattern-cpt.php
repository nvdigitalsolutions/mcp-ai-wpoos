<?php
/**
 * NV oOS Algorave — Pattern Custom Post Type
 *
 * Registers the `algorave_pattern` CPT for storing live coding
 * patterns created by users or AI assistants.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern CPT handler.
 *
 * @since 1.0.0
 */
class NV_oOS_Algorave_Pattern_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'algorave_pattern';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the pattern CPT.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'Patterns', 'post type general name', 'nvoos-algorave' ),
			'singular_name'      => _x( 'Pattern', 'post type singular name', 'nvoos-algorave' ),
			'menu_name'          => _x( 'Algorave Patterns', 'admin menu', 'nvoos-algorave' ),
			'add_new'            => __( 'Add New Pattern', 'nvoos-algorave' ),
			'add_new_item'       => __( 'Add New Pattern', 'nvoos-algorave' ),
			'edit_item'          => __( 'Edit Pattern', 'nvoos-algorave' ),
			'new_item'           => __( 'New Pattern', 'nvoos-algorave' ),
			'view_item'          => __( 'View Pattern', 'nvoos-algorave' ),
			'search_items'       => __( 'Search Patterns', 'nvoos-algorave' ),
			'not_found'          => __( 'No patterns found.', 'nvoos-algorave' ),
			'not_found_in_trash' => __( 'No patterns found in Trash.', 'nvoos-algorave' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'algorave-pattern' ),
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-format-audio',
			'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields' ),
			'taxonomies'         => array( 'algorave_genre' ),
		);

		register_post_type( self::POST_TYPE, $args );

		// Register genre taxonomy.
		register_taxonomy(
			'algorave_genre',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => _x( 'Genres', 'taxonomy general name', 'nvoos-algorave' ),
					'singular_name' => _x( 'Genre', 'taxonomy singular name', 'nvoos-algorave' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'algorave-genre' ),
				'show_admin_column' => true,
			)
		);
	}

	/**
	 * Save a pattern from tool execution.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Pattern data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function save_pattern( $data ) {
		$post_data = array(
			'post_title'   => sanitize_text_field( $data['name'] ?? __( 'Untitled Pattern', 'nvoos-algorave' ) ),
			'post_content' => wp_kses_post( $data['description'] ?? '' ),
			'post_status'  => 'publish',
			'post_type'    => self::POST_TYPE,
		);

		if ( ! empty( $data['pattern_id'] ) ) {
			$post_data['ID'] = absint( $data['pattern_id'] );
			$post_id         = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store pattern code and metadata.
		if ( ! empty( $data['code'] ) ) {
			update_post_meta( $post_id, '_algorave_code', sanitize_textarea_field( $data['code'] ) );
		}
		if ( ! empty( $data['engine'] ) ) {
			update_post_meta( $post_id, '_algorave_engine', sanitize_text_field( $data['engine'] ) );
		}
		if ( ! empty( $data['bpm'] ) ) {
			update_post_meta( $post_id, '_algorave_bpm', absint( $data['bpm'] ) );
		}
		if ( ! empty( $data['scale'] ) ) {
			update_post_meta( $post_id, '_algorave_scale', sanitize_text_field( $data['scale'] ) );
		}
		if ( ! empty( $data['genre'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $data['genre'] ), 'algorave_genre' );
		}

		update_post_meta( $post_id, '_algorave_updated', current_time( 'mysql' ) );

		return $post_id;
	}

	/**
	 * Get pattern data by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Pattern post ID.
	 * @return array|null Pattern data or null if not found.
	 */
	public static function get_pattern( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$genres = wp_get_object_terms( $post->ID, 'algorave_genre', array( 'fields' => 'names' ) );

		$engine  = get_post_meta( $post->ID, '_algorave_engine', true );
		$bpm_raw = (int) get_post_meta( $post->ID, '_algorave_bpm', true );
		$scale   = get_post_meta( $post->ID, '_algorave_scale', true );

		return array(
			'id'          => $post->ID,
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'code'        => get_post_meta( $post->ID, '_algorave_code', true ),
			'engine'      => $engine ? $engine : 'strudel',
			'bpm'         => $bpm_raw ? $bpm_raw : 120,
			'scale'       => $scale ? $scale : 'C minor',
			'genre'       => is_array( $genres ) ? implode( ', ', $genres ) : '',
			'author'      => get_the_author_meta( 'display_name', $post->post_author ),
			'created'     => $post->post_date,
			'updated'     => get_post_meta( $post->ID, '_algorave_updated', true ),
		);
	}
}

// Initialize.
NV_oOS_Algorave_Pattern_CPT::init();
