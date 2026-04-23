<?php
/**
 * NV oOS Graphify — Content Detector
 *
 * Inventories published WordPress content and returns a list of items
 * that need to be (re-)indexed. Supports incremental detection by
 * comparing post_modified against the last-indexed timestamp.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects WordPress content that needs to be included in the knowledge graph.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Detector {

	/**
	 * Collect all content items that should be represented as nodes.
	 *
	 * @since 0.5.0
	 *
	 * @param bool   $incremental When true, only return items newer than last build.
	 * @param string $since       ISO-8601 datetime string (overrides incremental flag).
	 * @return array {
	 *     @type array $posts  Published posts (all public post types).
	 *     @type array $terms  Taxonomy terms with at least one published post.
	 *     @type array $users  Authors with published content.
	 *     @type array $media  Featured images referenced by posts in scope.
	 * }
	 */
	public static function detect( $incremental = false, $since = '' ) {
		if ( $incremental && ! $since ) {
			$since = NV_oOS_Graphify_DB::get_meta( 'last_build_completed', '' );
		}

		$posts = self::detect_posts( $since );
		$terms = self::detect_terms( $posts );
		$users = self::detect_users( $posts );
		$media = self::detect_media( $posts );

		return compact( 'posts', 'terms', 'users', 'media' );
	}

	// -------------------------------------------------------------------------
	// Post detection
	// -------------------------------------------------------------------------

	/**
	 * Return published posts across all public post types.
	 *
	 * @since 0.5.0
	 *
	 * @param string $since Optional datetime filter (only posts modified after this).
	 * @return WP_Post[]
	 */
	public static function detect_posts( $since = '' ) {
		$settings   = NV_oOS_Graphify::get_settings();
		$post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? $settings['post_types']
			: self::get_default_post_types();

		$args = array(
			'post_type'      => array_map( 'sanitize_key', $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'all',
			'no_found_rows'  => true,
		);

		if ( $since ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => sanitize_text_field( $since ),
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Return the default public post types to index.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	public static function get_default_post_types() {
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		// Exclude 'attachment' by default — media is handled separately.
		unset( $public_types['attachment'] );
		return array_values( $public_types );
	}

	// -------------------------------------------------------------------------
	// Term detection
	// -------------------------------------------------------------------------

	/**
	 * Return terms that are used by at least one post in the supplied array.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @return WP_Term[]
	 */
	public static function detect_terms( array $posts ) {
		if ( empty( $posts ) ) {
			return array();
		}

		$post_ids   = wp_list_pluck( $posts, 'ID' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

		$terms = wp_get_object_terms(
			$post_ids,
			array_values( $taxonomies ),
			array( 'fields' => 'all' )
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Deduplicate by term ID.
		$unique = array();
		foreach ( $terms as $term ) {
			$unique[ $term->term_id ] = $term;
		}
		return array_values( $unique );
	}

	// -------------------------------------------------------------------------
	// User/author detection
	// -------------------------------------------------------------------------

	/**
	 * Return author user objects for the supplied posts.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @return WP_User[]
	 */
	public static function detect_users( array $posts ) {
		if ( empty( $posts ) ) {
			return array();
		}

		$author_ids = array_unique( array_map( 'absint', wp_list_pluck( $posts, 'post_author' ) ) );
		$author_ids = array_filter( $author_ids );

		$users = array();
		foreach ( $author_ids as $uid ) {
			$user = get_userdata( $uid );
			if ( $user instanceof WP_User ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	// -------------------------------------------------------------------------
	// Media detection
	// -------------------------------------------------------------------------

	/**
	 * Return attachment post objects for featured images used by the supplied posts.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to inspect.
	 * @return WP_Post[]
	 */
	public static function detect_media( array $posts ) {
		if ( empty( $posts ) ) {
			return array();
		}

		$attachment_ids = array();
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumb > 0 ) {
				$attachment_ids[] = $thumb;
			}
		}
		$attachment_ids = array_unique( $attachment_ids );

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$media = get_posts(
			array(
				'post_type'      => 'attachment',
				'post__in'       => $attachment_ids,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		return is_array( $media ) ? $media : array();
	}

	// -------------------------------------------------------------------------
	// Node ID helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a stable node_id for a post.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id   WordPress post ID.
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function post_node_id( $post_id, $post_type = 'post' ) {
		return 'post_' . absint( $post_id );
	}

	/**
	 * Generate a stable node_id for a term.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function term_node_id( $term_id, $taxonomy ) {
		return 'term_' . absint( $term_id ) . '_' . sanitize_key( $taxonomy );
	}

	/**
	 * Generate a stable node_id for a user.
	 *
	 * @since 0.5.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function user_node_id( $user_id ) {
		return 'user_' . absint( $user_id );
	}

	/**
	 * Generate a stable node_id for an attachment.
	 *
	 * @since 0.5.0
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 * @return string
	 */
	public static function media_node_id( $attachment_id ) {
		return 'media_' . absint( $attachment_id );
	}

	/**
	 * Generate a stable node_id for a named entity or topic string.
	 *
	 * Uses a short hash so labels with special characters remain safe to store.
	 *
	 * @since 0.5.0
	 *
	 * @param string $label Entity/topic label.
	 * @param string $type  Entity type (entity|topic).
	 * @return string
	 */
	public static function entity_node_id( $label, $type = 'entity' ) {
		return $type . '_' . substr( hash( 'sha256', strtolower( trim( $label ) ) ), 0, 16 );
	}
}
