<?php
/**
 * NV oOS Graphify Addon — Content Detector
 *
 * Inventories what content exists on the WordPress site so that
 * downstream extractors can build graph nodes and edges from it.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content detector for the NV oOS Graphify Addon.
 *
 * Queries WordPress for posts, terms, users, and media items
 * based on the current addon settings and returns a structured
 * inventory suitable for the extraction pipeline.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Detector {

	/**
	 * Default safety limit for queries.
	 *
	 * @var int
	 */
	const DEFAULT_DETECTION_LIMIT = 10000;

	/**
	 * Addon settings.
	 *
	 * @since 0.1.0
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Maximum number of items to retrieve per query.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $detection_limit;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Optional. Addon settings. Defaults to NV_oOS_Graphify::get_settings().
	 */
	public function __construct( $settings = array() ) {
		$this->settings = ! empty( $settings ) ? $settings : NV_oOS_Graphify::get_settings();

		/**
		 * Filters the maximum number of items the detector will retrieve per query.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $limit    Default detection limit.
		 * @param array $settings Current addon settings.
		 */
		$this->detection_limit = (int) apply_filters(
			'nvoos_graphify_detection_limit',
			self::DEFAULT_DETECTION_LIMIT,
			$this->settings
		);
	}

	/**
	 * Run the full content detection.
	 *
	 * @since 0.1.0
	 *
	 * @param bool        $incremental Whether to detect only content modified since $since.
	 * @param string|null $since       MySQL datetime string for incremental detection.
	 * @return array {
	 *     Detected content organised by type.
	 *
	 *     @type array $posts Array of post data arrays.
	 *     @type array $terms Array of term data arrays.
	 *     @type array $users Array of user data arrays.
	 *     @type array $media Array of media data arrays.
	 *     @type array $stats Summary statistics.
	 * }
	 */
	public function detect( $incremental = false, $since = null ) {
		$posts = $this->detect_posts( $incremental, $since );
		$terms = array();
		$users = array();
		$media = array();

		if ( ! empty( $this->settings['include_taxonomies'] ) ) {
			$terms = $this->detect_terms();
		}

		if ( ! empty( $this->settings['include_users'] ) ) {
			$users = $this->detect_users();
		}

		if ( ! empty( $this->settings['include_media'] ) ) {
			$media = $this->detect_media();
		}

		$post_type_breakdown = array();
		foreach ( $posts as $post ) {
			if ( ! isset( $post_type_breakdown[ $post['post_type'] ] ) ) {
				$post_type_breakdown[ $post['post_type'] ] = 0;
			}
			++$post_type_breakdown[ $post['post_type'] ];
		}

		return array(
			'posts' => $posts,
			'terms' => $terms,
			'users' => $users,
			'media' => $media,
			'stats' => array(
				'total_posts'         => count( $posts ),
				'total_terms'         => count( $terms ),
				'total_users'         => count( $users ),
				'total_media'         => count( $media ),
				'post_type_breakdown' => $post_type_breakdown,
			),
		);
	}

	/**
	 * Detect published posts for the configured post types.
	 *
	 * @since 0.1.0
	 *
	 * @param bool        $incremental Whether to filter by modification date.
	 * @param string|null $since       MySQL datetime string for the modification cutoff.
	 * @return array Array of post data arrays.
	 */
	public function detect_posts( $incremental = false, $since = null ) {
		$post_types = ! empty( $this->settings['post_types'] ) ? $this->settings['post_types'] : array( 'post', 'page' );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $this->detection_limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( $incremental && ! empty( $since ) ) {
			$query_args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $since,
				),
			);
		}

		$query = new WP_Query( $query_args );
		$posts = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$posts[] = array(
					'ID'            => (int) $post->ID,
					'post_type'     => $post->post_type,
					'post_title'    => $post->post_title,
					'post_name'     => $post->post_name,
					'post_author'   => (int) $post->post_author,
					'post_modified' => $post->post_modified,
					'post_content'  => $post->post_content,
					'permalink'     => get_permalink( $post->ID ),
				);
			}
		}

		wp_reset_postdata();

		return $posts;
	}

	/**
	 * Detect taxonomy terms associated with the configured post types.
	 *
	 * @since 0.1.0
	 *
	 * @return array Array of term data arrays.
	 */
	public function detect_terms() {
		$post_types = ! empty( $this->settings['post_types'] ) ? $this->settings['post_types'] : array( 'post', 'page' );
		$taxonomies = get_object_taxonomies( $post_types, 'names' );

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$raw_terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => $this->detection_limit,
			)
		);

		if ( is_wp_error( $raw_terms ) || empty( $raw_terms ) ) {
			return array();
		}

		$terms = array();
		foreach ( $raw_terms as $term ) {
			$terms[] = array(
				'term_id'  => (int) $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'taxonomy' => $term->taxonomy,
				'count'    => (int) $term->count,
			);
		}

		return $terms;
	}

	/**
	 * Detect users who have published posts in the configured post types.
	 *
	 * @since 0.1.0
	 *
	 * @return array Array of user data arrays.
	 */
	public function detect_users() {
		$post_types = ! empty( $this->settings['post_types'] ) ? $this->settings['post_types'] : array( 'post', 'page' );

		$raw_users = get_users(
			array(
				'has_published_posts' => $post_types,
				'number'             => $this->detection_limit,
				'orderby'            => 'ID',
				'order'              => 'ASC',
			)
		);

		if ( empty( $raw_users ) ) {
			return array();
		}

		$users = array();
		foreach ( $raw_users as $user ) {
			$users[] = array(
				'ID'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'user_url'     => $user->user_url,
			);
		}

		return $users;
	}

	/**
	 * Detect media attachments.
	 *
	 * @since 0.1.0
	 *
	 * @return array Array of media data arrays.
	 */
	public function detect_media() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $this->detection_limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$media = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $attachment ) {
				$media[] = array(
					'ID'             => (int) $attachment->ID,
					'post_title'     => $attachment->post_title,
					'post_mime_type' => $attachment->post_mime_type,
					'guid'           => $attachment->guid,
					'post_parent'    => (int) $attachment->post_parent,
				);
			}
		}

		wp_reset_postdata();

		return $media;
	}
}
