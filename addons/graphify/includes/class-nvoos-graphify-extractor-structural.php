<?php
/**
 * NV oOS Graphify Addon — Structural Extractor
 *
 * Extracts deterministic (non-AI) relationships from WordPress data
 * and produces graph nodes and edges for the knowledge graph.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural extractor for the NV oOS Graphify Addon.
 *
 * Converts the raw content inventory produced by
 * {@see NV_oOS_Graphify_Detector} into typed graph nodes and
 * relationship edges using only deterministic WordPress APIs
 * (taxonomy associations, authorship, internal links, media).
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Extractor_Structural {

	/**
	 * Graph ID for all produced nodes and edges.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $graph_id;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph ID to assign to extracted data.
	 */
	public function __construct( $graph_id ) {
		$this->graph_id = (int) $graph_id;
	}

	/**
	 * Run all structural extraction on detected content.
	 *
	 * @since 0.1.0
	 *
	 * @param array $detected_content Output from NV_oOS_Graphify_Detector::detect().
	 * @return array {
	 *     Extracted graph data.
	 *
	 *     @type array $nodes Array of node arrays.
	 *     @type array $edges Array of edge arrays.
	 * }
	 */
	public function extract( $detected_content ) {
		$nodes = array();
		$edges = array();

		$posts = isset( $detected_content['posts'] ) ? $detected_content['posts'] : array();
		$terms = isset( $detected_content['terms'] ) ? $detected_content['terms'] : array();
		$users = isset( $detected_content['users'] ) ? $detected_content['users'] : array();
		$media = isset( $detected_content['media'] ) ? $detected_content['media'] : array();

		// Extract nodes.
		$nodes = array_merge(
			$nodes,
			$this->extract_post_nodes( $posts ),
			$this->extract_term_nodes( $terms ),
			$this->extract_user_nodes( $users ),
			$this->extract_media_nodes( $media )
		);

		// Extract edges.
		$edges = array_merge(
			$edges,
			$this->extract_internal_links( $posts ),
			$this->extract_taxonomy_edges( $posts ),
			$this->extract_author_edges( $posts ),
			$this->extract_media_edges( $posts )
		);

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Create graph nodes for posts.
	 *
	 * @since 0.1.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of node arrays.
	 */
	public function extract_post_nodes( $posts ) {
		$nodes = array();

		foreach ( $posts as $post ) {
			$content    = isset( $post['post_content'] ) ? $post['post_content'] : '';
			$word_count = str_word_count( wp_strip_all_tags( $content ) );

			$nodes[] = array(
				'node_id'     => 'post_' . $post['ID'],
				'label'       => $post['post_title'],
				'node_type'   => $post['post_type'],
				'source_type' => 'post',
				'source_id'   => (int) $post['ID'],
				'source_url'  => $post['permalink'],
				'metadata'    => wp_json_encode( array( 'word_count' => $word_count ) ),
			);
		}

		return $nodes;
	}

	/**
	 * Create graph nodes for taxonomy terms.
	 *
	 * @since 0.1.0
	 *
	 * @param array $terms Array of term data arrays from the detector.
	 * @return array Array of node arrays.
	 */
	public function extract_term_nodes( $terms ) {
		$nodes = array();

		foreach ( $terms as $term ) {
			$term_link = get_term_link( (int) $term['term_id'] );

			// Skip terms whose link could not be resolved.
			if ( is_wp_error( $term_link ) ) {
				$term_link = '';
			}

			$nodes[] = array(
				'node_id'     => 'term_' . $term['term_id'],
				'label'       => $term['name'],
				'node_type'   => 'taxonomy_term',
				'source_type' => 'term',
				'source_id'   => (int) $term['term_id'],
				'source_url'  => $term_link,
				'metadata'    => wp_json_encode(
					array(
						'taxonomy' => $term['taxonomy'],
						'count'    => (int) $term['count'],
					)
				),
			);
		}

		return $nodes;
	}

	/**
	 * Create graph nodes for users.
	 *
	 * @since 0.1.0
	 *
	 * @param array $users Array of user data arrays from the detector.
	 * @return array Array of node arrays.
	 */
	public function extract_user_nodes( $users ) {
		$nodes = array();

		foreach ( $users as $user ) {
			$nodes[] = array(
				'node_id'     => 'user_' . $user['ID'],
				'label'       => $user['display_name'],
				'node_type'   => 'user',
				'source_type' => 'user',
				'source_id'   => (int) $user['ID'],
				'source_url'  => get_author_posts_url( (int) $user['ID'] ),
				'metadata'    => wp_json_encode( array( 'login' => $user['user_login'] ) ),
			);
		}

		return $nodes;
	}

	/**
	 * Create graph nodes for media attachments.
	 *
	 * @since 0.1.0
	 *
	 * @param array $media Array of media data arrays from the detector.
	 * @return array Array of node arrays.
	 */
	public function extract_media_nodes( $media ) {
		$nodes = array();

		foreach ( $media as $item ) {
			$nodes[] = array(
				'node_id'     => 'media_' . $item['ID'],
				'label'       => $item['post_title'],
				'node_type'   => 'media',
				'source_type' => 'media',
				'source_id'   => (int) $item['ID'],
				'source_url'  => $item['guid'],
				'metadata'    => wp_json_encode(
					array(
						'mime_type'   => $item['post_mime_type'],
						'post_parent' => (int) $item['post_parent'],
					)
				),
			);
		}

		return $nodes;
	}

	/**
	 * Extract internal link edges from post content.
	 *
	 * Parses each post's HTML content for anchor tags pointing to
	 * other content on the same site and resolves them to post IDs
	 * using {@see url_to_postid()}.
	 *
	 * @since 0.1.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of edge arrays.
	 */
	public function extract_internal_links( $posts ) {
		$edges    = array();
		$home_url = home_url();

		// Build a set of known post IDs for quick validation.
		$known_ids = array();
		foreach ( $posts as $post ) {
			$known_ids[ (int) $post['ID'] ] = true;
		}

		foreach ( $posts as $post ) {
			$content = isset( $post['post_content'] ) ? $post['post_content'] : '';

			if ( empty( $content ) ) {
				continue;
			}

			$match_count = preg_match_all(
				'/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>/i',
				$content,
				$matches
			);

			if ( empty( $match_count ) ) {
				continue;
			}

			$seen_targets = array();

			foreach ( $matches[1] as $url ) {
				$url = trim( $url );

				if ( empty( $url ) || strpos( $url, '#' ) === 0 ) {
					continue;
				}

				// Determine if the URL is internal.
				$is_internal = false;

				if ( strpos( $url, $home_url ) === 0 ) {
					$is_internal = true;
				} elseif ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
					// Relative URL starting with / but not //.
					$is_internal = true;
					$url         = $home_url . $url;
				}

				if ( ! $is_internal ) {
					continue;
				}

				$target_id = url_to_postid( $url );

				if ( empty( $target_id ) || $target_id === (int) $post['ID'] ) {
					continue;
				}

				// Avoid duplicate edges for the same source → target pair.
				if ( isset( $seen_targets[ $target_id ] ) ) {
					continue;
				}
				$seen_targets[ $target_id ] = true;

				$edges[] = array(
					'source_node_id'   => 'post_' . $post['ID'],
					'target_node_id'   => 'post_' . $target_id,
					'relation'         => 'links_to',
					'confidence'       => 'EXTRACTED',
					'confidence_score' => 1.0,
					'metadata'         => wp_json_encode( array() ),
				);
			}
		}

		return $edges;
	}

	/**
	 * Extract taxonomy relationship edges for posts.
	 *
	 * Creates edges between posts and their assigned taxonomy terms,
	 * using `categorized_by` for hierarchical taxonomies and
	 * `tagged_with` for flat taxonomies.
	 *
	 * @since 0.1.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of edge arrays.
	 */
	public function extract_taxonomy_edges( $posts ) {
		$edges = array();

		foreach ( $posts as $post ) {
			$taxonomies = get_object_taxonomies( $post['post_type'], 'objects' );

			foreach ( $taxonomies as $taxonomy ) {
				$post_terms = wp_get_post_terms( (int) $post['ID'], $taxonomy->name );

				if ( is_wp_error( $post_terms ) || empty( $post_terms ) ) {
					continue;
				}

				$relation = $taxonomy->hierarchical ? 'categorized_by' : 'tagged_with';

				foreach ( $post_terms as $term ) {
					$edges[] = array(
						'source_node_id'   => 'post_' . $post['ID'],
						'target_node_id'   => 'term_' . $term->term_id,
						'relation'         => $relation,
						'confidence'       => 'EXTRACTED',
						'confidence_score' => 1.0,
						'metadata'         => wp_json_encode(
							array( 'taxonomy' => $taxonomy->name )
						),
					);
				}
			}
		}

		return $edges;
	}

	/**
	 * Extract authorship edges for posts.
	 *
	 * Creates an `authored_by` edge from each post to its author user node.
	 *
	 * @since 0.1.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of edge arrays.
	 */
	public function extract_author_edges( $posts ) {
		$edges = array();

		foreach ( $posts as $post ) {
			if ( empty( $post['post_author'] ) ) {
				continue;
			}

			$edges[] = array(
				'source_node_id'   => 'post_' . $post['ID'],
				'target_node_id'   => 'user_' . $post['post_author'],
				'relation'         => 'authored_by',
				'confidence'       => 'EXTRACTED',
				'confidence_score' => 1.0,
				'metadata'         => wp_json_encode( array() ),
			);
		}

		return $edges;
	}

	/**
	 * Extract featured image edges for posts.
	 *
	 * Creates a `has_featured_image` edge from each post to its
	 * thumbnail attachment node when a featured image is set.
	 *
	 * @since 0.1.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of edge arrays.
	 */
	public function extract_media_edges( $posts ) {
		$edges = array();

		foreach ( $posts as $post ) {
			$thumbnail_id = get_post_thumbnail_id( (int) $post['ID'] );

			if ( empty( $thumbnail_id ) ) {
				continue;
			}

			$edges[] = array(
				'source_node_id'   => 'post_' . $post['ID'],
				'target_node_id'   => 'media_' . $thumbnail_id,
				'relation'         => 'has_featured_image',
				'confidence'       => 'EXTRACTED',
				'confidence_score' => 1.0,
				'metadata'         => wp_json_encode( array() ),
			);
		}

		return $edges;
	}
}
