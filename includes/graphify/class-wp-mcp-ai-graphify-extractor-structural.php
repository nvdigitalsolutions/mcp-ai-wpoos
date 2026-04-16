<?php
/**
 * Graphify Knowledge Graph — Structural Extractor
 *
 * Deterministic extraction of relationships from WordPress data.
 * All edges produced here are tagged EXTRACTED with confidence 1.0.
 * Analogous to Graphify's AST pass — no AI/LLM calls.
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
 * Extracts explicit structural relationships from WordPress content.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Extractor_Structural {

	/**
	 * Extract nodes and edges from detected content.
	 *
	 * @param array $detection Detection results from WP_MCP_AI_Graphify_Detector::detect().
	 * @param int   $graph_id  Graph ID. Default 1.
	 * @return array {
	 *     @type array $nodes Array of node data arrays.
	 *     @type array $edges Array of edge data arrays.
	 * }
	 */
	public function extract( $detection, $graph_id = 1 ) {
		$nodes = array();
		$edges = array();

		// 1. Create nodes for posts.
		foreach ( $detection['posts'] as $post_data ) {
			$node_id = 'post_' . $post_data['post_id'];

			$nodes[ $node_id ] = array(
				'graph_id'    => $graph_id,
				'node_id'     => $node_id,
				'label'       => $post_data['title'],
				'node_type'   => $post_data['post_type'],
				'source_type' => 'post',
				'source_id'   => $post_data['post_id'],
				'source_url'  => $post_data['permalink'],
				'metadata'    => wp_json_encode(
					array(
						'word_count' => $post_data['word_count'],
						'modified'   => $post_data['modified'],
					)
				),
			);
		}

		// 2. Create nodes for taxonomy terms.
		foreach ( $detection['terms'] as $term_data ) {
			$node_id = 'term_' . $term_data['term_id'];

			$nodes[ $node_id ] = array(
				'graph_id'    => $graph_id,
				'node_id'     => $node_id,
				'label'       => $term_data['name'],
				'node_type'   => 'taxonomy_term',
				'source_type' => $term_data['taxonomy'],
				'source_id'   => $term_data['term_id'],
				'source_url'  => is_string( $term_data['term_url'] ) ? $term_data['term_url'] : '',
				'metadata'    => wp_json_encode(
					array(
						'taxonomy' => $term_data['taxonomy'],
						'slug'     => $term_data['slug'],
						'count'    => $term_data['count'],
					)
				),
			);
		}

		// 3. Create nodes for users.
		foreach ( $detection['users'] as $user_data ) {
			$node_id = 'user_' . $user_data['user_id'];

			$nodes[ $node_id ] = array(
				'graph_id'    => $graph_id,
				'node_id'     => $node_id,
				'label'       => $user_data['display_name'],
				'node_type'   => 'user',
				'source_type' => 'user',
				'source_id'   => $user_data['user_id'],
				'source_url'  => $user_data['user_url'],
				'metadata'    => wp_json_encode(
					array(
						'post_count' => $user_data['post_count'],
					)
				),
			);
		}

		// 4. Extract edges.
		$this->extract_taxonomy_edges( $detection['posts'], $edges, $graph_id );
		$this->extract_author_edges( $detection['posts'], $edges, $graph_id );
		$this->extract_internal_link_edges( $detection['posts'], $edges, $nodes, $graph_id );
		$this->extract_featured_image_edges( $detection['posts'], $edges, $nodes, $graph_id );

		return array(
			'nodes' => array_values( $nodes ),
			'edges' => $edges,
		);
	}

	/**
	 * Extract taxonomy assignment edges (post → term).
	 *
	 * @param array $posts    Detected post data.
	 * @param array $edges    Edges array (modified by reference).
	 * @param int   $graph_id Graph ID.
	 * @return void
	 */
	protected function extract_taxonomy_edges( $posts, &$edges, $graph_id ) {
		foreach ( $posts as $post_data ) {
			$post_id      = $post_data['post_id'];
			$post_node_id = 'post_' . $post_id;

			// Get all taxonomies for this post type.
			$taxonomies = get_object_taxonomies( $post_data['post_type'], 'names' );

			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$term_node_id = 'term_' . $term->term_id;

					$relation = 'categorized_by';
					if ( 'post_tag' === $taxonomy ) {
						$relation = 'tagged_with';
					} elseif ( 'category' !== $taxonomy ) {
						$relation = 'has_term';
					}

					$edges[] = array(
						'graph_id'         => $graph_id,
						'source_node_id'   => $post_node_id,
						'target_node_id'   => $term_node_id,
						'relation'         => $relation,
						'confidence'       => 'EXTRACTED',
						'confidence_score' => 1.0,
						'metadata'         => wp_json_encode(
							array( 'taxonomy' => $taxonomy )
						),
					);
				}
			}
		}
	}

	/**
	 * Extract author edges (post → user).
	 *
	 * @param array $posts    Detected post data.
	 * @param array $edges    Edges array (modified by reference).
	 * @param int   $graph_id Graph ID.
	 * @return void
	 */
	protected function extract_author_edges( $posts, &$edges, $graph_id ) {
		foreach ( $posts as $post_data ) {
			if ( empty( $post_data['author_id'] ) ) {
				continue;
			}

			$edges[] = array(
				'graph_id'         => $graph_id,
				'source_node_id'   => 'post_' . $post_data['post_id'],
				'target_node_id'   => 'user_' . $post_data['author_id'],
				'relation'         => 'authored_by',
				'confidence'       => 'EXTRACTED',
				'confidence_score' => 1.0,
				'metadata'         => '{}',
			);
		}
	}

	/**
	 * Extract internal link edges by parsing post_content for <a href>.
	 *
	 * @param array $posts    Detected post data.
	 * @param array $edges    Edges array (modified by reference).
	 * @param array $nodes    Nodes array (may be modified to add link target nodes).
	 * @param int   $graph_id Graph ID.
	 * @return void
	 */
	protected function extract_internal_link_edges( $posts, &$edges, &$nodes, $graph_id ) {
		$site_url = untrailingslashit( home_url() );

		// Build a lookup: permalink → node_id for fast matching.
		$permalink_to_node = array();
		foreach ( $posts as $post_data ) {
			$permalink = untrailingslashit( $post_data['permalink'] );
			$permalink_to_node[ $permalink ] = 'post_' . $post_data['post_id'];
		}

		foreach ( $posts as $post_data ) {
			$content       = $post_data['content'];
			$source_node   = 'post_' . $post_data['post_id'];

			// Extract all hrefs from the content.
			$links = $this->extract_hrefs( $content );

			foreach ( $links as $href ) {
				// Only internal links.
				if ( strpos( $href, $site_url ) !== 0 && strpos( $href, '/' ) !== 0 ) {
					continue;
				}

				// Normalize to absolute URL.
				if ( strpos( $href, '/' ) === 0 ) {
					$href = $site_url . $href;
				}

				$href = untrailingslashit( $href );

				// Skip self-links.
				$source_permalink = untrailingslashit( $post_data['permalink'] );
				if ( $href === $source_permalink ) {
					continue;
				}

				// Skip anchors, query params only.
				if ( strpos( $href, '#' ) !== false ) {
					$href = strtok( $href, '#' );
				}

				// Match to a known node.
				if ( isset( $permalink_to_node[ $href ] ) ) {
					$target_node = $permalink_to_node[ $href ];

					// Avoid duplicate edges from the same post to the same target.
					$edge_key = $source_node . '→' . $target_node . '→links_to';
					static $seen_edges = array();
					if ( isset( $seen_edges[ $edge_key ] ) ) {
						continue;
					}
					$seen_edges[ $edge_key ] = true;

					$edges[] = array(
						'graph_id'         => $graph_id,
						'source_node_id'   => $source_node,
						'target_node_id'   => $target_node,
						'relation'         => 'links_to',
						'confidence'       => 'EXTRACTED',
						'confidence_score' => 1.0,
						'metadata'         => '{}',
					);
				} else {
					// Try to resolve URL to a post ID for links not in our current detection set.
					$linked_post_id = url_to_postid( $href );
					if ( $linked_post_id > 0 ) {
						$target_node = 'post_' . $linked_post_id;

						// Create the target node if it doesn't exist yet.
						if ( ! isset( $nodes[ $target_node ] ) ) {
							$linked_post = get_post( $linked_post_id );
							if ( $linked_post && 'publish' === $linked_post->post_status ) {
								$nodes[ $target_node ] = array(
									'graph_id'    => $graph_id,
									'node_id'     => $target_node,
									'label'       => $linked_post->post_title,
									'node_type'   => $linked_post->post_type,
									'source_type' => 'post',
									'source_id'   => $linked_post_id,
									'source_url'  => get_permalink( $linked_post_id ),
									'metadata'    => wp_json_encode(
										array(
											'word_count' => str_word_count( wp_strip_all_tags( $linked_post->post_content ) ),
											'discovered' => 'link_target',
										)
									),
								);
							}
						}

						if ( isset( $nodes[ $target_node ] ) ) {
							$edges[] = array(
								'graph_id'         => $graph_id,
								'source_node_id'   => $source_node,
								'target_node_id'   => $target_node,
								'relation'         => 'links_to',
								'confidence'       => 'EXTRACTED',
								'confidence_score' => 1.0,
								'metadata'         => '{}',
							);
						}
					}
				}
			}
		}
	}

	/**
	 * Extract featured image edges (post → media attachment).
	 *
	 * @param array $posts    Detected post data.
	 * @param array $edges    Edges array (modified by reference).
	 * @param array $nodes    Nodes array (may be modified to add media nodes).
	 * @param int   $graph_id Graph ID.
	 * @return void
	 */
	protected function extract_featured_image_edges( $posts, &$edges, &$nodes, $graph_id ) {
		foreach ( $posts as $post_data ) {
			$thumbnail_id = get_post_thumbnail_id( $post_data['post_id'] );
			if ( ! $thumbnail_id ) {
				continue;
			}

			$media_node_id = 'media_' . $thumbnail_id;

			// Create media node if not present.
			if ( ! isset( $nodes[ $media_node_id ] ) ) {
				$attachment = get_post( $thumbnail_id );
				if ( $attachment ) {
					$nodes[ $media_node_id ] = array(
						'graph_id'    => $graph_id,
						'node_id'     => $media_node_id,
						'label'       => $attachment->post_title ? $attachment->post_title : __( 'Featured Image', 'mcp-ai-wpoos' ),
						'node_type'   => 'media',
						'source_type' => 'attachment',
						'source_id'   => $thumbnail_id,
						'source_url'  => wp_get_attachment_url( $thumbnail_id ),
						'metadata'    => wp_json_encode(
							array(
								'alt_text'  => get_post_meta( $thumbnail_id, '_wp_attachment_alt', true ),
								'mime_type' => $attachment->post_mime_type,
							)
						),
					);
				}
			}

			if ( isset( $nodes[ $media_node_id ] ) ) {
				$edges[] = array(
					'graph_id'         => $graph_id,
					'source_node_id'   => 'post_' . $post_data['post_id'],
					'target_node_id'   => $media_node_id,
					'relation'         => 'has_featured_image',
					'confidence'       => 'EXTRACTED',
					'confidence_score' => 1.0,
					'metadata'         => '{}',
				);
			}
		}
	}

	/**
	 * Extract all href values from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return array Array of href strings.
	 */
	protected function extract_hrefs( $html ) {
		$hrefs = array();

		if ( empty( $html ) ) {
			return $hrefs;
		}

		// Use regex rather than DOMDocument to avoid loading overhead for simple link extraction.
		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$hrefs = $matches[1];
		}

		return array_unique( $hrefs );
	}
}
