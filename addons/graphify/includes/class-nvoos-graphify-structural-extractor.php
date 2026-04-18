<?php
/**
 * NV oOS Graphify — Structural Extractor
 *
 * Produces deterministic, high-confidence (1.0) edges from intrinsic
 * WordPress relationships — no AI required. All edges are tagged
 * provenance=EXTRACTED.
 *
 * Relationships produced:
 *   LINKS_TO            — internal hyperlink (href → post)
 *   CATEGORIZED_BY      — post → category term
 *   TAGGED_WITH         — post → tag / custom taxonomy term
 *   AUTHORED_BY         — post → author user
 *   HAS_FEATURED_IMAGE  — post → attachment
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts structural (deterministic) graph relationships from WordPress content.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Structural_Extractor {

	/**
	 * Run structural extraction for a set of posts.
	 *
	 * Converts detected posts, terms, users, and media into a flat list of
	 * node definitions and edge definitions ready for the Builder.
	 *
	 * @since 0.5.0
	 *
	 * @param array $detected Output of NV_oOS_Graphify_Detector::detect().
	 * @return array {
	 *     @type array $nodes Array of node definition arrays.
	 *     @type array $edges Array of edge definition arrays.
	 * }
	 */
	public static function extract( array $detected ) {
		$nodes = array();
		$edges = array();

		// Build post nodes.
		foreach ( $detected['posts'] as $post ) {
			$node_id = NV_oOS_Graphify_Detector::post_node_id( $post->ID, $post->post_type );
			$nodes[] = array(
				'node_id'    => $node_id,
				'label'      => $post->post_title,
				'type'       => $post->post_type,
				'post_id'    => $post->ID,
				'url'        => get_permalink( $post->ID ),
				'properties' => array(
					'post_status' => $post->post_status,
					'post_date'   => $post->post_date,
					'modified'    => $post->post_modified,
				),
				'content_hash' => hash( 'sha256', $post->post_content . $post->post_title ),
			);

			// --- AUTHORED_BY ---
			if ( $post->post_author ) {
				$author_node_id = NV_oOS_Graphify_Detector::user_node_id( $post->post_author );
				$edges[]        = array(
					'source_node_id' => $node_id,
					'target_node_id' => $author_node_id,
					'relation'       => 'AUTHORED_BY',
					'confidence'     => 1.0,
					'provenance'     => 'EXTRACTED',
				);
			}

			// --- HAS_FEATURED_IMAGE ---
			$thumb_id = (int) get_post_thumbnail_id( $post->ID );
			if ( $thumb_id > 0 ) {
				$media_node_id = NV_oOS_Graphify_Detector::media_node_id( $thumb_id );
				$edges[]       = array(
					'source_node_id' => $node_id,
					'target_node_id' => $media_node_id,
					'relation'       => 'HAS_FEATURED_IMAGE',
					'confidence'     => 1.0,
					'provenance'     => 'EXTRACTED',
				);
			}

			// --- Taxonomy edges ---
			$taxonomies = get_post_taxonomies( $post );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post->ID, $taxonomy );
				if ( ! $terms || is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$term_node_id = NV_oOS_Graphify_Detector::term_node_id( $term->term_id, $taxonomy );
					$relation     = ( 'category' === $taxonomy ) ? 'CATEGORIZED_BY' : 'TAGGED_WITH';
					$edges[]      = array(
						'source_node_id' => $node_id,
						'target_node_id' => $term_node_id,
						'relation'       => $relation,
						'confidence'     => 1.0,
						'provenance'     => 'EXTRACTED',
					);
				}
			}

			// --- LINKS_TO (internal links) ---
			$link_edges = self::extract_internal_links( $post->ID, $post->post_content, $node_id );
			foreach ( $link_edges as $link_edge ) {
				$edges[] = $link_edge;
			}
		}

		// Build term nodes.
		foreach ( $detected['terms'] as $term ) {
			$term_node_id = NV_oOS_Graphify_Detector::term_node_id( $term->term_id, $term->taxonomy );
			$term_link    = get_term_link( $term );
			$nodes[]      = array(
				'node_id'    => $term_node_id,
				'label'      => $term->name,
				'type'       => 'term',
				'post_id'    => 0,
				'url'        => is_wp_error( $term_link ) ? '' : $term_link,
				'properties' => array(
					'taxonomy'    => $term->taxonomy,
					'slug'        => $term->slug,
					'count'       => $term->count,
					'description' => $term->description,
				),
			);
		}

		// Build user nodes.
		foreach ( $detected['users'] as $user ) {
			$user_node_id = NV_oOS_Graphify_Detector::user_node_id( $user->ID );
			$nodes[]      = array(
				'node_id'    => $user_node_id,
				'label'      => $user->display_name,
				'type'       => 'user',
				'post_id'    => 0,
				'url'        => get_author_posts_url( $user->ID ),
				'properties' => array(
					'user_login' => $user->user_login,
				),
			);
		}

		// Build media nodes.
		foreach ( $detected['media'] as $attachment ) {
			$media_node_id = NV_oOS_Graphify_Detector::media_node_id( $attachment->ID );
			$nodes[]       = array(
				'node_id'    => $media_node_id,
				'label'      => $attachment->post_title ? $attachment->post_title : basename( get_attached_file( $attachment->ID ) ),
				'type'       => 'media',
				'post_id'    => $attachment->ID,
				'url'        => wp_get_attachment_url( $attachment->ID ),
				'properties' => array(
					'mime_type' => $attachment->post_mime_type,
				),
			);
		}

		return compact( 'nodes', 'edges' );
	}

	// -------------------------------------------------------------------------
	// Internal-link extraction
	// -------------------------------------------------------------------------

	/**
	 * Parse the post content for internal hrefs and emit LINKS_TO edges.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id      Source post ID (used for url_to_postid).
	 * @param string $content      Raw post content.
	 * @param string $source_node  Source node ID.
	 * @return array Edge definition arrays.
	 */
	private static function extract_internal_links( $post_id, $content, $source_node ) {
		$edges   = array();
		$home    = trailingslashit( home_url() );
		$matches = array();

		// Match all href attributes.
		if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return $edges;
		}

		$seen = array();
		foreach ( $matches[1] as $href ) {
			$href = trim( $href );

			// Skip anchors, mailto, tel, external.
			if ( empty( $href )
				|| '#' === $href[0]
				|| 0 === strpos( $href, 'mailto:' )
				|| 0 === strpos( $href, 'tel:' )
				|| ( 0 !== strpos( $href, $home ) && 0 === strpos( $href, 'http' ) )
			) {
				continue;
			}

			// Resolve relative URLs.
			if ( 0 !== strpos( $href, 'http' ) ) {
				$href = home_url( '/' . ltrim( $href, '/' ) );
			}

			// Deduplicate within this post.
			if ( isset( $seen[ $href ] ) ) {
				continue;
			}
			$seen[ $href ] = true;

			$linked_id = url_to_postid( $href );
			if ( $linked_id && $linked_id !== $post_id ) {
				$target_node = NV_oOS_Graphify_Detector::post_node_id( $linked_id );
				$edges[]     = array(
					'source_node_id' => $source_node,
					'target_node_id' => $target_node,
					'relation'       => 'LINKS_TO',
					'confidence'     => 1.0,
					'provenance'     => 'EXTRACTED',
					'properties'     => array( 'href' => esc_url_raw( $href ) ),
				);
			}
		}

		return $edges;
	}
}
