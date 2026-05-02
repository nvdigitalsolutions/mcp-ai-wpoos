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

		// Build JetEngine CCT nodes.
		if ( ! empty( $detected['ccts'] ) && is_array( $detected['ccts'] ) ) {
			foreach ( $detected['ccts'] as $row ) {
				if ( empty( $row['item']['_ID'] ) || empty( $row['type'] ) ) {
					continue;
				}

				$slug    = sanitize_key( $row['type'] );
				$item    = $row['item'];
				$item_id = absint( $item['_ID'] );
				$node_id = NV_oOS_Graphify_Detector::cct_node_id( $slug, $item_id );

				$type_name = isset( $row['name'] ) ? (string) $row['name'] : '';
				$label     = self::resolve_cct_label( $slug, $item, $type_name );

				$properties = array(
					'cct_slug' => $slug,
					'cct_name' => '' !== $type_name ? $type_name : $slug,
				);
				foreach ( array( 'cct_status', 'cct_created', 'cct_modified' ) as $meta_key ) {
					if ( isset( $item[ $meta_key ] ) ) {
						$properties[ $meta_key ] = is_scalar( $item[ $meta_key ] )
							? (string) $item[ $meta_key ]
							: '';
					}
				}

				$content_source = self::resolve_cct_content( $item );

				$nodes[] = array(
					'node_id'      => $node_id,
					'label'        => $label,
					'type'         => 'cct_' . $slug,
					'post_id'      => 0,
					'url'          => '',
					'properties'   => $properties,
					'content_hash' => hash( 'sha256', $label . '|' . $content_source ),
				);

				// AUTHORED_BY edge when the CCT carries an author column.
				if ( ! empty( $item['cct_author_id'] ) ) {
					$author_node_id = NV_oOS_Graphify_Detector::user_node_id( $item['cct_author_id'] );
					$edges[]        = array(
						'source_node_id' => $node_id,
						'target_node_id' => $author_node_id,
						'relation'       => 'AUTHORED_BY',
						'confidence'     => 1.0,
						'provenance'     => 'EXTRACTED',
					);
				}
			}
		}

		return compact( 'nodes', 'edges' );
	}

	// -------------------------------------------------------------------------
	// CCT field resolvers (shared with the semantic extractor)
	// -------------------------------------------------------------------------

	/**
	 * Resolve a human-readable label for a JetEngine CCT item.
	 *
	 * Scans the most common title-like columns in order and falls back to
	 * `"{Type Name} #{ID}"` when nothing matches. The candidate field list
	 * is filterable via {@see 'nvoos_graphify_cct_label_fields'} so sites
	 * with bespoke CCT schemas can point at their own primary-name column.
	 *
	 * @since 0.7.1
	 *
	 * @param string $slug      CCT slug (sanitised).
	 * @param array  $item      CCT item row (associative array).
	 * @param string $type_name Optional human-readable type name used in the fallback label.
	 * @return string
	 */
	public static function resolve_cct_label( $slug, array $item, $type_name = '' ) {
		$slug = sanitize_key( $slug );

		$label_fields = array( '_title', 'title', 'name', 'cct_name', 'label' );
		/**
		 * Filter the ordered list of CCT item fields checked when
		 * resolving a node label.
		 *
		 * @since 0.7.0
		 *
		 * @param string[] $label_fields Field names checked in order.
		 * @param string   $slug         CCT slug.
		 * @param array    $item         CCT item row (associative array).
		 */
		$label_fields = apply_filters( 'nvoos_graphify_cct_label_fields', $label_fields, $slug, $item );

		$label = '';
		foreach ( (array) $label_fields as $field ) {
			$field = (string) $field;
			if ( '' === $field ) {
				continue;
			}
			if ( ! empty( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				$label = (string) $item[ $field ];
				break;
			}
		}

		if ( '' === $label ) {
			$item_id   = isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;
			$type_name = '' !== $type_name ? $type_name : $slug;
			/* translators: 1: CCT type name, 2: numeric item ID. */
			$label = sprintf( __( '%1$s #%2$d', 'nvoos-graphify' ), $type_name, $item_id );
		}

		return $label;
	}

	/**
	 * Resolve the primary content field for a JetEngine CCT item.
	 *
	 * Returns the first non-empty scalar value from the conventional
	 * content/description/body columns. The candidate list is filterable
	 * via {@see 'nvoos_graphify_cct_content_fields'} so semantic extraction
	 * can target the right column on bespoke schemas.
	 *
	 * @since 0.7.1
	 *
	 * @param array $item CCT item row (associative array).
	 * @return string Content text, or '' when no content-like column is populated.
	 */
	public static function resolve_cct_content( array $item ) {
		$content_fields = array( 'content', 'description', 'body', 'message', 'text' );
		/**
		 * Filter the ordered list of CCT item fields checked when
		 * resolving the body/content text used for hashing and
		 * semantic extraction.
		 *
		 * @since 0.7.1
		 *
		 * @param string[] $content_fields Field names checked in order.
		 * @param array    $item           CCT item row (associative array).
		 */
		$content_fields = apply_filters( 'nvoos_graphify_cct_content_fields', $content_fields, $item );

		foreach ( (array) $content_fields as $field ) {
			$field = (string) $field;
			if ( '' === $field ) {
				continue;
			}
			if ( ! empty( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				return (string) $item[ $field ];
			}
		}

		return '';
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
