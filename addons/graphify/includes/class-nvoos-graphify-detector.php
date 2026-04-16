<?php
/**
 * NV oOS Graphify Addon — Content Detector
 *
 * Inventories WordPress content (posts, terms, users, media) to
 * determine what should be processed by the extraction pipeline.
 * Analogous to Graphify's detect.py.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content detector for the NV oOS Graphify addon.
 *
 * Scans the WordPress database for published content and returns
 * a structured inventory array consumed by the extractor classes.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Detector {

	/**
	 * Maximum number of media attachments to include in a single detection pass.
	 *
	 * @var int
	 */
	const MEDIA_LIMIT = 1000;

	/**
	 * Detect all eligible content for graph extraction.
	 *
	 * Queries published posts, taxonomy terms, users, and media
	 * according to the provided (or stored) settings and returns
	 * a full inventory array.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Optional. Override settings instead of reading from the database.
	 * @return array {
	 *     Structured content inventory.
	 *
	 *     @type WP_Post[] $posts Array of post objects.
	 *     @type object[]  $terms Array of term objects.
	 *     @type WP_User[] $users Array of user objects.
	 *     @type WP_Post[] $media Array of attachment post objects.
	 *     @type array     $stats {
	 *         Aggregate statistics.
	 *
	 *         @type int $total_posts Total number of posts detected.
	 *         @type int $total_terms Total number of terms detected.
	 *         @type int $total_users Total number of users detected.
	 *         @type int $total_media Total number of media items detected.
	 *         @type int $total_words Approximate word count across all post content.
	 *     }
	 * }
	 */
	public function detect( $settings = array() ) {
		if ( empty( $settings ) ) {
			$settings = NV_oOS_Graphify::get_settings();
		}

		$content_types = $this->sanitize_content_types( $settings );

		$posts = $this->query_posts( $content_types );
		$terms = $this->query_terms( $content_types, $settings );
		$users = $this->query_users( $posts, $settings );
		$media = $this->query_media( $settings );

		$total_words = $this->calculate_word_count( $posts );

		return array(
			'posts' => $posts,
			'terms' => $terms,
			'users' => $users,
			'media' => $media,
			'stats' => array(
				'total_posts' => count( $posts ),
				'total_terms' => count( $terms ),
				'total_users' => count( $users ),
				'total_media' => count( $media ),
				'total_words' => $total_words,
			),
		);
	}

	/**
	 * Detect content modified since a given datetime.
	 *
	 * Behaves like {@see detect()} but restricts results to content
	 * whose `post_modified` column is after the provided timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $since Optional. Date/time string in Y-m-d H:i:s format.
	 *                           Defaults to 24 hours ago when null.
	 * @return array Same structure as {@see detect()}.
	 */
	public function detect_incremental( $since = null ) {
		if ( null === $since ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		}

		$settings      = NV_oOS_Graphify::get_settings();
		$content_types = $this->sanitize_content_types( $settings );

		$posts = $this->query_posts( $content_types, $since );
		$terms = $this->query_terms( $content_types, $settings );
		$users = $this->query_users( $posts, $settings );
		$media = $this->query_media( $settings, $since );

		$total_words = $this->calculate_word_count( $posts );

		return array(
			'posts' => $posts,
			'terms' => $terms,
			'users' => $users,
			'media' => $media,
			'stats' => array(
				'total_posts' => count( $posts ),
				'total_terms' => count( $terms ),
				'total_users' => count( $users ),
				'total_media' => count( $media ),
				'total_words' => $total_words,
			),
		);
	}

	/**
	 * Detect a single post and its related terms and author.
	 *
	 * Useful for incremental graph rebuilds triggered by a post save.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID to detect.
	 * @return array Same structure as {@see detect()}, filtered to one post.
	 */
	public function detect_single( $post_id ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array(
				'posts' => array(),
				'terms' => array(),
				'users' => array(),
				'media' => array(),
				'stats' => array(
					'total_posts' => 0,
					'total_terms' => 0,
					'total_users' => 0,
					'total_media' => 0,
					'total_words' => 0,
				),
			);
		}

		$posts = array( $post );

		// Collect terms assigned to this post.
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		$terms      = array();
		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = wp_get_object_terms( $post_id, $taxonomy );
			if ( ! is_wp_error( $post_terms ) ) {
				$terms = array_merge( $terms, $post_terms );
			}
		}

		// Get the author.
		$users = array();
		if ( $post->post_author ) {
			$author = get_userdata( absint( $post->post_author ) );
			if ( $author ) {
				$users[] = $author;
			}
		}

		// Get the featured image if present.
		$media    = array();
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$thumb = get_post( absint( $thumb_id ) );
			if ( $thumb ) {
				$media[] = $thumb;
			}
		}

		$total_words = $this->calculate_word_count( $posts );

		return array(
			'posts' => $posts,
			'terms' => $terms,
			'users' => $users,
			'media' => $media,
			'stats' => array(
				'total_posts' => count( $posts ),
				'total_terms' => count( $terms ),
				'total_users' => count( $users ),
				'total_media' => count( $media ),
				'total_words' => $total_words,
			),
		);
	}

	/**
	 * Sanitize and validate the configured content types.
	 *
	 * Falls back to `array( 'post', 'page' )` when the setting is
	 * empty or not an array.
	 *
	 * @since 0.1.0
	 *
	 * @param array $settings Addon settings array.
	 * @return string[] Array of post type slugs.
	 */
	private function sanitize_content_types( $settings ) {
		$content_types = isset( $settings['content_types'] ) ? $settings['content_types'] : array();

		if ( ! is_array( $content_types ) || empty( $content_types ) ) {
			$content_types = array( 'post', 'page' );
		}

		return array_map( 'sanitize_key', $content_types );
	}

	/**
	 * Query published posts for the given post types.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]    $content_types Array of post type slugs.
	 * @param string|null $since         Optional. Only return posts modified after this datetime.
	 * @return WP_Post[]
	 */
	private function query_posts( $content_types, $since = null ) {
		$args = array(
			'post_type'      => $content_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( null !== $since ) {
			$args['date_query'] = array(
				array(
					'after'  => sanitize_text_field( $since ),
					'column' => 'post_modified',
				),
			);
		}

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Query taxonomy terms associated with the given post types.
	 *
	 * Only runs when the `include_taxonomies` setting is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $content_types Array of post type slugs.
	 * @param array    $settings      Addon settings array.
	 * @return object[] Array of term objects.
	 */
	private function query_terms( $content_types, $settings ) {
		if ( empty( $settings['include_taxonomies'] ) ) {
			return array();
		}

		$taxonomies = array();
		foreach ( $content_types as $post_type ) {
			$type_taxonomies = get_object_taxonomies( $post_type, 'names' );
			$taxonomies      = array_merge( $taxonomies, $type_taxonomies );
		}
		$taxonomies = array_unique( $taxonomies );

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Query authors who have published at least one of the detected posts.
	 *
	 * Only runs when the `include_users` setting is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post[] $posts    Array of post objects already detected.
	 * @param array     $settings Addon settings array.
	 * @return WP_User[] Array of user objects.
	 */
	private function query_users( $posts, $settings ) {
		if ( empty( $settings['include_users'] ) ) {
			return array();
		}

		$author_ids = array();
		foreach ( $posts as $post ) {
			if ( ! empty( $post->post_author ) ) {
				$author_ids[ absint( $post->post_author ) ] = true;
			}
		}

		if ( empty( $author_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => array_keys( $author_ids ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return $users;
	}

	/**
	 * Query media attachments.
	 *
	 * Only runs when the `include_media` setting is enabled.
	 * Results are capped at {@see MEDIA_LIMIT}.
	 *
	 * @since 0.1.0
	 *
	 * @param array       $settings Addon settings array.
	 * @param string|null $since    Optional. Only return media modified after this datetime.
	 * @return WP_Post[] Array of attachment post objects.
	 */
	private function query_media( $settings, $since = null ) {
		if ( empty( $settings['include_media'] ) ) {
			return array();
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => self::MEDIA_LIMIT,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		if ( null !== $since ) {
			$args['date_query'] = array(
				array(
					'after'  => sanitize_text_field( $since ),
					'column' => 'post_modified',
				),
			);
		}

		$query = new WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Calculate the total word count across all post content.
	 *
	 * Strips HTML tags before counting so that markup is not included.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post[] $posts Array of post objects.
	 * @return int Total word count.
	 */
	private function calculate_word_count( $posts ) {
		$total = 0;

		foreach ( $posts as $post ) {
			$plain_text = wp_strip_all_tags( $post->post_content );
			$total     += str_word_count( $plain_text );
		}

		return $total;
	}
}
