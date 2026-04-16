<?php
/**
 * NV oOS Graphify Addon — Structural Extractor
 *
 * Extracts deterministic relationships from WordPress data structures:
 * internal links, taxonomy assignments, authorship, and featured images.
 * All edges produced by this extractor are tagged EXTRACTED with a
 * confidence score of 1.0.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural relationship extractor for the NV oOS Graphify addon.
 *
 * Processes an inventory array (from {@see NV_oOS_Graphify_Detector})
 * and produces node and edge arrays describing the deterministic
 * relationships present in WordPress content.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Extractor_Structural {

	/**
	 * Extract structural nodes and edges from a content inventory.
	 *
	 * Processes posts, terms, users, and media to build a complete set
	 * of graph nodes and the deterministic edges that connect them.
	 *
	 * @since 0.1.0
	 *
	 * @param array $inventory Content inventory from NV_oOS_Graphify_Detector::detect().
	 * @return array {
	 *     Extracted graph data.
	 *
	 *     @type array[] $nodes Array of node arrays.
	 *     @type array[] $edges Array of edge arrays.
	 * }
	 */
	public function extract( $inventory ) {
		$nodes = array();
		$edges = array();

		$posts = isset( $inventory['posts'] ) ? $inventory['posts'] : array();
		$terms = isset( $inventory['terms'] ) ? $inventory['terms'] : array();
		$users = isset( $inventory['users'] ) ? $inventory['users'] : array();
		$media = isset( $inventory['media'] ) ? $inventory['media'] : array();

		// Index nodes by node_id to avoid duplicates.
		$node_index = array();

		// --- User nodes ---------------------------------------------------
		foreach ( $users as $user ) {
			$node                           = $this->make_user_node( $user );
			$node_index[ $node['node_id'] ] = $node;
		}

		// --- Term nodes ---------------------------------------------------
		foreach ( $terms as $term ) {
			$node                           = $this->make_term_node( $term );
			$node_index[ $node['node_id'] ] = $node;
		}

		// --- Media nodes --------------------------------------------------
		foreach ( $media as $attachment ) {
			$node                           = $this->make_media_node( $attachment );
			$node_index[ $node['node_id'] ] = $node;
		}

		// --- Post nodes + edges ------------------------------------------
		foreach ( $posts as $post ) {
			$post_node                           = $this->make_post_node( $post );
			$node_index[ $post_node['node_id'] ] = $post_node;

			// Author relationship.
			if ( ! empty( $post->post_author ) ) {
				$author_node_id = 'user_' . absint( $post->post_author );
				if ( isset( $node_index[ $author_node_id ] ) ) {
					$edges[] = $this->make_edge(
						$post_node['node_id'],
						$author_node_id,
						'authored_by'
					);
				}
			}

			// Taxonomy assignments.
			$taxonomy_edges = $this->extract_taxonomy_edges( $post );
			foreach ( $taxonomy_edges as $tax_edge ) {
				// Ensure the target term node exists in our index.
				$target_id = $tax_edge['target_node_id'];
				if ( ! isset( $node_index[ $target_id ] ) ) {
					$term_id = absint( str_replace( 'term_', '', $target_id ) );
					$term    = get_term( $term_id );
					if ( $term && ! is_wp_error( $term ) ) {
						$node_index[ $target_id ] = $this->make_term_node( $term );
					}
				}
				$edges[] = $tax_edge;
			}

			// Featured image.
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id ) {
				$media_node_id = 'media_' . absint( $thumb_id );
				if ( ! isset( $node_index[ $media_node_id ] ) ) {
					$thumb = get_post( absint( $thumb_id ) );
					if ( $thumb ) {
						$node_index[ $media_node_id ] = $this->make_media_node( $thumb );
					}
				}
				if ( isset( $node_index[ $media_node_id ] ) ) {
					$edges[] = $this->make_edge(
						$post_node['node_id'],
						$media_node_id,
						'has_featured_image'
					);
				}
			}

			// Internal links.
			$link_targets = $this->extract_internal_links( $post );
			foreach ( $link_targets as $target_post_id ) {
				$target_node_id = 'post_' . absint( $target_post_id );
				$edges[]        = $this->make_edge(
					$post_node['node_id'],
					$target_node_id,
					'links_to'
				);
			}
		}

		$nodes = array_values( $node_index );

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Extract internal links from post content.
	 *
	 * Parses `<a href="...">` elements in the post content and returns
	 * the post IDs of any links that point to content on the same site.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post object to parse.
	 * @return int[] Array of target post IDs (unique, excludes self-links).
	 */
	public function extract_internal_links( $post ) {
		$content = $post->post_content;
		if ( empty( $content ) ) {
			return array();
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $home_host ) ) {
			return array();
		}

		$target_ids = array();

		// Match href attribute values.
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return array();
		}

		foreach ( $matches[1] as $href ) {
			$href = trim( $href );
			if ( empty( $href ) || '#' === $href[0] ) {
				continue;
			}

			// Handle relative URLs.
			if ( '/' === $href[0] ) {
				$href = home_url( $href );
			}

			$parsed = wp_parse_url( $href );
			if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
				continue;
			}

			// Only process links to the same site.
			if ( strtolower( $parsed['host'] ) !== strtolower( $home_host ) ) {
				continue;
			}

			$target_id = url_to_postid( $href );
			if ( $target_id > 0 && $target_id !== $post->ID ) {
				$target_ids[ $target_id ] = true;
			}
		}

		return array_keys( $target_ids );
	}

	/**
	 * Create a node array for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return array Node array.
	 */
	public function make_post_node( $post ) {
		return array(
			'node_id'     => 'post_' . absint( $post->ID ),
			'label'       => sanitize_text_field( $post->post_title ),
			'node_type'   => sanitize_key( $post->post_type ),
			'source_type' => 'post',
			'source_id'   => absint( $post->ID ),
			'source_url'  => esc_url( get_permalink( $post->ID ) ),
			'metadata'    => array(
				'post_status'   => sanitize_key( $post->post_status ),
				'post_date'     => sanitize_text_field( $post->post_date ),
				'comment_count' => absint( $post->comment_count ),
				'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			),
		);
	}

	/**
	 * Create a node array for a taxonomy term.
	 *
	 * @since 0.1.0
	 *
	 * @param object $term Term object (WP_Term or stdClass).
	 * @return array Node array.
	 */
	public function make_term_node( $term ) {
		$term_link = get_term_link( $term );
		if ( is_wp_error( $term_link ) ) {
			$term_link = '';
		}

		return array(
			'node_id'     => 'term_' . absint( $term->term_id ),
			'label'       => sanitize_text_field( $term->name ),
			'node_type'   => 'term',
			'source_type' => 'term',
			'source_id'   => absint( $term->term_id ),
			'source_url'  => esc_url( $term_link ),
			'metadata'    => array(
				'taxonomy' => sanitize_key( $term->taxonomy ),
				'slug'     => sanitize_title( $term->slug ),
				'count'    => absint( $term->count ),
			),
		);
	}

	/**
	 * Create a node array for a user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User object.
	 * @return array Node array.
	 */
	public function make_user_node( $user ) {
		return array(
			'node_id'     => 'user_' . absint( $user->ID ),
			'label'       => sanitize_text_field( $user->display_name ),
			'node_type'   => 'user',
			'source_type' => 'user',
			'source_id'   => absint( $user->ID ),
			'source_url'  => esc_url( get_author_posts_url( $user->ID ) ),
			'metadata'    => array(
				'login' => sanitize_user( $user->user_login ),
				'email' => sanitize_email( $user->user_email ),
				'role'  => ! empty( $user->roles ) ? sanitize_text_field( implode( ', ', $user->roles ) ) : '',
			),
		);
	}

	/**
	 * Create a node array for a media attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Attachment post object.
	 * @return array Node array.
	 */
	public function make_media_node( $post ) {
		return array(
			'node_id'     => 'media_' . absint( $post->ID ),
			'label'       => sanitize_text_field( $post->post_title ),
			'node_type'   => 'media',
			'source_type' => 'media',
			'source_id'   => absint( $post->ID ),
			'source_url'  => esc_url( wp_get_attachment_url( $post->ID ) ),
			'metadata'    => array(
				'mime_type' => sanitize_mime_type( $post->post_mime_type ),
				'alt_text'  => sanitize_text_field( get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ),
			),
		);
	}

	/**
	 * Extract taxonomy assignment edges for a single post.
	 *
	 * Creates `categorized_by` edges for categories, `tagged_with` edges
	 * for post tags, and `has_term` edges for all other taxonomies.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return array[] Array of edge arrays.
	 */
	private function extract_taxonomy_edges( $post ) {
		$edges      = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$relation = 'has_term';
			if ( 'category' === $taxonomy ) {
				$relation = 'categorized_by';
			} elseif ( 'post_tag' === $taxonomy ) {
				$relation = 'tagged_with';
			}

			foreach ( $terms as $term ) {
				$edges[] = $this->make_edge(
					'post_' . absint( $post->ID ),
					'term_' . absint( $term->term_id ),
					$relation,
					array( 'taxonomy' => sanitize_key( $taxonomy ) )
				);
			}
		}

		return $edges;
	}

	/**
	 * Create a deterministic (EXTRACTED) edge array.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source_node_id Source node identifier.
	 * @param string $target_node_id Target node identifier.
	 * @param string $relation       Relationship type slug.
	 * @param array  $metadata       Optional. Additional metadata for the edge.
	 * @return array Edge array.
	 */
	private function make_edge( $source_node_id, $target_node_id, $relation, $metadata = array() ) {
		return array(
			'source_node_id'   => sanitize_text_field( $source_node_id ),
			'target_node_id'   => sanitize_text_field( $target_node_id ),
			'relation'         => sanitize_key( $relation ),
			'confidence'       => 'EXTRACTED',
			'confidence_score' => 1.0,
			'metadata'         => $metadata,
		);
	}
}
