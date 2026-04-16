<?php
/**
 * Graphify Knowledge Graph — Content Detector
 *
 * Inventories WordPress content that should be included in the knowledge
 * graph. Analogous to Graphify's detect.py — determines *what* to extract
 * without performing the extraction itself.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans WordPress content and returns a structured inventory of extractable items.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Detector {

	/**
	 * Default post types to include if none are specified.
	 *
	 * @var array
	 */
	const DEFAULT_POST_TYPES = array( 'post', 'page' );

	/**
	 * Detect all extractable content.
	 *
	 * @param array $options {
	 *     Optional. Detection options.
	 *
	 *     @type array  $post_types Post types to include. Default: post, page.
	 *     @type string $since      Only content modified after this datetime (ISO 8601). Empty = all.
	 *     @type bool   $include_taxonomies Whether to include taxonomy terms. Default true.
	 *     @type bool   $include_users      Whether to include authors. Default true.
	 *     @type bool   $include_media      Whether to include media items. Default false.
	 *     @type int    $posts_per_page     Batch size for post queries. Default 200.
	 * }
	 * @return array {
	 *     Detection results.
	 *
	 *     @type array $posts      Array of post data arrays.
	 *     @type array $terms      Array of term data arrays.
	 *     @type array $users      Array of user data arrays.
	 *     @type array $media      Array of media data arrays.
	 *     @type array $stats      Summary statistics.
	 * }
	 */
	public function detect( $options = array() ) {
		$defaults = array(
			'post_types'         => self::DEFAULT_POST_TYPES,
			'since'              => '',
			'include_taxonomies' => true,
			'include_users'      => true,
			'include_media'      => false,
			'posts_per_page'     => 200,
		);

		$options = wp_parse_args( $options, $defaults );

		$result = array(
			'posts' => array(),
			'terms' => array(),
			'users' => array(),
			'media' => array(),
			'stats' => array(
				'total_posts'   => 0,
				'total_terms'   => 0,
				'total_users'   => 0,
				'total_media'   => 0,
				'total_words'   => 0,
				'post_types'    => array(),
				'incremental'   => ! empty( $options['since'] ),
			),
		);

		// 1. Detect posts/pages.
		$result['posts'] = $this->detect_posts( $options );
		$result['stats']['total_posts'] = count( $result['posts'] );

		// Count words and post type breakdown.
		foreach ( $result['posts'] as $post_data ) {
			$result['stats']['total_words'] += $post_data['word_count'];

			$pt = $post_data['post_type'];
			if ( ! isset( $result['stats']['post_types'][ $pt ] ) ) {
				$result['stats']['post_types'][ $pt ] = 0;
			}
			++$result['stats']['post_types'][ $pt ];
		}

		// 2. Detect taxonomy terms.
		if ( $options['include_taxonomies'] ) {
			$result['terms'] = $this->detect_terms( $options['post_types'] );
			$result['stats']['total_terms'] = count( $result['terms'] );
		}

		// 3. Detect authors.
		if ( $options['include_users'] ) {
			$result['users'] = $this->detect_users( $options['post_types'] );
			$result['stats']['total_users'] = count( $result['users'] );
		}

		// 4. Detect media.
		if ( $options['include_media'] ) {
			$result['media'] = $this->detect_media( $options );
			$result['stats']['total_media'] = count( $result['media'] );
		}

		return $result;
	}

	/**
	 * Detect published posts for the given post types.
	 *
	 * @param array $options Detection options.
	 * @return array Array of post data.
	 */
	protected function detect_posts( $options ) {
		$query_args = array(
			'post_type'              => $options['post_types'],
			'post_status'            => 'publish',
			'posts_per_page'         => min( absint( $options['posts_per_page'] ), 500 ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			'suppress_filters'       => false,
		);

		// Incremental: only posts modified after the given date.
		if ( ! empty( $options['since'] ) ) {
			$query_args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => sanitize_text_field( $options['since'] ),
				),
			);
		}

		$posts  = array();
		$paged  = 1;

		do {
			$query_args['paged'] = $paged;
			$query = new WP_Query( $query_args );

			if ( ! $query->have_posts() ) {
				break;
			}

			foreach ( $query->posts as $post ) {
				$content    = $post->post_content;
				$word_count = str_word_count( wp_strip_all_tags( $content ) );

				$posts[] = array(
					'post_id'    => $post->ID,
					'title'      => $post->post_title,
					'post_type'  => $post->post_type,
					'permalink'  => get_permalink( $post->ID ),
					'content'    => $content,
					'word_count' => $word_count,
					'modified'   => $post->post_modified_gmt,
					'author_id'  => (int) $post->post_author,
				);
			}

			++$paged;

		} while ( $paged <= $query->max_num_pages );

		wp_reset_postdata();

		return $posts;
	}

	/**
	 * Detect taxonomy terms that are assigned to published posts.
	 *
	 * @param array $post_types Post types to check terms for.
	 * @return array Array of term data.
	 */
	protected function detect_terms( $post_types ) {
		$taxonomies = array();

		foreach ( $post_types as $pt ) {
			$pt_taxonomies = get_object_taxonomies( $pt, 'names' );
			$taxonomies    = array_merge( $taxonomies, $pt_taxonomies );
		}

		$taxonomies = array_unique( $taxonomies );

		// Exclude internal/nav taxonomies.
		$exclude = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' );
		$taxonomies = array_diff( $taxonomies, $exclude );

		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms_data = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 500,
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$terms_data[] = array(
					'term_id'    => $term->term_id,
					'name'       => $term->name,
					'slug'       => $term->slug,
					'taxonomy'   => $term->taxonomy,
					'count'      => $term->count,
					'parent'     => $term->parent,
					'term_url'   => get_term_link( $term ),
				);
			}
		}

		return $terms_data;
	}

	/**
	 * Detect users who have authored published content.
	 *
	 * @param array $post_types Post types to check authorship for.
	 * @return array Array of user data.
	 */
	protected function detect_users( $post_types ) {
		global $wpdb;

		// Get distinct author IDs from published posts of the given types.
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$author_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_types
			)
		);

		if ( empty( $author_ids ) ) {
			return array();
		}

		$users_data = array();

		foreach ( $author_ids as $author_id ) {
			$user = get_userdata( (int) $author_id );
			if ( ! $user ) {
				continue;
			}

			$users_data[] = array(
				'user_id'      => $user->ID,
				'display_name' => $user->display_name,
				'user_url'     => get_author_posts_url( $user->ID ),
				'post_count'   => count_user_posts( $user->ID, $post_types, true ),
			);
		}

		return $users_data;
	}

	/**
	 * Detect media attachments.
	 *
	 * @param array $options Detection options.
	 * @return array Array of media data.
	 */
	protected function detect_media( $options ) {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( absint( $options['posts_per_page'] ), 200 ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
		);

		$query = new WP_Query( $query_args );
		$media = array();

		foreach ( $query->posts as $attachment ) {
			$media[] = array(
				'post_id'   => $attachment->ID,
				'title'     => $attachment->post_title,
				'alt_text'  => get_post_meta( $attachment->ID, '_wp_attachment_alt', true ),
				'mime_type' => $attachment->post_mime_type,
				'url'       => wp_get_attachment_url( $attachment->ID ),
				'parent_id' => $attachment->post_parent,
			);
		}

		wp_reset_postdata();

		return $media;
	}
}
